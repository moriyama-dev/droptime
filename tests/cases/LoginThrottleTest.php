<?php

declare(strict_types=1);

use DropTime\Http\LoginThrottle;
use DropTime\Tests\Assert;

$now = 1_700_000_000;

return [
    '失敗が上限未満ならロックしない' => static function () use ($now): void {
        Assert::same(0, LoginThrottle::lockSecondsRemaining(9, $now - 10, $now, 10, 15));
    },
    '上限に達したらロックする' => static function () use ($now): void {
        Assert::same(15 * 60, LoginThrottle::lockSecondsRemaining(10, $now, $now, 10, 15));
    },
    'ロック時間が過ぎたら解除される' => static function () use ($now): void {
        Assert::same(0, LoginThrottle::lockSecondsRemaining(10, $now - 15 * 60, $now, 10, 15));
    },
    '残り時間は最後の失敗からの経過で減る' => static function () use ($now): void {
        Assert::same(60, LoginThrottle::lockSecondsRemaining(12, $now - 14 * 60, $now, 10, 15));
    },
    '失敗履歴が無ければロックしない' => static function () use ($now): void {
        Assert::same(0, LoginThrottle::lockSecondsRemaining(0, null, $now, 10, 15));
    },
    '上限を超えた回数でも残り時間は増えない' => static function () use ($now): void {
        Assert::same(
            LoginThrottle::lockSecondsRemaining(10, $now, $now, 10, 15),
            LoginThrottle::lockSecondsRemaining(500, $now, $now, 10, 15)
        );
    },
];
