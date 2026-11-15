<?php

declare(strict_types=1);

namespace DropTime\Repository;

use DropTime\Db\Database;
use DropTime\Domain\LiveSchedule;
use DropTime\Domain\ScheduleEstimator;

/**
 * お気に入りの選手のエントリーに「いつ泳ぐか」を付ける。
 *
 * ホームの「次に泳ぐレース」「今日の出番」と、通知（bin/push-notify.php）が同じ計算を使う。
 *
 * 推定は大会プログラム全体（お気に入り以外の種目も含む）の積算で行う。
 * 自分の種目だけを足しても時刻にならないため。
 * 速報サイトの目次からセッション（開始時刻と種目の並び）が分かっている大会は、
 * LiveSchedule でセッションごとに見積もり、当日は速報の終わった時刻で補正する。
 */
final class RacePlanRepository
{
    public function __construct(private readonly Database $db, private readonly int $lanes = 8)
    {
    }

    /**
     * エントリーを大会ごとにまとめ、時刻を付ける。泳ぎ終わった種目も残す（呼び出し側で選ぶ）。
     *
     * 各エントリーに足すもの:
     *   schedule  ?array{time: string, estimated: bool}
     *   live      ?array{start, status, events_before, session_done, corrected, date, time, minutes, is_today}
     *   today_at  ?string  今日泳ぐなら現地の開始時刻 'Y-m-d H:i:s'（分からなければ null）
     *
     * @param list<array<string, mixed>> $entries FavoriteRepository::upcomingEntries の形
     * @param string $today 現地の今日 'Y-m-d'
     * @param string $now 現地の今 'Y-m-d H:i:s'
     * @return list<array{meet: array<string, mixed>, entries: list<array<string, mixed>>, is_today: bool, latest: ?array<string, mixed>}>
     */
    public function plan(array $entries, string $today, string $now): array
    {
        $meets = new MeetRepository($this->db);
        $groups = [];

        foreach ($entries as $entry) {
            $meetId = (int) $entry['meet_id'];

            if (!isset($groups[$meetId])) {
                $program = $meets->programOf($meetId);
                $estimates = ScheduleEstimator::estimate(array_map(
                    static fn (array $event): array => [
                        'id' => (int) $event['id'],
                        'distance' => (int) $event['distance'],
                        'heats' => (int) $event['heat_count'],
                        'entries' => (int) $event['entry_count'],
                        'scheduled_time' => is_string($event['scheduled_time']) ? $event['scheduled_time'] : null,
                    ],
                    $program
                ), null, $this->lanes);

                $sessions = $meets->sessionsOf($meetId);
                $live = $sessions === [] ? [] : $meets->liveStatusOf($meetId);
                $end = (string) ($entry['end_date'] ?? $entry['start_date']);

                $groups[$meetId] = [
                    'meet' => [
                        'id' => $meetId,
                        'name' => $entry['meet_name'],
                        'start_date' => $entry['start_date'],
                        'end_date' => $entry['end_date'],
                        'venue' => $entry['venue'],
                    ],
                    'estimates' => $estimates,
                    'plan' => $sessions === [] ? [] : LiveSchedule::forMeet($sessions, $live, $now, $this->lanes),
                    'single_day' => (string) $entry['start_date'] === $end,
                    'is_today' => (string) $entry['start_date'] <= $today && $end >= $today,
                    'latest' => $live === [] ? null : $live[0] + ['time' => substr($live[0]['finished_at'], 11, 5)],
                    'entries' => [],
                ];
            }

            $group = $groups[$meetId];
            $entry['schedule'] = $group['estimates'][(int) $entry['event_id']] ?? null;
            $entry['live'] = null;
            $entry['today_at'] = null;
            $plan = $group['plan'][LiveSchedule::key((string) $entry['event_number'], (string) $entry['round'])] ?? null;

            if ($plan !== null) {
                $entry['live'] = $plan + [
                    'date' => substr($plan['start'], 0, 10),
                    'time' => ltrim(substr($plan['start'], 11, 5), '0'),
                    'minutes' => LiveSchedule::minutesUntil($plan['start'], $now),
                    'is_today' => substr($plan['start'], 0, 10) === $today,
                ];
                $entry['schedule'] = ['time' => $entry['live']['time'], 'estimated' => true];

                if ($entry['live']['is_today']) {
                    $entry['today_at'] = $plan['start'];
                }
            } elseif ($group['plan'] === [] && $group['single_day'] && $group['is_today'] && $entry['schedule'] !== null) {
                // セッションが分からない 1 日だけの大会は、進行表からの見積もりを今日の時刻とみなす。
                $entry['today_at'] = $today . ' ' . str_pad((string) $entry['schedule']['time'], 5, '0', STR_PAD_LEFT) . ':00';
            }

            $groups[$meetId]['entries'][] = $entry;
        }

        return array_values(array_map(static function (array $group): array {
            unset($group['estimates'], $group['plan'], $group['single_day']);

            // セッションの見積もりがある大会は、見積もりの開始時刻の順に並べる（並行するセッションが混ざるため）。
            usort($group['entries'], static fn (array $a, array $b): int => strcmp(
                (string) ($a['live']['start'] ?? ''),
                (string) ($b['live']['start'] ?? '')
            ));

            return $group;
        }, $groups));
    }

