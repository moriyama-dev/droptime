<?php

declare(strict_types=1);

namespace DropTime\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 大会当日のカウントダウンのための、種目ごとの開始時刻の見積もり。
 *
 * セッション（"Session 2A - 1:45 PM"）ごとに、開始時刻から種目の所要時間を積み上げる。
 * 予選と決勝のプールが並行する大会（1A・1B）があるので、積み上げはセッションの中だけで行う。
 *
 * 実際の進み具合での補正:
 *   - 終わった種目（速報サイトに結果が出た種目）の、結果のページに印字された時刻を「その種目が終わった時刻」とし、
 *     そこから先の見積もりを積み上げ直す（遅れていれば後ろへ、早ければ前へずれる）。
 *   - あとの種目が終わっていれば、それより前の種目も終わっている（結果の掲載が抜けても並びで分かる）。
 *   - 見積もりの開始時刻を過ぎても終わっていない種目は「泳いでいる最中」とし、
 *     少なくとも今から 1 分は続くとみなして、あとの種目を後ろへずらす。
 *
 * 時刻はすべて現地（America/Toronto）の壁時計の文字列 'Y-m-d H:i:s' で受け渡す。
 * ここでは計算の都合で UTC として秒に直すだけで、タイムゾーンの変換はしない。
 */
final class LiveSchedule
{
    public const DONE = 'done';
    public const RUNNING = 'running';
    public const UPCOMING = 'upcoming';

    /** 泳いでいる最中の種目は、少なくともあとこれだけ続くとみなす（秒）。 */
    private const RUNNING_MIN_REMAINING = 60;

    /**
     * @param list<array{date: ?string, start_time: ?string,
     *                   events: list<array{key: string, distance: int, heats: int, done: bool, finished_at: ?string}>}> $sessions
     *        events はセッション内の並び順。finished_at は終わった時刻（分かれば）
     * @param string $now 現地の今 'Y-m-d H:i:s'
     * @return array<string, array{start: string, status: string, events_before: int, session_done: int, corrected: bool}>
     *         種目のキー → 見積もりの開始時刻・状態・その種目の前に残っている種目の数・
     *         そのセッションで終わった種目の数・実際の進み具合で補正したか
     */
    public static function compute(array $sessions, string $now): array
    {
        $nowTs = self::ts($now);
        $result = [];

        foreach ($sessions as $session) {
            if ($session['date'] === null || $session['start_time'] === null || $session['events'] === []) {
                continue;
            }

            $events = $session['events'];
            $lastDone = -1;

            foreach ($events as $index => $event) {
                if ($event['done'] || $event['finished_at'] !== null) {
                    $lastDone = $index;
                }
            }

            $cursor = self::ts($session['date'] . ' ' . $session['start_time'] . ':00');
            $corrected = false;

            foreach ($events as $index => $event) {
                $start = $cursor;
                $duration = ScheduleEstimator::durationSeconds($event['distance'], $event['heats']);

                if ($index <= $lastDone) {
                    if ($event['finished_at'] !== null) {
                        $cursor = self::ts($event['finished_at']);
                        $corrected = true;
                    } else {
                        // 終わっているのは確かなので、今より後にはしない。
                        $cursor = min($start + $duration, $nowTs);
                    }

                    $status = self::DONE;
                } elseif ($index === $lastDone + 1 && $nowTs >= $start) {
                    $cursor = max($start + $duration, $nowTs + self::RUNNING_MIN_REMAINING);
                    $corrected = $corrected || $cursor !== $start + $duration;
                    $status = self::RUNNING;
                } else {
                    $cursor = $start + $duration;
                    $status = self::UPCOMING;
                }

                $result[$event['key']] = [
                    'start' => gmdate('Y-m-d H:i:s', $start),
                    'status' => $status,
                    'events_before' => max(0, $index - $lastDone - 1),
                    'session_done' => $lastDone + 1,
                    'corrected' => $corrected,
                ];
            }
        }

        return $result;
    }

    /**
     * DB の形（MeetRepository::sessionsOf / liveStatusOf）から見積もる。
     *
     * 組数はヒートシートの組があればそれを、無ければ（Psych Sheet）人数をレーン数で割って見積もる。
     * 結果が入っている種目は終わっているとみなす。
     *
     * @param list<array{date: ?string, start_time: ?string, events: list<array{event_number: string, round: string,
     *                    distance: int, heat_count: int, entry_count: int, result_count: int}>}> $sessions
     * @param list<array{event_number: string, round: string, finished_at: string}> $live
     * @return array<string, array{start: string, status: string, events_before: int, session_done: int, corrected: bool}>
     */
    public static function forMeet(array $sessions, array $live, string $now, int $lanes = 8): array
    {
        $lanes = max(1, $lanes);
        $finished = [];

        foreach ($live as $row) {
            $finished[self::key($row['event_number'], $row['round'])] = $row['finished_at'];
        }

        $input = [];

        foreach ($sessions as $session) {
            $events = [];

            foreach ($session['events'] as $event) {
                $key = self::key($event['event_number'], $event['round']);
                $swimmers = max($event['entry_count'], $event['result_count']);

                $events[] = [
                    'key' => $key,
                    'distance' => $event['distance'],
                    'heats' => max($event['heat_count'], (int) ceil($swimmers / $lanes)),
                    'done' => $event['result_count'] > 0,
                    'finished_at' => $finished[$key] ?? null,
                ];
            }

            $input[] = ['date' => $session['date'], 'start_time' => $session['start_time'], 'events' => $events];
        }

        return self::compute($input, $now);
    }

    /** 種目のキー（DB の行と速報の記録をつなぐ）。 */
    public static function key(string $eventNumber, string $round): string
    {
        return $eventNumber . '|' . $round;
    }

    /** 開始までの分（過ぎていれば 0）。 */
    public static function minutesUntil(string $start, string $now): int
    {
        return max(0, intdiv(self::ts($start) - self::ts($now) + 59, 60));
    }

    private static function ts(string $wallClock): int
    {
        return (new DateTimeImmutable($wallClock, new DateTimeZone('UTC')))->getTimestamp();
    }
}
