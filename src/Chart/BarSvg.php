<?php

declare(strict_types=1);

namespace DropTime\Chart;

/**
 * 横 1 本の棒。ライブラリを使わず PHP で SVG を組む。
 *
 * CSP がインラインの style 属性を許さないため、長さを HTML の width で
 * 渡せない。そこで棒 1 本を小さな SVG にし、長さは rect の属性で持たせる。
 * 文字は SVG に入れず HTML 側に置く（preserveAspectRatio="none" で
 * 横に伸ばすので、SVG 内の文字は歪むため）。
 *
 * 伸びる動きは CSS に任せる（.bar__fill を scaleX(0) → 1）。
 *
 * $gain を渡すと、$fill から $gain までを別の色で描く。「前回からここまで
 * 縮めた」の区間で、比較を切ったときに CSS で隠せるよう要素を分けてある。
 */
final class BarSvg
{
    private const WIDTH = 300;
    private const HEIGHT = 16;

    /**
     * @param float      $fill    棒の長さ（0〜1）
     * @param string     $variant CSS の修飾子（now / prev / drop / gold / silver / bronze / plain）
     * @param float|null $gain    縮めた区間の右端（0〜1、$fill より大きいとき描く）
     */
    public static function render(float $fill, string $variant = 'plain', ?float $gain = null): string
    {
        $fill = self::clamp($fill);
        $width = max(2.0, $fill * self::WIDTH);

        $svg = sprintf(
            '<svg class="bar bar--%s" viewBox="0 0 %d %d" preserveAspectRatio="none" aria-hidden="true" focusable="false">',
            self::e($variant),
            self::WIDTH,
            self::HEIGHT
        );
        $svg .= sprintf('<rect class="bar__track" x="0" y="0" width="%d" height="%d" rx="4"/>', self::WIDTH, self::HEIGHT);

        if ($gain !== null && self::clamp($gain) > $fill) {
            $svg .= sprintf(
                '<rect class="bar__gain" x="%.1f" y="0" width="%.1f" height="%d" rx="4"/>',
                $width,
                self::clamp($gain) * self::WIDTH - $width,
                self::HEIGHT
            );
        }

        $svg .= sprintf('<rect class="bar__fill" x="0" y="0" width="%.1f" height="%d" rx="4"/>', $width, self::HEIGHT);

        return $svg . '</svg>';
    }

    private static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
