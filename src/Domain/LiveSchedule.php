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
 *   - この大会がこれまで「見積もりの何倍の時間で進んだか」（ペース）を、終わった時刻から出し、
 *     まだ泳いでいない種目の所要時間に掛ける。レーン数の思い違い・棄権の多さ・進行の速さ遅さを、これで吸収する。
 *   - 見積もりの終了時刻を過ぎても結果が出ない種目は、掲載の遅れぶん（数分）は待ち、
 *     それを超えたら遅れているとみなして後ろへずらす。ただしずらすのは一定の幅まで。
 *     結果が掲載されないだけのときに、見積もりが際限なく遅くなる（出番を逃す向きの誤り）のを防ぐ。
 *   - 同じ会場・同じ種目・同じ距離の実測（MeasuredTimings）がある種目は、計算式にその実測の倍率を掛ける。
 *     実測が無い種目だけ、上の大会全体のペースを掛ける。
 *   - 同じ日の前のセッションが押していると実際の進み具合から分かるときは、
 *     次のセッションはウォームアップの時間を空けてから始まるとみなす。
 *
 * 時刻はすべて現地（America/Toronto）の壁時計の文字列 'Y-m-d H:i:s' で受け渡す。
 * ここでは計算の都合で UTC として秒に直すだけで、タイムゾーンの変換はしない。
 */
final class LiveSchedule
{
    public const DONE = 'done';
    public const RUNNING = 'running';
    public const UPCOMING = 'upcoming';

    /** 終わっても、結果が速報サイトに載るまでこれくらいはかかるとみなす（秒）。 */
    private const POSTING_LAG = 300;

    /** 結果が出ないとき、後ろへずらす幅の上限（秒）。 */
    private const MAX_UNCONFIRMED_DELAY = 1200;

    /** 前のセッションが終わってから次のセッションが始まるまでに、最低限空く時間（ウォームアップ。秒）。 */
    private const MIN_SESSION_GAP = 1800;

    /** ペースを出すのに必要な、終わった種目の見積もりの合計（秒）。セッション 1 つぶんと、大会の合計。 */
    private const PACE_MIN_SESSION = 1200;
    private const PACE_MIN_TOTAL = 1800;

    /** ペースの範囲。印字の時刻の乱れで見積もりが飛ばないようにする。 */
    private const PACE_MIN = 0.7;
    private const PACE_MAX = 1.4;

    /**
     * @param list<array{date: ?string, start_time: ?string,
     *                   events: list<array{key: string, distance: int, heats: int, done: bool, finished_at: ?string,
     *                                      duration?: int, measured?: int}>}> $sessions
     *        events はセッション内の並び順。finished_at は終わった時刻（分かれば）。
     *        duration は所要時間の見積もり（秒）。無ければ距離と組数から出す。
     *        measured は、duration が同じ会場・同じ種目の実測にもとづくとき、その実測の回数（無ければ 0）
     * @param string $now 現地の今 'Y-m-d H:i:s'
     * @return array<string, array{start: string, status: string, events_before: int, session_done: int, corrected: bool, measured: int}>
     *         種目のキー → 見積もりの開始時刻・状態・その種目の前に残っている種目の数・
     *         そのセッションで終わった種目の数・実際の進み具合で補正したか・
     *         その種目の所要時間のもとにした実測の回数（0 なら計算式だけ）
     */
    public static function compute(array $sessions, string $now): array
    {
        $nowTs = self::ts($now);
        $result = [];
        $prepared = [];

        foreach ($sessions as $session) {
            if ($session['date'] === null || $session['start_time'] === null || $session['events'] === []) {
                continue;
            }

            $prepared[] = [
                'date' => $session['date'],
                'scheduled' => self::ts($session['date'] . ' ' . $session['start_time'] . ':00'),
                'events' => $session['events'],
                'durations' => array_map(
                    static fn (array $event): int => $event['duration'] ?? ScheduleEstimator::durationSeconds($event['distance'], $event['heats']),
                    $session['events']
                ),
            ];
        }

        // 前のセッションの終わりを次のセッションに伝えるので、始まる順に見る。
        usort($prepared, static fn (array $a, array $b): int => $a['scheduled'] <=> $b['scheduled']);

        $pace = self::pace($prepared, $nowTs);
        $ended = [];

        foreach ($prepared as $session) {
            $events = $session['events'];
            $lastDone = -1;

            foreach ($events as $index => $event) {
                if ($event['done'] || $event['finished_at'] !== null) {
                    $lastDone = $index;
                }
            }

            $cursor = $session['scheduled'];
            $corrected = $pace !== 1.0;

            // 前のセッションの押し。進み具合が実際に分かっているセッションだけを根拠にする
            //（見積もりだけで次のセッションまで遅らせると、見積もりの誤りが重なるため）。
            foreach ($ended as $earlier) {
                if ($earlier['confirmed'] && $earlier['date'] === $session['date'] && $earlier['scheduled'] < $session['scheduled']
                    && $earlier['end'] + self::MIN_SESSION_GAP > $cursor) {
                    $cursor = $earlier['end'] + self::MIN_SESSION_GAP;
                    $corrected = true;
                }
            }

            foreach ($events as $index => $event) {
                $start = $cursor;
                // 同じ会場・同じ種目の実測から出した所要時間には、大会全体のペースを重ねて掛けない。
                $duration = ($event['measured'] ?? 0) > 0
                    ? $session['durations'][$index]
                    : (int) round($session['durations'][$index] * $pace);

                if ($index <= $lastDone) {
                    if ($event['finished_at'] !== null) {
                        $cursor = self::ts($event['finished_at']);
                        $corrected = true;
                    } else {
                        // 終わっているのは確かなので、今より後にはしない。
                        $cursor = min($start + $duration, $nowTs);
                    }

                    $status = self::DONE;
                } elseif ($nowTs >= $start) {
                    $cursor = $start + $duration;
                    $overdue = $nowTs - $cursor - self::POSTING_LAG;

                    if ($index === $lastDone + 1 && $overdue > 0) {
                        $cursor += min($overdue, self::MAX_UNCONFIRMED_DELAY);
                        $corrected = true;
                    }

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
                    'measured' => $event['measured'] ?? 0,
                ];
            }

            $ended[] = ['date' => $session['date'], 'scheduled' => $session['scheduled'], 'end' => $cursor, 'confirmed' => $lastDone >= 0];
        }

        return $result;
    }

