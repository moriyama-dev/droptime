<?php

declare(strict_types=1);

namespace DropTime\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 種目の所要時間の実測（event_timings）の作り方と使い方。
 *
 * 使い方の決まり（Yoshi の指示）:
 *   「過去に、この会場で、この種目・この距離は、これくらいかかった。今回も同じくらいだろう」。
 *   会場と、種目・距離の両方が合う実測があるときだけ使う。どちらかでも合わなければ使わない。
 *
 * 人数と速さが違えば所要時間も違うので、実測の秒数をそのまま当てはめず、
 * 「計算式（エントリータイムからの見積もり）の何倍かかったか」を実測ごとに出し、その中央値を今回の計算式に掛ける。
 *
 * 実測の限界: 速報サイトから分かるのは「結果が載った時刻」だけで、泳ぎ始めた時刻や組ごとの時刻は公開されていない。
 * 結果は数種目まとめて載ることがあり、その場合 1 種目ずつの差は当てにならない。だから、
 * 計算式からかけ離れた実測は使わず（保存はする）、複数回の中央値を取る。
 */
final class MeasuredTimings
{
    /** 見積もりに使うのに必要な実測の回数。1 回だけでは、まとめて載っただけかどうか見分けられない。 */
    public const MIN_SAMPLES = 2;

    /** 実測 ÷ 計算式 がこの範囲の外なら、結果がまとめて載った・載り直したとみなして使わない。 */
    private const RATIO_MIN = 0.5;
    private const RATIO_MAX = 2.0;

    /** 施設の名前と分かる語（meets.host に会場が入っている大会がある。Hy-Tek の名義が施設のとき）。 */
    private const FACILITY_WORDS = '/\b(centre|center|pool|olympium|natatorium|complex|aquatics? (?:centre|center))\b/i';

    /**
     * 会場のキー。会場が分からなければ null（実測を使わない・「この会場」と言えないため）。
     * クラブの名前（主催者）は会場ではないので使わない。
     */
    public static function venueKey(?string $venue, ?string $host): ?string
    {
        $name = trim((string) $venue);

        if ($name === '' && preg_match(self::FACILITY_WORDS, (string) $host) === 1) {
            $name = trim((string) $host);
        }

        if ($name === '') {
            return null;
        }

        return preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)) ?? null;
    }

    /** 種目・距離のキー。リレーは個人種目と分ける。 */
    public static function eventKey(string $stroke, int $distance, bool $relay): string
    {
        return strtoupper($stroke) . '|' . $distance . '|' . ($relay ? 'R' : 'I');
    }

    /**
     * 1 セッションぶんの「結果が載った時刻」から、種目ごとの実測を作る。
     *
     * 続けて泳いだ 2 種目の両方に時刻があるときだけ、あとの種目の所要時間が分かる。
     * セッションの最初の種目は作らない（開始の遅れ・ウォームアップの押しが混ざる）。
     *
     * @param string $date セッションの日付 'Y-m-d'
     * @param list<array{key: string, finished_at: ?string}> $events セッション内の並び順
     * @return array<string, array{actual_seconds: int, finished_at: string}> 種目のキー → 実測
     */
    public static function fromSession(string $date, array $events): array
    {
        $measured = [];
        $previous = null;

        foreach ($events as $event) {
            $at = $event['finished_at'];

            // 別の日の時刻は、あとから載り直したもの。
            if ($at === null || substr($at, 0, 10) !== $date) {
                $previous = null;

                continue;
            }

            if ($previous !== null) {
                $seconds = self::ts($at) - self::ts($previous);

                if ($seconds > 0) {
                    $measured[$event['key']] = ['actual_seconds' => $seconds, 'finished_at' => $at];
                }
            }

            $previous = $at;
        }

        return $measured;
    }

    /**
     * 同じ会場の実測から、種目・距離ごとの「計算式の何倍かかるか」を出す。
     *
     * @param list<array{stroke: string, distance: int, is_relay: bool, actual_seconds: int, model_seconds: int}> $rows
     *        同じ会場の実測だけを渡す
     * @return array<string, array{factor: ?float, samples: int}> 種目・距離のキー → 倍率（回数が足りなければ null）と、使える実測の回数
     */
    public static function factors(array $rows): array
    {
        $ratios = [];

        foreach ($rows as $row) {
            if ($row['model_seconds'] <= 0) {
                continue;
            }

            $ratio = $row['actual_seconds'] / $row['model_seconds'];

            if ($ratio >= self::RATIO_MIN && $ratio <= self::RATIO_MAX) {
                $ratios[self::eventKey($row['stroke'], $row['distance'], $row['is_relay'])][] = $ratio;
            }
        }

        $factors = [];

        foreach ($ratios as $key => $values) {
            sort($values);
            $count = count($values);
            $middle = intdiv($count, 2);
            $median = $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

            $factors[$key] = ['factor' => $count >= self::MIN_SAMPLES ? $median : null, 'samples' => $count];
        }

        return $factors;
    }

    private static function ts(string $wallClock): int
    {
        return (new DateTimeImmutable($wallClock, new DateTimeZone('UTC')))->getTimestamp();
    }
}
