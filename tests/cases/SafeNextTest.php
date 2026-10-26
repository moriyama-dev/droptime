<?php

declare(strict_types=1);

use DropTime\Http\Controllers\AuthController;
use DropTime\Tests\Assert;

return [
    '自サイト内のパスは通す' => static function (): void {
        Assert::same('/meets/3', AuthController::safeNext('/meets/3'));
    },
    '空文字はそのまま' => static function (): void {
        Assert::same('', AuthController::safeNext(''));
    },
    '絶対 URL を拒否する' => static function (): void {
        Assert::same('', AuthController::safeNext('https://example.com/'));
    },
    'スキーム相対 URL を拒否する' => static function (): void {
        Assert::same('', AuthController::safeNext('//example.com/'));
    },
    'バックスラッシュを使った外部遷移を拒否する' => static function (): void {
        Assert::same('', AuthController::safeNext('/\\example.com'));
    },
    '改行の混入を拒否する' => static function (): void {
        Assert::same('', AuthController::safeNext("/home\r\nSet-Cookie: x=1"));
    },
];