    /**
     * この大会が、見積もりの何倍の時間で進んでいるか。
     *
     * セッションごとに「開始から、終わった時刻が分かっている最後の種目まで」の実際の時間と見積もりを比べ、合計の比を取る。
     * 種目ごとではなくセッションの通算で比べるのは、結果がまとめて掲載されると 1 種目ずつの時刻は当てにならないため。
     * 材料が少ないうちは 1.0（補正なし）。
     *
     * @param list<array{scheduled: int, events: list<array<string, mixed>>, durations: list<int>}> $prepared
     */
    private static function pace(array $prepared, int $nowTs): float
    {
        $actual = 0;
        $model = 0;

        foreach ($prepared as $session) {
            $elapsed = 0;
            $sum = 0;
            $upTo = 0;

            foreach ($session['events'] as $index => $event) {
                $sum += $session['durations'][$index];

                if ($event['finished_at'] !== null && self::ts($event['finished_at']) <= $nowTs) {
                    $elapsed = self::ts($event['finished_at']) - $session['scheduled'];
                    $upTo = $sum;
                }
            }

            if ($elapsed > 0 && $upTo >= self::PACE_MIN_SESSION) {
                $actual += $elapsed;
                $model += $upTo;
            }
        }

        if ($model < self::PACE_MIN_TOTAL) {
            return 1.0;
        }

        return min(self::PACE_MAX, max(self::PACE_MIN, $actual / $model));
    }

    /**
     * DB の形（MeetRepository::sessionsOf / liveStatusOf）から見積もる。
     *
     * 所要時間はエントリータイムから見積もる（ScheduleEstimator::durationFromTimes）。
     * 組はヒートシートの組があればそれを、無ければ（Psych Sheet）レーン数で区切る。
     * 結果が入っている種目は終わっているとみなす。
     *
     * @param list<array{date: ?string, start_time: ?string, events: list<array{event_number: string, round: string,
     *                    distance: int, heat_count: int, entry_count: int, result_count: int,
     *                    times?: list<array{heat: ?int, cs: ?int}>}>}> $sessions
     * @param list<array{event_number: string, round: string, finished_at: string}> $live
     * @param array<string, array{factor: ?float, samples: int}> $factors 同じ会場の実測から出した、種目・距離ごとの倍率
     *        （MeasuredTimings::factors）。合う実測がある種目は、計算式にその倍率を掛ける
     * @return array<string, array{start: string, status: string, events_before: int, session_done: int, corrected: bool, measured: int}>
     */
    public static function forMeet(array $sessions, array $live, string $now, int $lanes = 8, array $factors = []): array
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
                $factor = isset($event['stroke'])
                    ? ($factors[MeasuredTimings::eventKey($event['stroke'], $event['distance'], $event['is_relay'] ?? false)] ?? null)
                    : null;

                $events[] = [
                    'key' => $key,
                    'distance' => $event['distance'],
                    'heats' => max($event['heat_count'], (int) ceil($swimmers / $lanes)),
                    'duration' => (int) round(self::modelSeconds($event, $lanes) * ($factor['factor'] ?? 1.0)),
                    'measured' => ($factor['factor'] ?? null) === null ? 0 : $factor['samples'],
                    'done' => $event['result_count'] > 0,
                    'finished_at' => $finished[$key] ?? null,
                ];
            }

            $input[] = ['date' => $session['date'], 'start_time' => $session['start_time'], 'events' => $events];
        }

        return self::compute($input, $now);
    }

    /**
     * 計算式での、種目 1 件の所要時間（秒）。実測と比べるときも同じ式を使う。
     *
     * @param array{distance: int, heat_count: int, entry_count: int, result_count: int, times?: list<array{heat: ?int, cs: ?int}>} $event
     */
    public static function modelSeconds(array $event, int $lanes = 8): int
    {
        $lanes = max(1, $lanes);

        if (($event['times'] ?? []) !== []) {
            return ScheduleEstimator::durationFromTimes($event['distance'], $event['times'], $lanes);
        }

        $swimmers = max($event['entry_count'], $event['result_count']);

        return ScheduleEstimator::durationSeconds($event['distance'], max($event['heat_count'], (int) ceil($swimmers / $lanes)));
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
