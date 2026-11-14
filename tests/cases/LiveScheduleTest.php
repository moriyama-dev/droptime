<?php

declare(strict_types=1);

use DropTime\Domain\LiveSchedule;
use DropTime\Tests\Assert;

/**
 * 50m・1 組の種目は 50 + 55 + 60 = 165 秒（2 分 45 秒）と見積もる（ScheduleEstimator）。
 *
 * @param array<string, array{done?: bool, finished_at?: ?string}> $state
 * @return list<array<string, mixed>>
 */
function liveSession(array $state = [], string $start = '13:45'): array
{
    $events = [];

    foreach (['A', 'B', 'C', 'D'] as $key) {
        $events[] = [
            'key' => $key,
            'distance' => 50,
            'heats' => 1,
            'done' => $state[$key]['done'] ?? false,
            'finished_at' => $state[$key]['finished_at'] ?? null,
        ];
    }

    return [['date' => '2026-11-14', 'start_time' => $start, 'events' => $events]];
}

return [
    '始まる前は、セッションの開始時刻から所要時間を積み上げる' => static function (): void {
        $plan = LiveSchedule::compute(liveSession(), '2026-11-14 12:00:00');

        Assert::same('2026-11-14 13:45:00', $plan['A']['start']);
        Assert::same('2026-11-14 13:47:45', $plan['B']['start']);
        Assert::same('2026-11-14 13:53:15', $plan['D']['start']);
        Assert::same(LiveSchedule::UPCOMING, $plan['A']['status']);
        Assert::same(3, $plan['D']['events_before']);
        Assert::false($plan['D']['corrected']);
    },
    '終わった時刻が遅れていれば、あとの種目を後ろへずらす' => static function (): void {
        $plan = LiveSchedule::compute(liveSession(['A' => ['finished_at' => '2026-11-14 13:55:00']]), '2026-11-14 13:56:00');

        Assert::same(LiveSchedule::DONE, $plan['A']['status']);
        Assert::same(LiveSchedule::RUNNING, $plan['B']['status'], '開始の見積もりを過ぎたので泳いでいる最中');
        Assert::same('2026-11-14 13:55:00', $plan['B']['start']);
        Assert::same('2026-11-14 13:57:45', $plan['C']['start']);
        Assert::same(1, $plan['C']['events_before']);
        Assert::true($plan['C']['corrected']);
    },
    '早く終われば、あとの種目を前へずらす' => static function (): void {
        $plan = LiveSchedule::compute(liveSession(['A' => ['finished_at' => '2026-11-14 13:46:00']]), '2026-11-14 13:46:10');

        Assert::same('2026-11-14 13:46:00', $plan['B']['start']);
        Assert::same(LiveSchedule::RUNNING, $plan['B']['status']);
        Assert::same('2026-11-14 13:48:45', $plan['C']['start']);
    },
    'あとの種目が終わっていれば、それより前の種目も終わっている' => static function (): void {
        $plan = LiveSchedule::compute(liveSession(['C' => ['done' => true]]), '2026-11-14 13:50:00');

        Assert::same(LiveSchedule::DONE, $plan['A']['status']);
        Assert::same(LiveSchedule::DONE, $plan['B']['status']);
        Assert::same(LiveSchedule::DONE, $plan['C']['status']);
        Assert::same(0, $plan['D']['events_before']);
    },
    '結果が出ないまま時間が過ぎたら、泳いでいる最中として今から後ろへずらす' => static function (): void {
        $plan = LiveSchedule::compute(liveSession(), '2026-11-14 14:30:00');

        Assert::same(LiveSchedule::RUNNING, $plan['A']['status']);
        Assert::same('2026-11-14 14:31:00', $plan['B']['start'], '少なくとも 1 分は続くとみなす');
        Assert::same(LiveSchedule::UPCOMING, $plan['B']['status']);
        Assert::true($plan['B']['corrected']);
    },
    '並行するセッションは別々に積み上げる' => static function (): void {
        $sessions = [...liveSession(['A' => ['finished_at' => '2026-11-14 14:00:00']]), ...liveSession()];
        $sessions[1]['events'] = array_map(static fn (array $e): array => ['key' => 'B-' . $e['key']] + $e, $sessions[1]['events']);
        $plan = LiveSchedule::compute($sessions, '2026-11-14 13:40:00');

        Assert::same('2026-11-14 14:00:00', $plan['B']['start']);
        Assert::same('2026-11-14 13:47:45', $plan['B-B']['start'], 'もう一方のセッションは遅れの影響を受けない');
    },
    '開始時刻の分からないセッションは見積もらない' => static function (): void {
        $plan = LiveSchedule::compute(liveSession([], '13:45') + [], '2026-11-14 12:00:00');
        Assert::true(isset($plan['A']));

        $none = liveSession();
        $none[0]['start_time'] = null;
        Assert::same([], LiveSchedule::compute($none, '2026-11-14 12:00:00'));
    },
    '開始までの分は切り上げ、過ぎていれば 0' => static function (): void {
        Assert::same(25, LiveSchedule::minutesUntil('2026-11-14 14:10:00', '2026-11-14 13:45:30'));
        Assert::same(0, LiveSchedule::minutesUntil('2026-11-14 13:00:00', '2026-11-14 13:45:00'));
    },
];
