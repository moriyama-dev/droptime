<?php

declare(strict_types=1);

namespace DropTime\Http;

/**
 * 認証状態はセッションのフラグのみ。個別アカウントは持たない。
 * ログイン処理そのものは M2 で実装する。
 */
final class Auth
{
    private const PARENT_KEY = 'auth.parent';
    private const ADMIN_KEY = 'auth.admin';

    /** 合言葉が未設定のときにも同じだけ時間を使うためのダミー。 */
    private const DUMMY_HASH = '$2y$10$dZYAegHh/VgK0cglYQD66eQPnCkeTuTp8hRp2TVbHo7ET83dB97E.'; // privacy-check: allow（時間をそろえるためのダミー。どの合言葉にも対応しない）

    public static function isParent(): bool
    {
        return ($_SESSION[self::PARENT_KEY] ?? false) === true;
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION[self::ADMIN_KEY] ?? false) === true;
    }

    public static function grantParent(): void
    {
        $_SESSION[self::PARENT_KEY] = true;
    }

    public static function grantAdmin(): void
    {
        $_SESSION[self::ADMIN_KEY] = true;
    }

    public static function allows(Access $access): bool
    {
        return match ($access) {
            Access::PUBLIC => true,
            Access::PARENT => self::isParent(),
            Access::ADMIN => self::isAdmin(),
        };
    }

    /**
     * 合言葉の照合。
     *
     * 設定にハッシュが無い場合でもダミーのハッシュで検証を回し、
     * 「未設定なのか不一致なのか」が応答時間の差から分からないようにする。
     */
    public static function verifyPassphrase(string $expectedHash, string $input): bool
    {
        if ($expectedHash === '') {
            password_verify($input, self::DUMMY_HASH);

            return false;
        }

        return password_verify($input, $expectedHash);
    }

    public static function forget(): void
    {
        unset($_SESSION[self::PARENT_KEY], $_SESSION[self::ADMIN_KEY]);
    }
}
