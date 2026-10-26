<?php

declare(strict_types=1);

namespace DropTime\Http;

/**
 * 状態を変える操作はすべて POST とし、Kernel が例外なくトークンを検証する。
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf.token';
    public const FIELD = '_token';

    public static function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function verify(Request $request): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $request->postString(self::FIELD));
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
