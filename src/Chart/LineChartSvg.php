<?php

declare(strict_types=1);

namespace DropTime\Chart;

use DropTime\Domain\SwimTime;
use DropTime\Support\Lang;

/**
 * タイム推移の折れ線グラフ。ライブラリを使わず PHP で SVG を組み立てる。
 *
 * 縦軸はタイムで、上ほど速い（タイムを縮める＝上がる）。自己ベストを
 * 更新した回には強調マークを付ける。SVG はページに直接埋め込まれ、
 * 色は CSS カスタムプロパティ（クラブカラー）を継承する。
 *
 * 各泳ぎに同年齢の中央値があれば、薄い破線としてもう 1 本重ねる。
 * 自分のタイムが縮んだかだけでなく、同い年の集団に対して近づいたのか
 * 離れたのかまで 1 枚で読めるようにするため。
 *
 * 描画アニメーションは CSS に任せる（画面に入ったときの再生の合図だけ app.js が出す）。
 * 折れ線には pathLength="100" を宣言してあるので、実際の長さに関係なく
 * stroke-dasharray: 100 の 1 通りの書き方で線を伸ばせる。点は
 * .chart__point の並び順に nth-child で遅延を掛ける。
 */
final class LineChartSvg
{
    private const WIDTH = 640;
    private const HEIGHT = 300;
    private const PAD_LEFT = 74;
    private const PAD_RIGHT = 20;
    private const PAD_TOP = 18;
    private const PAD_BOTTOM = 46;

    /**
     * @param array{label: string, swims: list<array{date: string, time_cs: int, is_pb: bool}>} $shape
     */
    public static function render(array $shape): string
    {
        $swims = $shape['swims'];

        if (count($swims) < 2) {
            return '';
        }

        $peers = self::peerMedians($swims);

        $xs = self::xPositions($swims);
        [$yMin, $yMax, $ticks] = self::yScale($swims, $peers);

        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;

        $toX = static fn (float $u): float => self::PAD_LEFT + $u * $plotW;
        // 上ほど速い: 最小タイム（速い）が上端、最大タイム（遅い）が下端。
        $toY = static fn (int $cs): float => $yMax === $yMin
            ? self::PAD_TOP + $plotH / 2
            : self::PAD_TOP + (1 - ($yMax - $cs) / ($yMax - $yMin)) * $plotH;

        $svg = [];
        $ariaLabel = self::e(Lang::t('prog.chart_aria', $shape['label']));
        $svg[] = sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="xMidYMid meet">',
            self::WIDTH,
            self::HEIGHT,
            $ariaLabel
        );
        $svg[] = '<title>' . $ariaLabel . '</title>';

        // 横のグリッドとタイムの目盛り。
        foreach ($ticks as $tick) {
            $y = $toY($tick);
            $svg[] = sprintf(
                '<line class="chart__grid" x1="%d" y1="%.1f" x2="%d" y2="%.1f"/>',
                self::PAD_LEFT,
                $y,
                self::WIDTH - self::PAD_RIGHT,
                $y
            );
            $svg[] = sprintf(
                '<text class="chart__tick" x="%d" y="%.1f" text-anchor="end" dominant-baseline="middle">%s</text>',
                self::PAD_LEFT - 8,
                $y,
                self::e(SwimTime::format($tick))
            );
        }

        // 日付の目盛り。混み合うときは間引く。
        foreach (self::dateTickIndexes(count($swims)) as $index) {
            $x = $toX($xs[$index]);
            $svg[] = sprintf(
                '<text class="chart__tick" x="%.1f" y="%d" text-anchor="middle">%s</text>',
                $x,
                self::HEIGHT - self::PAD_BOTTOM + 22,
                self::e(self::dateLabel($swims[$index]['date'], $swims))
            );
        }

        // 同年齢の中央値。自分の線より先に描いて背面に置く。
        if ($peers !== null) {
            $peerPoints = [];

            foreach ($peers as $i => $median) {
                $peerPoints[] = sprintf('%.1f,%.1f', $toX($xs[$i]), $toY($median));
            }

            $svg[] = '<polyline class="chart__peer-line" points="' . implode(' ', $peerPoints) . '" pathLength="100"/>';
            $svg[] = sprintf(
                '<text class="chart__peer-label" x="%.1f" y="%.1f">%s</text>',
                $toX($xs[count($xs) - 1]) - 4,
                $toY($peers[count($peers) - 1]) - 8,
                self::e(Lang::t('prog.peer_line'))
            );
        }

        // 折れ線。
        $points = [];

        foreach ($swims as $i => $swim) {
            $points[] = sprintf('%.1f,%.1f', $toX($xs[$i]), $toY($swim['time_cs']));
        }

        $svg[] = '<polyline class="chart__line" points="' . implode(' ', $points) . '" pathLength="100"/>';

        // 点。自己ベスト更新は大きく強調する。
        // 1 点 = 1 つの <g> にしておくと、CSS の nth-child で順に出せる。
        $svg[] = '<g class="chart__points">';

