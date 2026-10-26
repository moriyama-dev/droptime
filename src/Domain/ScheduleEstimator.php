<?php

declare(strict_types=1);

namespace DropTime\Domain;

/**
 * 「次に泳ぐレース」に出す推定開始時刻。
 *
 * ヒートシートに時刻の記載があればそれを優先し、無い種目は
 * 「直近の判明時刻 + そこからの種目ごとの所要時間の積算」で概算する。
 * 指示書のとおり正確さは求めない。画面には「推定」と明示する。
 */
final class ScheduleEstimator
{
    /** 何も分からないときに置く 1 日の開始時刻。 */
    private const DEFAULT_START = '09:00';

    /** ヒートの入れ替えにかかる固定分（秒）。 */
    private const HEAT_OVERHEAD_SECONDS = 50;

    /** 泳ぎそのものの概算（秒/メートル）。子供の大会想定でゆっくりめに見る。 */
    private const SECONDS_PER_METER = 1.1;

    /** 種目の切り替え（アナウンス等）の固定分（秒）。 */
    private const EVENT_OVERHEAD_SECONDS = 60;

    /**
     * 種目ごとの推定開始時刻を返す。
     *
     * @param list<array{id: int, distance: int, heats: int, scheduled_time: ?string}> $events
     *        sort_order 順に並んだ種目。heats は組数（エントリーが無ければ 0）。
     * @return array<int, array{time: string, estimated: bool}> 種目 ID => 時刻
     */
    public static function estimate(array $events, ?string $sessionStart = null): array
    {
        $result = [];
        $cursor = self::toSeconds($sessionStart ?? self::DEFAULT_START);

        foreach ($events as $event) {
            $scheduled = $event['scheduled_time'] ?? null;

            if ($scheduled !== null && self::isTime($scheduled)) {
                // 記載があればそれを信じ、以降の積算の起点を合わせ直す。
                $cursor = max($cursor, self::toSeconds($scheduled));
                $result[$event['id']] = ['time' => self::toTime(self::toSeconds($scheduled)), 'estimated' => false];
            } else {
                $result[$event['id']] = ['time' => self::toTime($cursor), 'estimated' => true];
            }

            $cursor += self::durationSeconds($event['distance'], $event['heats']);
        }

        return $result;
    }

    /** 種目 1 件の所要時間（秒）。 */
    public static function durationSeconds(int $distance, int $heats): int
    {
        if ($heats <= 0) {
            // エントリー数が分からない種目は最低 1 組ぶんは進むと見なす。
            $heats = 1;
        }

        $perHeat = self::HEAT_OVERHEAD_SECONDS + (int) round($distance * self::SECONDS_PER_METER);

        return $heats * $perHeat + self::EVENT_OVERHEAD_SECONDS;
    }

    private static function isTime(string $value): bool
    {
        return preg_match('/^\d{1,2}:\d{2}$/', $value) === 1;
    }

    private static function toSeconds(string $time): int
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $time));

        return $hours * 3600 + $minutes * 60;
    }

    private static function toTime(int $seconds): string
    {
        // 分単位に丸める。それ以上の精度は嘘になる。
        $minutes = intdiv($seconds + 30, 60);

        return sprintf('%d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
