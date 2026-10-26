<?php

declare(strict_types=1);

namespace DropTime\Domain;

use DateTimeImmutable;

/**
 * 「大会日にその年齢だった」という記録の束から、誕生日のあり得る範囲を絞る。
 *
 * Hy-Tek の結果には年齢しか載らないので、1 件だけでは生年が ±1 年ぶれる。
 * 年齢 a で大会日 d なら、誕生日は (d − (a+1) 年, d − a 年] のどこか。
 * 複数の大会の区間を重ねる（共通部分を取る）と範囲が狭まり、
 * 誕生日をまたいだ 2 大会があれば生年が 1 つに決まることが多い。
 *
 * 記録が食い違って共通部分が空になったら、最新の大会の区間を採る
 * （年齢の誤記より、新しい大会のほうが本人の申告に近いと見る）。
 */
final class BirthEstimate
{
    /**
     * @param list<array{date: string, age: int|null}> $observations
     * @return array{earliest: string, latest: string}|null 日付は Y-m-d
     */
    public static function range(array $observations): ?array
    {
        $intervals = [];

        foreach ($observations as $observation) {
            if ($observation['age'] === null || $observation['age'] <= 0) {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $observation['date']);

            if ($date === false) {
                continue;
            }

            $age = (int) $observation['age'];
            $latest = $date->modify("-{$age} years");
            $earliest = $date->modify('-' . ($age + 1) . ' years')->modify('+1 day');
            $intervals[] = [$earliest->format('Y-m-d'), $latest->format('Y-m-d'), $observation['date']];
        }

        if ($intervals === []) {
            return null;
        }

        $earliest = max(array_column($intervals, 0));
        $latest = min(array_column($intervals, 1));

        if ($earliest > $latest) {
            usort($intervals, static fn (array $a, array $b): int => strcmp($b[2], $a[2]));
            [$earliest, $latest] = $intervals[0];
        }

        return ['earliest' => $earliest, 'latest' => $latest];
    }

    /**
     * 生年の範囲。生年が分かっていればそれだけを返す。
     *
     * @param list<array{date: string, age: int|null}> $observations
     * @return array{0: int, 1: int}|null [最も早い生年, 最も遅い生年]
     */
    public static function birthYears(?int $knownBirthYear, array $observations): ?array
    {
        if ($knownBirthYear !== null && $knownBirthYear > 0) {
            return [$knownBirthYear, $knownBirthYear];
        }

        $range = self::range($observations);

        if ($range === null) {
            return null;
        }

        return [(int) substr($range['earliest'], 0, 4), (int) substr($range['latest'], 0, 4)];
    }
}
