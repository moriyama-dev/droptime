<?php

declare(strict_types=1);

use DropTime\Domain\ScheduleEstimator;
use DropTime\Tests\Assert;

return [
    '記載時刻があればそれを使う' => static function (): void {
        $result = ScheduleEstimator::estimate([
            ['id' => 1, 'distance' => 50, 'heats' => 2, 'scheduled_time' => '9:00'],
        ]);

        Assert::same('9:00', $result[1]['time']);
        Assert::false($result[1]['estimated']);
    },
    '記載が無ければ積算で推定する' => static function (): void {
        $result = ScheduleEstimator::estimate([
            ['id' => 1, 'distance' => 50, 'heats' => 2, 'scheduled_time' => '9:00'],
            ['id' => 2, 'distance' => 100, 'heats' => 1, 'scheduled_time' => null],
        ]);

        // 種目 1 の所要 = 2 組 ×（入れ替え 50 + 泳ぎ 43）= 186 秒 → 9:03:06 ≒ 9:03（分に丸め）
        Assert::true($result[2]['estimated']);
        Assert::same('9:03', $result[2]['time']);
    },
    '後の記載時刻で積算の起点が合い直る' => static function (): void {
        $result = ScheduleEstimator::estimate([
            ['id' => 1, 'distance' => 50, 'heats' => 20, 'scheduled_time' => '9:00'],
            ['id' => 2, 'distance' => 50, 'heats' => 2, 'scheduled_time' => '10:30'],
            ['id' => 3, 'distance' => 50, 'heats' => 1, 'scheduled_time' => null],
        ]);

        Assert::false($result[2]['estimated']);
        Assert::same('10:30', $result[2]['time']);
        // 種目 2 の所要 = 2 × 93 = 186 秒 → 10:33:06 ≒ 10:33
        Assert::same('10:33', $result[3]['time']);
    },
    '記載が全く無ければ既定の 9:00 起点' => static function (): void {
        $result = ScheduleEstimator::estimate([
            ['id' => 1, 'distance' => 50, 'heats' => 1, 'scheduled_time' => null],
        ]);

        Assert::same('9:00', $result[1]['time']);
        Assert::true($result[1]['estimated']);
    },
    '組数 0 でも最低 1 組ぶん進む' => static function (): void {
        Assert::true(ScheduleEstimator::durationSeconds(50, 0) > 0);
        Assert::same(
            ScheduleEstimator::durationSeconds(50, 1),
            ScheduleEstimator::durationSeconds(50, 0)
        );
    },
    '組が分からなければ、人数をレーン数で割って組数を見積もる' => static function (): void {
        $result = ScheduleEstimator::estimate([
            ['id' => 1, 'distance' => 50, 'heats' => 0, 'entries' => 20, 'scheduled_time' => '9:00'],
            ['id' => 2, 'distance' => 50, 'heats' => 0, 'scheduled_time' => null],
        ], null, 10);

        // 20 人 ÷ 10 レーン = 2 組 → 186 秒
        Assert::same('9:03', $result[2]['time']);
    },
    'エントリータイムがあれば、組ごとのいちばん遅い選手のタイムで見積もる' => static function (): void {
        $times = array_map(
            static fn (int $cs): array => ['heat' => null, 'cs' => $cs],
            [3000, 3100, 3200, 3300, 4000, 4100, 4200, 6000]
        );

        // 4 レーン: 遅い順に [60.00, 42, 41, 40] と [33, 32, 31, 30] → 60 + 33 + 入れ替え 50 × 2 = 193 秒
        Assert::same(193, ScheduleEstimator::durationFromTimes(50, $times, 4));
        // 8 レーン: 1 組 → 60 + 50 = 110 秒
        Assert::same(110, ScheduleEstimator::durationFromTimes(50, $times, 8));
    },
    'タイムなしの選手は、いちばん遅い組に入るとみなす' => static function (): void {
        $times = [
            ['heat' => null, 'cs' => 3000],
            ['heat' => null, 'cs' => 4000],
            ['heat' => null, 'cs' => null],
        ];

        // 2 レーン: [40（タイムなし）, 40] と [30] → 40 + 30 + 100 = 170 秒
        Assert::same(170, ScheduleEstimator::durationFromTimes(50, $times, 2));
    },
    'ヒートシートの組が分かっていれば、その組分けを使う' => static function (): void {
        $times = [
            ['heat' => 1, 'cs' => 4000],
            ['heat' => 1, 'cs' => 3900],
            ['heat' => 2, 'cs' => 3000],
            ['heat' => 3, 'cs' => 2900],
        ];

        // レーン数によらず 3 組 → 40 + 30 + 29 + 150 = 249 秒
        Assert::same(249, ScheduleEstimator::durationFromTimes(50, $times, 8));
    },
    'ありえないエントリータイムで見積もりが飛ばない' => static function (): void {
        // 50m に 10 分のタイム（入力の誤り）→ 2 秒/m の 100 秒までしか見ない
        Assert::same(150, ScheduleEstimator::durationFromTimes(50, [['heat' => null, 'cs' => 60000]], 8));
    },
    'タイムが 1 つも無ければ距離からの概算' => static function (): void {
        Assert::same(
            ScheduleEstimator::durationSeconds(100, 2),
            ScheduleEstimator::durationFromTimes(100, array_fill(0, 12, ['heat' => null, 'cs' => null]), 8)
        );
        Assert::same(ScheduleEstimator::durationSeconds(100, 1), ScheduleEstimator::durationFromTimes(100, [], 8));
    },
];
