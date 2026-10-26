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

        // 種目 1 の所要 = 2 組 × (50 + 55) + 60 = 270 秒 → 9:04:30 ≒ 9:05（分に丸め）
        Assert::true($result[2]['estimated']);
        Assert::same('9:05', $result[2]['time']);
    },
    '後の記載時刻で積算の起点が合い直る' => static function (): void {
        $result = ScheduleEstimator::estimate([
            ['id' => 1, 'distance' => 50, 'heats' => 20, 'scheduled_time' => '9:00'],
            ['id' => 2, 'distance' => 50, 'heats' => 2, 'scheduled_time' => '10:30'],
            ['id' => 3, 'distance' => 50, 'heats' => 1, 'scheduled_time' => null],
        ]);

        Assert::false($result[2]['estimated']);
        Assert::same('10:30', $result[2]['time']);
        // 種目 2 の所要 = 2 × 105 + 60 = 270 秒 → 10:34:30 ≒ 10:35
        Assert::same('10:35', $result[3]['time']);
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
];