        foreach ($swims as $i => $swim) {
            $x = $toX($xs[$i]);
            $y = $toY($swim['time_cs']);

            $svg[] = '<g class="chart__point">';

            if ($swim['is_pb']) {
                $svg[] = sprintf('<circle class="chart__pb-ring" cx="%.1f" cy="%.1f" r="9"/>', $x, $y);
                $svg[] = sprintf('<circle class="chart__pb" cx="%.1f" cy="%.1f" r="5"/>', $x, $y);
            } else {
                $svg[] = sprintf('<circle class="chart__dot" cx="%.1f" cy="%.1f" r="4"/>', $x, $y);
            }

            $svg[] = '</g>';
        }

        $svg[] = '</g>';

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    /**
     * 横位置（0..1）。日付ベースだが、同日が続く場合も潰れないよう
     * 最小間隔を確保してから全体を正規化する。
     *
     * @param list<array{date: string, time_cs: int, is_pb: bool}> $swims
     * @return list<float>
     */
    private static function xPositions(array $swims): array
    {
        $times = array_map(static fn (array $s): int => (int) strtotime($s['date'] . ' 00:00:00 UTC'), $swims);

        $min = min($times);
        $max = max($times);
        $range = $max - $min;

        if ($range === 0) {
            // 全部同じ日（予選と決勝だけ等）。等間隔に置く。
            $count = count($swims);

            return array_map(static fn (int $i): float => $count === 1 ? 0.5 : $i / ($count - 1), array_keys($swims));
        }

        $positions = [];
        $minGap = 0.035; // 同日・近接日が重ならない最小間隔
        $previous = -1.0;

        foreach ($times as $time) {
            $u = ($time - $min) / $range;

            if ($u <= $previous + $minGap) {
                $u = $previous + $minGap;
            }

            $positions[] = $u;
            $previous = $u;
        }

        // 間隔の確保で 1.0 を超えたら全体を縮める。
        $last = $positions[count($positions) - 1];

        if ($last > 1.0) {
            $positions = array_map(static fn (float $u): float => $u / $last, $positions);
        }

        return $positions;
    }

    /**
     * 各泳ぎの「同年齢の中央値」。全部そろっているときだけ線にする。
     * 1 回でも欠けていると線が飛ぶので、その系列では描かない。
     *
     * @param list<array<string, mixed>> $swims
     * @return list<int>|null
     */
    private static function peerMedians(array $swims): ?array
    {
        $medians = [];

        foreach ($swims as $swim) {
            $peer = $swim['rank']['peer'] ?? null;

            if (!is_array($peer) || !isset($peer['median_cs'])) {
                return null;
            }

            $medians[] = (int) $peer['median_cs'];
        }

        return $medians;
    }

    /**
     * 縦軸の範囲と目盛り。範囲に少し余白を持たせ、切りのよい間隔で刻む。
     *
     * @param list<array{date: string, time_cs: int, is_pb: bool}> $swims
     * @param list<int>|null $peers 重ねる同年齢の線。あれば範囲に含める。
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private static function yScale(array $swims, ?array $peers = null): array
    {
        $values = array_map(static fn (array $s): int => $s['time_cs'], $swims);

        if ($peers !== null) {
            $values = array_merge($values, $peers);
        }

        $min = min($values);
        $max = max($values);

        $padding = max(50, (int) round(($max - $min) * 0.15));
        $min -= $padding;
        $max += $padding;

        $step = self::niceStep($max - $min);
        $ticks = [];

        for ($tick = (int) (ceil($min / $step) * $step); $tick <= $max; $tick += $step) {
            $ticks[] = $tick;
        }

        return [$min, $max, $ticks];
    }

    /** 目盛り間隔。0.5 秒 → 1 秒 → 2 秒 → 5 秒 … と切りのよい値を選ぶ。 */
    private static function niceStep(int $rangeCs): int
    {
        foreach ([50, 100, 200, 500, 1000, 2000, 3000, 6000, 12000, 30000, 60000] as $step) {
            if ($rangeCs / $step <= 5) {
                return $step;
            }
        }

        return 120000;
    }

    /** @return list<int> */
    private static function dateTickIndexes(int $count): array
    {
        if ($count <= 6) {
            return range(0, $count - 1);
        }

        // 最初・最後を含めて 5 点ほどに間引く。
        $indexes = [];

        for ($i = 0; $i < 5; $i++) {
            $indexes[] = (int) round($i * ($count - 1) / 4);
        }

        return array_values(array_unique($indexes));
    }

    /** @param list<array{date: string, time_cs: int, is_pb: bool}> $swims */
    private static function dateLabel(string $date, array $swims): string
    {
        $first = substr($swims[0]['date'], 0, 4);
        $last = substr($swims[count($swims) - 1]['date'], 0, 4);

        // 年をまたぐ系列なら年も出す。
        $format = $first === $last ? 'n/j' : 'y/n/j';

        $timestamp = strtotime($date . ' 00:00:00 UTC');

        return $timestamp === false ? $date : gmdate($format, $timestamp);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
