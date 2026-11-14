<?php

declare(strict_types=1);

namespace DropTime\Http;

use DropTime\Support\Clock;

/**
 * 認証状態はセッションのフラグのみ。個別アカウントは持たない。
 * ログイン処理そのものは M2 で実装する。
 */
final class Auth
{
    private const PARENT_KEY = 'auth.parent';
    private const ADMIN_KEY = 'auth.admin';
    /** 管理画面を最後に使った時刻（UNIX 秒）。 */
    private const ADMIN_SEEN_KEY = 'auth.admin_seen';

    /** 管理画面は最後の操作からこの秒数で切れる。保護者のログインは残る。 */
    private static int $adminIdleSeconds = 8 * 3600;

    public static function configureAdminIdle(int $seconds): void
    {
        self::$adminIdleSeconds = max(60, $seconds);
    }

    /** 合言葉が未設定のときにも同じだけ時間を使うためのダミー。 */
    private const DUMMY_HASH = '$2y$10$dZYAegHh/VgK0cglYQD66eQPnCkeTuTp8hRp2TVbHo7ET83dB97E.'; // privacy-check: allow（時間をそろえるためのダミー。どの合言葉にも対応しない）

    public static function isParent(): bool
    {
        return ($_SESSION[self::PARENT_KEY] ?? false) === true;
    }

    /**
     * 管理者か。最後の操作から一定時間（既定 8 時間）たっていたら管理者の権限だけを外す。
     * 保護者としてのログインはそのまま残る。通るたびに最後の操作時刻を更新する。
     */
    public static function isAdmin(?int $now = null): bool
    {
        if (($_SESSION[self::ADMIN_KEY] ?? false) !== true) {
            return false;
        }

        $now ??= Clock::timestamp();
        $seen = $_SESSION[self::ADMIN_SEEN_KEY] ?? null;

        if (!is_int($seen) || $now - $seen > self::$adminIdleSeconds) {
            unset($_SESSION[self::ADMIN_KEY], $_SESSION[self::ADMIN_SEEN_KEY]);

            return false;
        }

        $_SESSION[self::ADMIN_SEEN_KEY] = $now;

        return true;
    }

    public static function grantParent(): void
    {
        $_SESSION[self::PARENT_KEY] = true;
    }

    public static function grantAdmin(?int $now = null): void
    {
        $_SESSION[self::ADMIN_KEY] = true;
        $_SESSION[self::ADMIN_SEEN_KEY] = $now ?? Clock::timestamp();
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
        unset($_SESSION[self::PARENT_KEY], $_SESSION[self::ADMIN_KEY], $_SESSION[self::ADMIN_SEEN_KEY]);
    }
}
