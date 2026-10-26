<?php

declare(strict_types=1);

use DropTime\Http\Access;
use DropTime\Http\Auth;
use DropTime\Tests\Assert;

return [
    '正しい合言葉を受け入れる' => static function (): void {
        $hash = password_hash('correct horse battery', PASSWORD_DEFAULT);
        Assert::true(Auth::verifyPassphrase($hash, 'correct horse battery'));
    },
    '違う合言葉を拒否する' => static function (): void {
        $hash = password_hash('correct horse battery', PASSWORD_DEFAULT);
        Assert::false(Auth::verifyPassphrase($hash, 'wrong'));
    },
    'ハッシュ未設定なら常に拒否する' => static function (): void {
        Assert::false(Auth::verifyPassphrase('', ''));
        Assert::false(Auth::verifyPassphrase('', 'anything'));
    },
    'PUBLIC は未ログインでも通す' => static function (): void {
        $_SESSION = [];
        Assert::true(Auth::allows(Access::PUBLIC));
    },
    'PARENT は未ログインを弾く' => static function (): void {
        $_SESSION = [];
        Assert::false(Auth::allows(Access::PARENT));
    },
    'ADMIN は保護者ログインだけでは通さない' => static function (): void {
        $_SESSION = [];
        Auth::grantParent();
        Assert::true(Auth::allows(Access::PARENT));
        Assert::false(Auth::allows(Access::ADMIN));
        $_SESSION = [];
    },
    'ADMIN は管理者フラグで通る' => static function (): void {
        $_SESSION = [];
        Auth::grantAdmin();
        Assert::true(Auth::allows(Access::ADMIN));
        $_SESSION = [];
    },
    'ログアウトで両方のフラグが消える' => static function (): void {
        $_SESSION = [];
        Auth::grantParent();
        Auth::grantAdmin();
        Auth::forget();
        Assert::false(Auth::isParent());
        Assert::false(Auth::isAdmin());
    },
];
