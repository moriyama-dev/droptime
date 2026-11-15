<?php

declare(strict_types=1);

use DropTime\Domain\LiveSchedule;
use DropTime\Tests\Assert;

/**
 * 50m・1 組の種目は、入れ替え 50 + 泳ぎ 43 = 93 秒（1 分 33 秒）と見積もる（ScheduleEstimator）。
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
        Assert::same('2026-11-14 13:46:33', $plan['B']['start']);
        Assert::same('2026-11-14 13:49:39', $plan['D']['start']);
        Assert::same(LiveSchedule::UPCOMING, $plan['A']['status']);
        Assert::same(3, $plan['D']['events_before']);
        Assert::false($plan['D']['corrected']);
    },
    '終わった時刻が遅れていれば、あとの種目を後ろへずらす' => static function (): void {
        $plan = LiveSchedule::compute(liveSession(['A' => ['finished_at' => '2026-11-14 13:55:00']]), '2026-11-14 13:56:00');

        Assert::same(LiveSchedule::DONE, $plan['A']['status']);
        Assert::same(LiveSchedule::RUNNING, $plan['B']['status'], '開始の見積もりを過ぎたので泳いでいる最中');
        Assert::same('2026-11-14 13:55:00', $plan['B']['start']);
        Assert::same('2026-11-14 13:56:33', $plan['C']['start']);
        Assert::same(1, $plan['C']['events_before']);
        Assert::true($plan['C']['corrected']);
    },
    '早く終われば、あとの種目を前へずらす' => static function (): void {
        $plan = LiveSchedule::compute(liveSession(['A' => ['finished_at' => '2026-11-14 13:46:00']]), '2026-11-14 13:46:10');

        Assert::same('2026-11-14 13:46:00', $plan['B']['start']);
        Assert::same(LiveSchedule::RUNNING, $plan['B']['status']);
        Assert::same('2026-11-14 13:47:33', $plan['C']['start']);
    },
    'あとの種目が終わっていれば、それより前の種目も終わっている' => static function (): void {
        $plan = LiveSchedule::compute(liveSession(['C' => ['done' => true]]), '2026-11-14 13:50:00');

        Assert::same(LiveSchedule::DONE, $plan['A']['status']);
        Assert::same(LiveSchedule::DONE, $plan['B']['status']);
        Assert::same(LiveSchedule::DONE, $plan['C']['status']);
        Assert::same(0, $plan['D']['events_before']);
    },
    '結果の掲載が少し遅れているだけなら、後ろへずらさない' => static function (): void {
        // A は 13:46:33 に終わる見積もり。2 分過ぎただけ（掲載の遅れのうち）。
        $plan = LiveSchedule::compute(liveSession(), '2026-11-14 13:48:33');

        Assert::same(LiveSchedule::RUNNING, $plan['A']['status']);
        Assert::same('2026-11-14 13:46:33', $plan['B']['start']);
        Assert::same(LiveSchedule::RUNNING, $plan['B']['status'], '見積もりの開始を過ぎた種目は、泳いでいるかもしれない');
        Assert::same(LiveSchedule::UPCOMING, $plan['D']['status']);
    },
    '結果が出ないまま時間が過ぎたら、遅れているとみなして後ろへずらす' => static function (): void {
        // 掲載の遅れ 5 分を超えた 5 分ぶんだけずらす。
        $plan = LiveSchedule::compute(liveSession(), '2026-11-14 13:56:33');

        Assert::same(LiveSchedule::RUNNING, $plan['A']['status']);
        Assert::same('2026-11-14 13:51:33', $plan['B']['start']);
        Assert::true($plan['B']['corrected']);
    },
    '結果が掲載されないだけかもしれないので、ずらすのは 20 分まで' => static function (): void {
        $plan = LiveSchedule::compute(liveSession(), '2026-11-14 16:00:00');

        Assert::same('2026-11-14 14:06:33', $plan['B']['start'], '際限なく遅くしない（出番を逃す向きの誤りを避ける）');
        Assert::same('2026-11-14 14:08:06', $plan['C']['start']);
    },
    'この大会の実際の進み具合（ペース）を、これからの種目に掛ける' => static function (): void {
        $session = static fn (string $date, array $finished): array => [
            'date' => $date,
            'start_time' => '09:00',
            'events' => array_map(static fn (string $key): array => [
                'key' => $date . $key,
                'distance' => 100,
                'heats' => 1,
                'duration' => 1200,
                'done' => false,
                'finished_at' => $finished[$key] ?? null,
            ], ['A', 'B', 'C']),
        ];

        // 1 日目: 20 分 × 2 種目の見積もりが、実際は 32 分で終わった（0.8 倍）。
        $sessions = [
            $session('2026-11-13', ['B' => '2026-11-13 09:32:00']),
            $session('2026-11-14', []),
        ];
        $plan = LiveSchedule::compute($sessions, '2026-11-14 08:00:00');

        Assert::same('2026-11-14 09:00:00', $plan['2026-11-14A']['start']);
        Assert::same('2026-11-14 09:16:00', $plan['2026-11-14B']['start'], '20 分 × 0.8 = 16 分');
        Assert::same('2026-11-14 09:32:00', $plan['2026-11-14C']['start']);
        Assert::true($plan['2026-11-14B']['corrected']);

        // 材料が少ない（見積もり 20 分ぶんしか終わっていない）うちは補正しない。
        $few = [$session('2026-11-13', ['A' => '2026-11-13 09:10:00']), $session('2026-11-14', [])];
        Assert::same('2026-11-14 09:20:00', LiveSchedule::compute($few, '2026-11-14 08:00:00')['2026-11-14B']['start']);

        // 印字の時刻が乱れていても、ペースは 0.7〜1.4 倍の範囲に収める。
        $wild = [$session('2026-11-13', ['B' => '2026-11-13 15:00:00']), $session('2026-11-14', [])];
        Assert::same('2026-11-14 09:28:00', LiveSchedule::compute($wild, '2026-11-14 08:00:00')['2026-11-14B']['start']);
    },
    '前のセッションが押していると分かれば、次のセッションはウォームアップを空けて始まる' => static function (): void {
        $morning = liveSession(['C' => ['finished_at' => '2026-11-14 13:40:00']], '09:00');
        $afternoon = liveSession();
        $afternoon[0]['events'] = array_map(static fn (array $e): array => ['key' => 'PM-' . $e['key']] + $e, $afternoon[0]['events']);
        $plan = LiveSchedule::compute([...$afternoon, ...$morning], '2026-11-14 13:41:00');

        // 午前の残り D は 13:40 から 93 秒 → 13:41:33 に終わる。30 分空けて 14:11:33。
        Assert::same('2026-11-14 14:11:33', $plan['PM-A']['start']);
        Assert::true($plan['PM-A']['corrected']);

        // 進み具合が分からない（結果が 1 つも出ていない）セッションは、次のセッションを遅らせる根拠にしない。
        $unknown = LiveSchedule::compute([...$afternoon, ...liveSession([], '13:30')], '2026-11-14 12:00:00');
        Assert::same('2026-11-14 13:45:00', $unknown['PM-A']['start']);
    },
    '並行するセッションは別々に積み上げる' => static function (): void {
        $sessions = [...liveSession(['A' => ['finished_at' => '2026-11-14 14:00:00']]), ...liveSession()];
        $sessions[1]['events'] = array_map(static fn (array $e): array => ['key' => 'B-' . $e['key']] + $e, $sessions[1]['events']);
        $plan = LiveSchedule::compute($sessions, '2026-11-14 13:40:00');

        Assert::same('2026-11-14 14:00:00', $plan['B']['start']);
        Assert::same('2026-11-14 13:46:33', $plan['B-B']['start'], 'もう一方のセッションは遅れの影響を受けない');
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
