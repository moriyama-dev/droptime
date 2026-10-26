<?php

declare(strict_types=1);

namespace DropTime\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 時刻の扱いを 1 箇所に集約する。
 *
 *   * DB に書く記録用の日時（*_at）は UTC。MySQL の time_zone 設定に依存させない。
 *   * 画面に出すときだけ設定のタイムゾーン（America/Toronto）に変換する。
 *   * 大会の日付・時刻は現地の壁時計の値であり、変換の対象ではない。
 */
final class Clock
{
    private static ?DateTimeZone $local = null;

    public static function localZone(): DateTimeZone
    {
        if (self::$local === null) {
            self::$local = new DateTimeZone(Config::instance()->string('app.timezone', 'America/Toronto'));
        }

        return self::$local;
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** DB 保存用の UTC 文字列。 */
    public static function utcNow(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    /** 画面表示用に UTC 文字列を現地時刻へ変換する。 */
    public static function toLocal(string $utcDateTime): DateTimeImmutable
    {
        $parsed = new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC'));

        return $parsed->setTimezone(self::localZone());
    }

    /** 現地の「今日」の日付（ISO 8601）。 */
    public static function localToday(): string
    {
        return self::now()->setTimezone(self::localZone())->format('Y-m-d');
    }

    public static function timestamp(): int
    {
        return self::now()->getTimestamp();
    }
}
