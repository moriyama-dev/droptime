<?php

declare(strict_types=1);

use DropTime\Chart\LineChartSvg;
use DropTime\Tests\Assert;

/** @return array<string, mixed> */
function chartShape(array $swims): array
{
    return ['label' => '50m 自由形（SCM）', 'swims' => $swims];
}

return [
    '2 点以上で SVG を返す' => static function (): void {
        $svg = LineChartSvg::render(chartShape([
            ['date' => '2025-10-19', 'time_cs' => 4512, 'is_pb' => true],
            ['date' => '2026-02-08', 'time_cs' => 4380, 'is_pb' => true],
        ]));

        Assert::true(str_starts_with($svg, '<svg'), 'SVG で始まる');
        Assert::true(str_contains($svg, 'polyline'), '折れ線がある');
        Assert::same(2, substr_count($svg, 'chart__pb-ring'), 'PB マークが 2 つ');
        Assert::true(str_contains($svg, 'role="img"'));
    },
    '1 点では描かない' => static function (): void {
        Assert::same('', LineChartSvg::render(chartShape([
            ['date' => '2025-10-19', 'time_cs' => 4512, 'is_pb' => true],
        ])));
    },
    '同日の 2 点でも壊れない' => static function (): void {
        $svg = LineChartSvg::render(chartShape([
            ['date' => '2026-02-08', 'time_cs' => 4512, 'is_pb' => true],
            ['date' => '2026-02-08', 'time_cs' => 4380, 'is_pb' => true],
        ]));

        Assert::true(str_contains($svg, 'polyline'));
        Assert::false(str_contains($svg, 'NAN'), 'NaN が混ざらない');
    },
    '同年齢の中央値がそろっていれば線をもう 1 本重ねる' => static function (): void {
        $peer = static fn (int $median): array => ['peer' => ['median_cs' => $median]];

        $svg = LineChartSvg::render(chartShape([
            ['date' => '2025-10-19', 'time_cs' => 4512, 'is_pb' => true, 'rank' => $peer(4400)],
            ['date' => '2026-02-08', 'time_cs' => 4380, 'is_pb' => true, 'rank' => $peer(4350)],
        ]));

        Assert::true(str_contains($svg, 'chart__peer-line'));
        Assert::same(2, substr_count($svg, 'pathLength="100"'), '自分の線と同年齢の線の 2 本');
    },
    '中央値が 1 回でも欠けていれば重ねない' => static function (): void {
        $svg = LineChartSvg::render(chartShape([
            ['date' => '2025-10-19', 'time_cs' => 4512, 'is_pb' => true, 'rank' => ['peer' => ['median_cs' => 4400]]],
            ['date' => '2026-02-08', 'time_cs' => 4380, 'is_pb' => true, 'rank' => ['peer' => null]],
        ]));

        Assert::false(str_contains($svg, 'chart__peer-line'), '線が飛ぶくらいなら描かない');
    },
    '順位が付いていない系列でも従来どおり描ける' => static function (): void {
        $svg = LineChartSvg::render(chartShape([
            ['date' => '2025-10-19', 'time_cs' => 4512, 'is_pb' => true],
            ['date' => '2026-02-08', 'time_cs' => 4380, 'is_pb' => true],
        ]));

        Assert::true(str_contains($svg, 'chart__line'));
        Assert::false(str_contains($svg, 'chart__peer-line'));
    },
    '同年齢の中央値もグラフの範囲に収まる' => static function (): void {
        // 中央値が自分より大きく外側にあるとき、はみ出さずに軸が広がること。
        $svg = LineChartSvg::render(chartShape([
            ['date' => '2025-10-19', 'time_cs' => 4512, 'is_pb' => true, 'rank' => ['peer' => ['median_cs' => 3000]]],
            ['date' => '2026-02-08', 'time_cs' => 4380, 'is_pb' => true, 'rank' => ['peer' => ['median_cs' => 3100]]],
        ]));

        preg_match_all('/class="chart__peer-line" points="([^"]+)"/', $svg, $m);
        Assert::same(1, count($m[1]));

        foreach (explode(' ', $m[1][0]) as $point) {
            $y = (float) explode(',', $point)[1];
            Assert::true($y >= 0.0 && $y <= 300.0, '描画領域の中に収まる: ' . $y);
        }
    },
    'ラベルの特殊文字がエスケープされる' => static function (): void {
        $svg = LineChartSvg::render([
            'label' => 'A<b>&"c"',
            'swims' => [
                ['date' => '2025-10-19', 'time_cs' => 4512, 'is_pb' => true],
                ['date' => '2026-02-08', 'time_cs' => 4380, 'is_pb' => true],
            ],
        ]);

        Assert::false(str_contains($svg, '<b>'));
        Assert::true(str_contains($svg, '&lt;b&gt;'));
    },
];
