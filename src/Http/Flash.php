<?php

declare(strict_types=1);

namespace DropTime\Http;

/**
 * リダイレクトをまたいで 1 回だけ表示するメッセージ。セッションに置く。
 */
final class Flash
{
    private const KEY = 'flash';

    public static function success(string $message): void
    {
        $_SESSION[self::KEY] = ['type' => 'success', 'message' => $message];
    }

    public static function error(string $message): void
    {
        $_SESSION[self::KEY] = ['type' => 'error', 'message' => $message];
    }

    /**
     * 取り出すと同時に消える。
     *
     * @return array{type: string, message: string}|null
     */
    public static function pull(): ?array
    {
        $flash = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);

        if (!is_array($flash) || !isset($flash['type'], $flash['message'])) {
            return null;
        }

        return ['type' => (string) $flash['type'], 'message' => (string) $flash['message']];
    }
}
