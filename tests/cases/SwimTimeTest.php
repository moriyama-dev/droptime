<?php

declare(strict_types=1);

use DropTime\Domain\SwimTime;
use DropTime\Tests\Assert;

return [
    '分を含むタイムをセンチ秒にする' => static function (): void {
        Assert::same(6845, SwimTime::parse('1:08.45'));
    },
    '分の無いタイムをセンチ秒にする' => static function (): void {
        Assert::same(2871, SwimTime::parse('28.71'));
    },
    '小数 1 桁はセンチ秒に揃える' => static function (): void {
        Assert::same(2870, SwimTime::parse('28.7'));
    },
    '時を含む長距離のタイム' => static function (): void {
        Assert::same((1 * 3600 + 2 * 60 + 3) * 100 + 45, SwimTime::parse('1:02:03.45'));
    },
    '末尾の公認記号を無視する' => static function (): void {
        Assert::same(2871, SwimTime::parse('28.71Y'));
    },
    'NT は数値として解釈しない' => static function (): void {
        Assert::null(SwimTime::parse('NT'));
    },
    '空文字は null' => static function (): void {
        Assert::null(SwimTime::parse('   '));
    },
    '60 秒以上は分表記で整形する' => static function (): void {
        Assert::same('1:08.45', SwimTime::format(6845));
    },
    '60 秒未満は秒表記で整形する' => static function (): void {
        Assert::same('28.71', SwimTime::format(2871));
    },
    'センチ秒の桁を必ず 2 桁で出す' => static function (): void {
        Assert::same('1:00.05', SwimTime::format(6005));
    },
    '記録なしは -- で表示する' => static function (): void {
        Assert::same('--', SwimTime::format(null));
    },
    '往復変換で値が保たれる' => static function (): void {
        foreach (['28.71', '1:08.45', '9:59.99', '0.01'] as $text) {
            $centi = SwimTime::parse($text);
            Assert::true($centi !== null, $text . ' を解釈できない');
            Assert::same($centi, SwimTime::parse(SwimTime::format((int) $centi)), $text);
        }
    },
    '前回比は符号付きで出す' => static function (): void {
        Assert::same('-1.23', SwimTime::formatDelta(-123));
        Assert::same('+0.50', SwimTime::formatDelta(50));
    },
];
