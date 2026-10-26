<?php

declare(strict_types=1);

use DropTime\Support\TransferCode;
use DropTime\Tests\Assert;

return [
    '生成されたコードは表示形式になっている' => static function (): void {
        $code = TransferCode::generate();
        Assert::same(1, preg_match('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/', $code), $code);
    },
    '大文字小文字・空白・ハイフンのゆれを吸収する' => static function (): void {
        Assert::same(TransferCode::hash('ABC23-DEF45'), TransferCode::hash('abc23 def45'));
        Assert::same(TransferCode::hash('ABC23-DEF45'), TransferCode::hash('  abc23def45  '));
    },
    '紛らわしい文字を寄せる（O→0, I/L→1, U→V）' => static function (): void {
        Assert::same('01', TransferCode::normalize('OI'));
        Assert::same('11V', TransferCode::normalize('ilu'));
    },
    '違うコードは違うハッシュになる' => static function (): void {
        Assert::true(TransferCode::hash('AAAAA-AAAA2') !== TransferCode::hash('AAAAA-AAAA3'));
    },
    'pretty はハイフン区切りへ整える' => static function (): void {
        Assert::same('ABC23-DEF45', TransferCode::pretty('abc23def45'));
    },
];
