<?php

declare(strict_types=1);

namespace DropTime\Domain;

/**
 * タイムはセンチ秒の整数だけで扱う。浮動小数点は一切使わない。
 * "1:08.45" ⇄ 6845
 */
final class SwimTime
{
    /**
     * 表記をセンチ秒に変換する。解釈できなければ null。
     * NT / DQ などの非数値はここでは扱わず、呼び出し元で判定する。
     */
    public static function parse(string $raw): ?int
    {
        $value = trim($raw);

        // 末尾の判定記号（'Y' 公認、'r' リレー先頭など）を落とす。
        $value = rtrim($value, 'YyLlSsRr* ');
        $value = str_replace(' ', '', $value);

        if ($value === '') {
            return null;
        }

        // m:ss.cc
        if (preg_match('/^(\d{1,3}):([0-5]\d)\.(\d{1,2})$/', $value, $m) === 1) {
            $centi = self::centiFromFraction($m[3]);

            return ((int) $m[1] * 60 + (int) $m[2]) * 100 + $centi;
        }

        // h:mm:ss.cc（1500m などで稀に出る）
        if (preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)\.(\d{1,2})$/', $value, $m) === 1) {
            $centi = self::centiFromFraction($m[4]);
            $seconds = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];

            return $seconds * 100 + $centi;
        }

        // ss.cc
        if (preg_match('/^(\d{1,2})\.(\d{1,2})$/', $value, $m) === 1) {
            return (int) $m[1] * 100 + self::centiFromFraction($m[2]);
        }

        return null;
    }

    /** 表示用。60 秒未満なら "28.71"、以上なら "1:08.45"。 */
    public static function format(?int $centiseconds): string
    {
        if ($centiseconds === null) {
            return '--';
        }

        $centi = $centiseconds % 100;
        $totalSeconds = intdiv($centiseconds, 100);
        $seconds = $totalSeconds % 60;
        $minutes = intdiv($totalSeconds, 60);

        if ($minutes === 0) {
            return sprintf('%d.%02d', $seconds, $centi);
        }

        return sprintf('%d:%02d.%02d', $minutes, $seconds, $centi);
    }

    /** 前回比。負なら短縮（ベスト更新方向）。 */
    public static function formatDelta(int $deltaCentiseconds): string
    {
        $sign = $deltaCentiseconds < 0 ? '-' : '+';
        $abs = abs($deltaCentiseconds);

        return $sign . sprintf('%d.%02d', intdiv($abs, 100), $abs % 100);
    }

    /**
     * 2 人のタイムの差。符号を付けず絶対値で返す。
     *
     * formatDelta と分けているのは、前回比（速くなった／遅くなった）と
     * 他人との差（何秒離れている）で、符号の意味が違うため。400m などで
     * 差が 1 分を超えることがあるので、桁上がりは format に任せる。
     */
    public static function formatGap(int $centiseconds): string
    {
        return self::format(abs($centiseconds));
    }

    private static function centiFromFraction(string $fraction): int
    {
        // ".4" は 40 センチ秒。桁数に依存させない。
        return strlen($fraction) === 1 ? (int) $fraction * 10 : (int) $fraction;
    }
}