    /**
     * 今日の出番の時間帯。選手ごとに、今日泳ぐ種目のうち最初と最後。
     * 「会場に何時から何時までいればよいか」に答えるためのもの。泳ぎ終わった種目も含める。
     *
     * @param list<array{meet: array<string, mixed>, entries: list<array<string, mixed>>}> $groups plan() の戻り値
     * @return list<array{swimmer_id: int, swimmer_name: string, meet: array<string, mixed>,
     *                    first: array<string, mixed>, last: array<string, mixed>, count: int,
     *                    times: list<array{at: string, done: bool}>, done: int, estimated: bool}>
     */
    public static function todayWindows(array $groups): array
    {
        $windows = [];

        foreach ($groups as $group) {
            foreach ($group['entries'] as $entry) {
                if (($entry['today_at'] ?? null) === null) {
                    continue;
                }

                $key = (int) $entry['swimmer_id'] . '|' . (int) $group['meet']['id'];
                $windows[$key] ??= [
                    'swimmer_id' => (int) $entry['swimmer_id'],
                    'swimmer_name' => (string) $entry['swimmer_name'],
                    'meet' => $group['meet'],
                    'entries' => [],
                ];
                $windows[$key]['entries'][] = $entry;
            }
        }

        $result = [];

        foreach ($windows as $window) {
            $entries = $window['entries'];
            usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['today_at'], (string) $b['today_at']));

            $done = count(array_filter($entries, static fn (array $e): bool => ($e['live']['status'] ?? null) === LiveSchedule::DONE));
            $first = $entries[0];
            $last = $entries[count($entries) - 1];

            $result[] = [
                'swimmer_id' => $window['swimmer_id'],
                'swimmer_name' => $window['swimmer_name'],
                'meet' => $window['meet'],
                'first' => $first + ['clock' => self::clock((string) $first['today_at'])],
                'last' => $last + ['clock' => self::clock((string) $last['today_at'])],
                'count' => count($entries),
                'times' => array_map(static fn (array $e): array => [
                    'at' => (string) $e['today_at'],
                    'done' => ($e['live']['status'] ?? null) === LiveSchedule::DONE,
                ], $entries),
                'done' => $done,
                'estimated' => array_filter($entries, static fn (array $e): bool => (bool) ($e['schedule']['estimated'] ?? true)) !== [],
            ];
        }

        usort($result, static fn (array $a, array $b): int => strcmp((string) $a['first']['today_at'], (string) $b['first']['today_at']));

        return $result;
    }

    /** 'Y-m-d H:i:s' → '9:05'。 */
    public static function clock(string $wallClock): string
    {
        return ltrim(substr($wallClock, 11, 5), '0') ?: '0:00';
    }
}
