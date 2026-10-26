<?php

declare(strict_types=1);

namespace DropTime\Domain;

/**
 * 1 レースの中での位置づけ。
 *
 * 「同じ種目に出た選手全体の中で何位か」と「その中で同い年だけに絞ると何位か」を
 * 両方出す。年代別の部（10 & Under など）は 2〜3 歳差が同居するため、
 * この年頃では全体順位だけを見せても実力を表さない。主役は同年齢の順位、
 * 全体順位は事実として併記する、という並びにしている。
 *
 * 順位はタイムから数え直す。DB の place をそのまま使わないのは、同年齢に
 * 絞った順位を同じ規則で出すため（同着は同順位、次は飛ばす）。
 */
final class FieldRank
{
    /** 同年齢の枠を「順位」として見せるのに要る最少人数。1 人では比較にならない。 */
    private const MIN_PEERS = 2;

    /**
     * 「同じ年齢」は既定で同い年だけ。$younger / $older で、年下・年上へそれぞれ何歳まで
     * 含めるかを広げられる（保護者が画面で選ぶ）。
     *
     * @param array<string, mixed>       $target  対象選手の結果行
     * @param list<array<string, mixed>> $field   同じ種目の全結果行（非計時も混じってよい）
     * @param int                        $younger 年下へ何歳まで含めるか（0 = 含めない）
     * @param int                        $older   年上へ何歳まで含めるか（0 = 含めない）
     * @return array{
     *     time_cs: int, age: ?int, basis: 'peer'|'overall',
     *     overall: array{rank: int, count: int, beaten: int, top_percent: int,
     *                    gap_ahead_cs: ?int, gap_behind_cs: ?int, median_cs: int},
     *     peer: ?array{age: int, age_min: int, age_max: int, rank: int, count: int, beaten: int,
     *                  top_percent: int, gap_ahead_cs: ?int, gap_behind_cs: ?int, median_cs: int}
     * }|null
     */
    public static function of(array $target, array $field, int $younger = 0, int $older = 0): ?array
    {
        $time = self::timeOf($target);

        if ($time === null) {
            // 失格・棄権には順位が無い。
            return null;
        }

        $finishers = self::finishers($field);

        if ($finishers === []) {
            return null;
        }

        $age = isset($target['age']) && $target['age'] !== null ? (int) $target['age'] : null;

        $peer = null;

        if ($age !== null) {
            $min = $age - max(0, $younger);
            $max = $age + max(0, $older);
            $sameAge = array_values(array_filter(
                $finishers,
                static fn (array $row): bool => $row['age'] !== null && $row['age'] >= $min && $row['age'] <= $max
            ));

            if (count($sameAge) >= self::MIN_PEERS) {
                $peer = ['age' => $age, 'age_min' => $min, 'age_max' => $max] + self::standing($time, $sameAge);
            }
        }

        return [
            'time_cs' => $time,
            'age' => $age,
            'basis' => $peer === null ? 'overall' : 'peer',
            'overall' => self::standing($time, $finishers),
            'peer' => $peer,
        ];
    }

    /**
     * 与えられた集団の中での位置。
     *
     * @param list<array{time_cs: int, age: ?int}> $group 対象選手自身を含む
     * @return array{rank: int, count: int, beaten: int, top_percent: int,
     *               gap_ahead_cs: ?int, gap_behind_cs: ?int, median_cs: int}
     */
    private static function standing(int $time, array $group): array
    {
        $times = array_map(static fn (array $row): int => $row['time_cs'], $group);

        $faster = array_values(array_filter($times, static fn (int $t): bool => $t < $time));
        $slower = array_values(array_filter($times, static fn (int $t): bool => $t > $time));

        $rank = count($faster) + 1;
        $count = count($times);

        return [
            'rank' => $rank,
            'count' => $count,
            // 自分より後ろでゴールした人数。順位が振るわない日でも事実として残る。
            'beaten' => count($slower),
            'top_percent' => (int) max(1, min(100, (int) ceil($rank / $count * 100))),
            // すぐ上の選手との差。「あと何秒で 1 つ上」を出すために使う。
            'gap_ahead_cs' => $faster === [] ? null : $time - max($faster),
            // すぐ下の選手との差。1 位のときに「2 位に何秒差」を出すのに使う。
            'gap_behind_cs' => $slower === [] ? null : min($slower) - $time,
            // この集団の真ん中のタイム。推移グラフに重ねる基準線に使う。
            'median_cs' => self::median($times),
        ];
    }

    /**
     * 中央値。平均ではないのは、1 人の飛び抜けた記録に基準線が引きずられるため。
     * 偶数個のときは中央 2 つの平均を取り、センチ秒の整数に丸める。
     *
     * @param non-empty-list<int> $times
     */
    private static function median(array $times): int
    {
        sort($times);
        $count = count($times);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $times[$middle];
        }

        return (int) round(($times[$middle - 1] + $times[$middle]) / 2);
    }

    /**
     * 計時が成立した行だけを取り出し、比較に要る 2 列へ落とす。
     *
     * @param list<array<string, mixed>> $field
     * @return list<array{time_cs: int, age: ?int}>
     */
    private static function finishers(array $field): array
    {
        $rows = [];

        foreach ($field as $row) {
            $time = self::timeOf($row);

            if ($time === null) {
                continue;
            }

            $rows[] = [
                'time_cs' => $time,
                'age' => isset($row['age']) && $row['age'] !== null ? (int) $row['age'] : null,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private static function timeOf(array $row): ?int
    {
        if (($row['status'] ?? 'OK') !== 'OK') {
            return null;
        }

        $time = $row['final_time_cs'] ?? null;

        return $time === null ? null : (int) $time;
    }
}
