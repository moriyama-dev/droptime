<?php

declare(strict_types=1);

namespace DropTime\Http;

use DateTimeImmutable;
use DropTime\Support\Clock;
use Throwable;

/**
 * 管理者のテスト時刻。スケジュールの画面を「この時刻だとしたら、どう見えるか」で表示するためのもの。
 *
 * 効くのは、管理者としてログインしているその端末の、スケジュールの画面の表示だけ。
 * 保存される時刻・通知・ほかの人の画面には影響しない（アプリ全体の時計は動かさない）。
 * セッションに置くので、管理者の権限が外れれば効かなくなる。
 */
final class TestClock
{
    private const KEY = 'test_now';

    /** 設定されていれば、現地の壁時計 'Y-m-d H:i:s'。 */
    public static function now(): ?string
    {
        $value = $_SESSION[self::KEY] ?? null;

        return is_string($value) && Auth::isAdmin() ? $value : null;
    }

    /**
     * @param string $input 画面の入力（'2026-11-15T09:00' など）
     * @return bool 読めて設定できたか
     */
    public static function set(string $input): bool
    {
        $parsed = self::parse($input);

        if ($parsed === null) {
            return false;
        }

        $_SESSION[self::KEY] = $parsed;

        return true;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /** 画面の入力を現地の壁時計 'Y-m-d H:i:s' にする。読めなければ null。 */
    public static function parse(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $input) !== 1) {
            return null;
        }

        try {
            return (new DateTimeImmutable(str_replace('T', ' ', $input), Clock::localZone()))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
