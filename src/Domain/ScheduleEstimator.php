<?php

declare(strict_types=1);

namespace DropTime\Domain;

/**
 * 「次に泳ぐレース」に出す推定開始時刻と、種目 1 件の所要時間の見積もり。
 *
 * 所要時間の考え方（2026-11 の NYAC Cup の実際の進み方で確かめた）:
 *   - 1 組にかかる時間 = その組でいちばん遅い選手のタイム + 組の入れ替え（約 50 秒）。
 *   - 組はエントリータイムの順に分けられるので、エントリータイムを遅い順に並べてレーン数ずつ区切れば、
 *     組ごとの「いちばん遅い選手」が分かる。タイムなし（NT）はいちばん遅い組に入るとみなす。
 *   - エントリータイムが 1 つも無い種目だけ、距離からの概算に落とす。
 *
 * 正確さは求めない。画面には「推定」と明示する。
 */
final class ScheduleEstimator
{
    /** 何も分からないときに置く 1 日の開始時刻。 */
    private const DEFAULT_START = '09:00';

    /** 組の入れ替え（退水・次の組の入場・スタートの合図まで）にかかる秒。実測は 40〜60 秒。 */
    public const HEAT_TURNOVER_SECONDS = 50;

    /** エントリータイムとして信じる範囲（秒/メートル）。入力の誤りで見積もりが飛ばないようにする。 */
    private const MIN_PACE = 0.4;
    private const MAX_PACE = 2.0;

    /**
     * 種目ごとの推定開始時刻を返す。
     *
     * @param list<array{id: int, distance: int, heats: int, scheduled_time: ?string, entries?: int}> $events
     *        sort_order 順に並んだ種目。heats は組数（組が分からなければ 0）。
     *        entries は人数（組が分からないとき、レーン数で割って組数を見積もる）。
     * @return array<int, array{time: string, estimated: bool}> 種目 ID => 時刻
     */
    public static function estimate(array $events, ?string $sessionStart = null, int $lanes = 8): array
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

            $heats = $event['heats'] > 0 ? $event['heats'] : (int) ceil(($event['entries'] ?? 0) / max(1, $lanes));
            $cursor += self::durationSeconds($event['distance'], $heats);
        }

        return $result;
    }

    /** エントリータイムが分からないときの、種目 1 件の所要時間（秒）。 */
    public static function durationSeconds(int $distance, int $heats): int
    {
        if ($heats <= 0) {
            // エントリー数が分からない種目は最低 1 組ぶんは進むと見なす。
            $heats = 1;
        }

        return $heats * (self::HEAT_TURNOVER_SECONDS + self::fallbackSwimSeconds($distance));
    }

    /**
     * エントリータイム（無ければ結果のタイム）から、種目 1 件の所要時間（秒）を見積もる。
     *
     * @param list<array{heat: ?int, cs: ?int}> $times 1 人（1 チーム）ごと。cs はセンチ秒、タイムなしは null
     * @param int $lanes 組が分からないときに区切るレーン数
     */
    public static function durationFromTimes(int $distance, array $times, int $lanes = 8): int
    {
        if ($times === []) {
            return self::durationSeconds($distance, 1);
        }

        $min = $distance * self::MIN_PACE;
        $max = $distance * self::MAX_PACE;
        $known = [];

        foreach ($times as $time) {
            if ($time['cs'] !== null && $time['cs'] > 0) {
                $known[] = min($max, max($min, $time['cs'] / 100));
            }
        }

        // タイムなしの選手は、その種目でいちばん遅い選手と同じだけかかるとみなす。
        $slowest = $known === [] ? (float) self::fallbackSwimSeconds($distance) : max($known);
        $seconds = [];
        $byHeat = [];
        $heatsKnown = true;

        foreach ($times as $time) {
            $value = $time['cs'] !== null && $time['cs'] > 0 ? min($max, max($min, $time['cs'] / 100)) : $slowest;
            $seconds[] = $value;

            if ($time['heat'] === null) {
                $heatsKnown = false;
            } else {
                $byHeat[$time['heat']] = max($byHeat[$time['heat']] ?? 0.0, $value);
            }
        }

        if (!$heatsKnown) {
            // 組が分からない（Psych Sheet）: 遅い順に並べてレーン数ずつ区切る。
            rsort($seconds);
            $byHeat = [];

            for ($i = 0, $n = count($seconds), $step = max(1, $lanes); $i < $n; $i += $step) {
                $byHeat[] = $seconds[$i];
            }
        }

        return (int) round(array_sum($byHeat) + count($byHeat) * self::HEAT_TURNOVER_SECONDS);
    }

    /** タイムが分からない組の、いちばん遅い選手の概算（秒）。年代別大会の実測に合わせた。 */
    private static function fallbackSwimSeconds(int $distance): int
    {
        $pace = match (true) {
            $distance <= 100 => 0.85,
            $distance <= 200 => 0.8,
            default => 0.75,
        };

        return (int) round($distance * $pace);
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
