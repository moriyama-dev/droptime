<?php

declare(strict_types=1);

use DropTime\Chart\FieldStripSvg;
use DropTime\Domain\FieldRank;
use DropTime\Tests\Assert;

/** @return array<string, mixed> */
function stripGroup(array $times, int $me): array
{
    return ['times' => $times, 'time_cs' => $me, 'rank' => 1, 'count' => count($times), 'label' => '50m 自由形'];
}

return [
    '出場者の数だけ点が並び、本人だけ大きく描かれる' => static function (): void {
        $svg = FieldStripSvg::render(stripGroup([3482, 3874, 3929, 3950], 3874));

        Assert::true(str_starts_with($svg, '<svg'));
        Assert::same(3, substr_count($svg, '<circle class="strip__dot'), '本人以外の 3 人が点になる');
        Assert::same(1, substr_count($svg, 'strip__me-dot'), '本人はひとつだけ');
        Assert::true(str_contains($svg, 'role="img"'));
    },
    'ひとりでは帯を描かない' => static function (): void {
        Assert::same('', FieldStripSvg::render(stripGroup([3874], 3874)));
    },
    'すぐ上の選手が目印になり、差が書き込まれる' => static function (): void {
        $svg = FieldStripSvg::render(stripGroup([3482, 3822, 3874, 3950], 3874));

        Assert::same(1, substr_count($svg, 'strip__dot--ahead'), 'すぐ上はひとりだけ');
        Assert::true(str_contains($svg, 'strip__bridge'), '差の橋が架かる');
        Assert::true(str_contains($svg, '+0.52'), '3822 との差 0.52 秒が出る');
    },
    '先頭なら差は書き込まない' => static function (): void {
        $svg = FieldStripSvg::render(stripGroup([3482, 3874, 3950], 3482));

        Assert::false(str_contains($svg, 'strip__bridge'));
        Assert::false(str_contains($svg, 'strip__dot--ahead'));
    },
    '1 分を超える差は m:ss.cc で書く' => static function (): void {
        $svg = FieldStripSvg::render(stripGroup([36000, 43491], 43491));

        Assert::true(str_contains($svg, '+1:14.91'), '400m のような大きな差でも桁が繰り上がる');
    },
    '細い帯では差の注記と端のラベルを省く' => static function (): void {
        $svg = FieldStripSvg::render(stripGroup([3482, 3822, 3874, 3950], 3874), true);

        Assert::true(str_contains($svg, 'strip--compact'));
        Assert::false(str_contains($svg, 'strip__bridge'), '細い帯に橋は架けない');
        Assert::false(str_contains($svg, 'strip__end'), '速い／遅いのラベルも出さない');
    },
    '全員同着でも壊れない' => static function (): void {
        $svg = FieldStripSvg::render(stripGroup([4000, 4000, 4000], 4000));

        Assert::true(str_contains($svg, 'strip__me-dot'));
        Assert::false(str_contains($svg, 'NAN'));
        Assert::false(str_contains($svg, 'INF'));
    },
    'ラベルの特殊文字がエスケープされる' => static function (): void {
        $svg = FieldStripSvg::render([
            'times' => [3482, 3874],
            'time_cs' => 3874,
            'rank' => 2,
            'count' => 2,
            'label' => 'A<b>&"c"',
        ]);

        Assert::false(str_contains($svg, '<b>'));
        Assert::true(str_contains($svg, '&lt;b&gt;'));
    },
    'FieldRank の結果から同年齢だけを抜いて描ける' => static function (): void {
        $field = [
            ['final_time_cs' => 3482, 'age' => 10, 'status' => 'OK'],
            ['final_time_cs' => 3499, 'age' => 10, 'status' => 'OK'],
            ['final_time_cs' => 3874, 'age' => 9, 'status' => 'OK'],
            ['final_time_cs' => 3929, 'age' => 9, 'status' => 'OK'],
            ['final_time_cs' => 3950, 'age' => 9, 'status' => 'OK'],
            ['final_time_cs' => null, 'age' => 9, 'status' => 'DQ'],
        ];
        $me = ['final_time_cs' => 3874, 'age' => 9, 'status' => 'OK'];
        $rank = FieldRank::of($me, $field);

        $svg = FieldStripSvg::fromRank($rank, $field, 'peer', '50m 自由形');

        // 9 歳は 3 人。本人以外の 2 人が点になる（失格は母数に入らない）。
        Assert::same(2, substr_count($svg, '<circle class="strip__dot'));
        Assert::true(str_contains($svg, '1 位 / 3 人') || str_contains($svg, '1 of 3'));
    },
    '全体を基準にすると同年齢以外も点になる' => static function (): void {
        $field = [
            ['final_time_cs' => 3482, 'age' => 10, 'status' => 'OK'],
            ['final_time_cs' => 3499, 'age' => 10, 'status' => 'OK'],
            ['final_time_cs' => 3874, 'age' => 9, 'status' => 'OK'],
        ];
        $me = ['final_time_cs' => 3874, 'age' => 9, 'status' => 'OK'];
        $rank = FieldRank::of($me, $field);

        $svg = FieldStripSvg::fromRank($rank, $field, 'overall', '50m 自由形');

        Assert::same(2, substr_count($svg, '<circle class="strip__dot'));
    },
];
