<?php

declare(strict_types=1);

namespace DropTime\Chart;

use DropTime\Domain\SwimTime;
use DropTime\Support\Lang;

/**
 * 1 レースの中での位置を表す横 1 本の帯。ライブラリを使わず PHP で SVG を組む。
 *
 * 左が速く右が遅い。出場者ひとりを 1 つの点で置き、本人だけを大きく光らせる。
 * すぐ上の選手との間に差を書き込むのは、順位そのものより「あと何秒で 1 つ上か」
 * のほうが次の目標になるため。
 *
 * 点の重なりは潰さない。人数が多い種目では点が密集し、それ自体が
 * 「この集団のどのあたりに居るのか」を語るため。
 *
 * 色は CSS 側（クラブカラー）に任せ、ここでは形と位置だけを決める。
 */
final class FieldStripSvg
{
    private const WIDTH = 640;
    private const PAD = 26;

    /** 標準の帯。差の注記まで入る。 */
    private const HEIGHT = 96;
    private const BASELINE = 64;

    /** 併記用の細い帯。点と本人の位置だけ。 */
    private const COMPACT_HEIGHT = 40;
    private const COMPACT_BASELINE = 24;

    /**
     * @param array{
     *     times: list<int>, time_cs: int, rank: int, count: int,
     *     gap_ahead_cs?: ?int, gap_behind_cs?: ?int, label: string
     * } $group
     */
    public static function render(array $group, bool $compact = false): string
    {
        $times = $group['times'];

        if (count($times) < 2) {
            // ひとりでは「位置」にならない。
            return '';
        }

        $height = $compact ? self::COMPACT_HEIGHT : self::HEIGHT;
        $baseline = $compact ? self::COMPACT_BASELINE : self::BASELINE;

        $min = min($times);
        $max = max($times);
        $me = $group['time_cs'];

        $toX = static function (int $cs) use ($min, $max): float {
            if ($max === $min) {
                return self::WIDTH / 2;
            }

            $span = self::WIDTH - self::PAD * 2;

            return self::PAD + ($cs - $min) / ($max - $min) * $span;
        };

        $svg = [];
        $aria = self::e(Lang::t('rank.strip_aria', $group['label'], $group['rank'], $group['count']));

        $svg[] = sprintf(
            '<svg class="strip%s" viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="xMidYMid meet">',
            $compact ? ' strip--compact' : '',
            self::WIDTH,
            $height,
            $aria
        );
        $svg[] = '<title>' . $aria . '</title>';

        // 軸。pathLength を宣言しておくと、実際の長さに関係なく CSS 側で
        // 同じ書き方の描画アニメーションが掛けられる。
        $svg[] = sprintf(
            '<line class="strip__axis" x1="%d" y1="%d" x2="%d" y2="%d" pathLength="100"/>',
            self::PAD,
            $baseline,
            self::WIDTH - self::PAD,
            $baseline
        );

        if (!$compact) {
            $svg[] = sprintf(
                '<text class="strip__end" x="%d" y="%d" text-anchor="start">%s</text>',
                self::PAD,
                $baseline + 20,
                self::e(Lang::t('rank.faster'))
            );
            $svg[] = sprintf(
                '<text class="strip__end" x="%d" y="%d" text-anchor="end">%s</text>',
                self::WIDTH - self::PAD,
                $baseline + 20,
                self::e(Lang::t('rank.slower'))
            );
        }

        // すぐ上の選手。差を書き込む相手。
        $faster = array_values(array_filter($times, static fn (int $t): bool => $t < $me));
        $ahead = $faster === [] ? null : max($faster);

        // 他の出場者。アニメーションの間隔を揃えるためひとまとめにする。
        $svg[] = '<g class="strip__dots">';

        foreach ($times as $time) {
            if ($time === $me) {
                continue; // 本人は最後に大きく描く
            }

            $class = $ahead !== null && $time === $ahead ? 'strip__dot strip__dot--ahead' : 'strip__dot';
            $svg[] = sprintf(
                '<circle class="%s" cx="%.1f" cy="%d" r="%s"/>',
                $class,
                $toX($time),
                $baseline,
                $compact ? '2.5' : '4'
            );
        }

        $svg[] = '</g>';

        $meX = $toX($me);

        // すぐ上との差。帯の上に小さな橋を架けて秒数を置く。
        if (!$compact && $ahead !== null) {
            $aheadX = $toX($ahead);
            $bridgeY = $baseline - 26;
            $gap = $me - $ahead;

            $svg[] = sprintf(
                '<path class="strip__bridge" d="M%.1f %d V%d H%.1f V%d"/>',
                $aheadX,
                $baseline - 8,
                $bridgeY,
                $meX,
                $baseline - 10
            );
            $svg[] = sprintf(
                '<text class="strip__gap" x="%.1f" y="%d" text-anchor="middle">%s</text>',
                ($aheadX + $meX) / 2,
                $bridgeY - 6,
                self::e(Lang::t('rank.gap_bridge', SwimTime::formatGap($gap)))
            );
        }

        // 本人。最後に置いて他の点の上に載せる。
        $svg[] = '<g class="strip__me">';
        $svg[] = sprintf(
            '<circle class="strip__me-ring" cx="%.1f" cy="%d" r="%s"/>',
            $meX,
            $baseline,
            $compact ? '6' : '11'
        );
        $svg[] = sprintf(
            '<circle class="strip__me-dot" cx="%.1f" cy="%d" r="%s"/>',
            $meX,
            $baseline,
            $compact ? '3.5' : '6'
        );
        $svg[] = '</g>';

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    /**
     * FieldRank の結果からそのまま帯を描く。
     *
     * @param array<string, mixed>       $rank  FieldRank::of() の戻り値
     * @param list<array<string, mixed>> $field その種目の全結果
     */
    public static function fromRank(array $rank, array $field, string $basis, string $label, bool $compact = false): string
    {
        $standing = $basis === 'peer' ? $rank['peer'] : $rank['overall'];

        if ($standing === null) {
            return '';
        }

        $times = [];

        foreach ($field as $row) {
            if (($row['status'] ?? 'OK') !== 'OK' || ($row['final_time_cs'] ?? null) === null) {
                continue;
            }

            if ($basis === 'peer') {
                $age = $row['age'] ?? null;

                if ($age === null || (int) $age !== (int) $rank['peer']['age']) {
                    continue;
                }
            }

            $times[] = (int) $row['final_time_cs'];
        }

        return self::render([
            'times' => $times,
            'time_cs' => (int) $rank['time_cs'],
            'rank' => (int) $standing['rank'],
            'count' => (int) $standing['count'],
            'label' => $label,
        ], $compact);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
