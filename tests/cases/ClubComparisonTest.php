<?php

declare(strict_types=1);

use DropTime\Domain\BirthEstimate;
use DropTime\Domain\ClubComparison;
use DropTime\Tests\Assert;

/** @return array<string, mixed> */
function clubRow(int $swimmerId, string $name, string $date, int $age, int $timeCs, int $distance = 50, string $stroke = 'FR', ?int $birthYear = null): array
{
    return [
        'swimmer_id' => $swimmerId, 'full_name' => $name, 'birth_year' => $birthYear, 'gender' => 'F',
        'final_time_cs' => $timeCs, 'status' => 'OK', 'age' => $age,
        'event_id' => $swimmerId * 100 + $distance, 'round' => 'timed', 'distance' => $distance, 'stroke' => $stroke,
        'course' => 'LCM', 'is_relay' => 0, 'sort_order' => 1,
        'meet_id' => 1, 'meet_name' => 'Meet', 'start_date' => $date,
    ];
}

return [
    '誕生日をまたぐ 2 大会で生年が 1 つに決まる' => static function (): void {
        // 2026-02-20 に 8 歳、2026-06-05 に 9 歳 → 誕生日は 2017-02-21〜2017-06-05。
        $years = BirthEstimate::birthYears(null, [
            ['date' => '2026-02-20', 'age' => 8],
            ['date' => '2026-06-05', 'age' => 9],
        ]);
        Assert::same([2017, 2017], $years);
    },
    '1 大会だけなら生年は 2 年にまたがる' => static function (): void {
        Assert::same([2016, 2017], BirthEstimate::birthYears(null, [['date' => '2026-06-05', 'age' => 9]]));
    },
    '生年が分かっていればそれを使う' => static function (): void {
        Assert::same([2017, 2017], BirthEstimate::birthYears(2017, [['date' => '2026-06-05', 'age' => 3]]));
    },
    '食い違う記録は最新の大会を採る' => static function (): void {
        $range = BirthEstimate::range([
            ['date' => '2025-07-18', 'age' => 12],
            ['date' => '2026-06-05', 'age' => 9],
        ]);
        Assert::same('2016-06-06', $range['earliest']);
        Assert::same('2017-06-05', $range['latest']);
    },
    '年齢の記録が無ければ推定できない' => static function (): void {
        Assert::same(null, BirthEstimate::birthYears(null, [['date' => '2026-06-05', 'age' => null]]));
    },
    'クラブ内で年の近い仲間だけを自己ベストで並べる' => static function (): void {
        $rows = [
            clubRow(1, 'Me', '2026-06-05', 9, 3874, 50, 'FR', 2017),
            clubRow(1, 'Me', '2026-02-20', 8, 4100, 50, 'FR', 2017),
            clubRow(2, 'Faster', '2026-06-05', 9, 3519),
            clubRow(3, 'Slower', '2026-06-05', 8, 3929),
            clubRow(4, 'Teen', '2026-06-05', 14, 2900),   // 年が離れているので入らない
            clubRow(2, 'Faster', '2026-06-05', 9, 9000, 100, 'BK'), // 自分が泳いでいない種目
        ];

        $result = ClubComparison::build(1, $rows, 1);

        Assert::same([2017, 2017], $result['child_years']);
        Assert::same(3, count($result['peers']), '本人 + 2 人');
        Assert::same(1, count($result['shapes']));

        $shape = $result['shapes'][0];
        Assert::same(2, $shape['child_rank']);
        Assert::same(3, $shape['count']);
        Assert::same(355, $shape['gap_above_cs'], '自己ベスト 38.74 とすぐ上 35.19 の差');
        Assert::same(3874, $shape['rows'][1]['time_cs'], '本人は自己ベストで並ぶ');
        Assert::same(1, count($result['other_shapes']));
    },
    '同タイムは同順位' => static function (): void {
        $rows = [
            clubRow(1, 'Me', '2026-06-05', 9, 4000, 50, 'FR', 2017),
            clubRow(2, 'Tie', '2026-06-05', 9, 4000),
        ];
        $shape = ClubComparison::build(1, $rows, 1)['shapes'][0];
        Assert::same(1, $shape['child_rank']);
        Assert::same(null, $shape['gap_above_cs']);
    },
];
