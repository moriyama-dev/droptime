<?php

declare(strict_types=1);

namespace DropTime\Http;

use DropTime\Support\Clock;

/**
 * 端末（ブラウザ）の目印。お気に入りの入れ物を、ログインとは別に端末へ結び付けるための Cookie。
 *
 * ログアウトや 90 日の放置でセッションが切れても、この Cookie が残っていれば
 * 同じお気に入りに戻れる。値はランダムな 64 桁の 16 進で、DB には SHA-256 だけを置く。
 * 1 年もち、アクセスのたびに延びる。名前などの個人情報は含まない。
 */
final class DeviceCookie
{
    public const NAME = 'dt_device';
    private const LIFETIME = 365 * 86400;

    private static string $path = '/';
    private static bool $secure = true;

    public static function configure(string $path, bool $secure): void
    {
        self::$path = $path;
        self::$secure = $secure;
    }

    public static function token(): ?string
    {
        $value = $_COOKIE[self::NAME] ?? null;

        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    public static function hash(): ?string
    {
        $token = self::token();

        return $token === null ? null : hash('sha256', $token);
    }

    /** 新しい目印を発行して、その SHA-256 を返す。 */
    public static function issue(): string
    {
        $token = bin2hex(random_bytes(32));
        self::send($token, Clock::timestamp() + self::LIFETIME);
        $_COOKIE[self::NAME] = $token;

        return hash('sha256', $token);
    }

    /** 有効期限を 1 年先へ延ばす。 */
    public static function extend(): void
    {
        $token = self::token();

        if ($token !== null) {
            self::send($token, Clock::timestamp() + self::LIFETIME);
        }
    }

    public static function forget(): void
    {
        self::send('', 1);
        unset($_COOKIE[self::NAME]);
    }

    private static function send(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::NAME, $value, [
            'expires' => $expires,
            'path' => self::$path,
            'secure' => self::$secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
