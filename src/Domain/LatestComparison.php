<?php

declare(strict_types=1);

namespace DropTime\Domain;

use DropTime\Chart\BarSvg;

/**
 * 「いちばん新しい大会の泳ぎ」と「その 1 つ前」の比較。成績タブの ① と、大会当日のホームで使う。
 * Progression::build() の結果（種目の形ごとの泳ぎの系列）から組み立てる。
 */
final class LatestComparison
{
    /**
     * ① いちばん新しい大会の泳ぎと、それぞれの「1 つ前」の泳ぎ。
     *
     * 1 つ前は同じ種目（距離・泳法・コース）の直前の泳ぎ。同じ大会の予選と
     * 決勝なら、決勝の 1 つ前は予選になる。棒の長さはタイムに比例させ、
     * 0 から描く（縮めた分を誇張しない）。縮めた区間は別の色で塗る。
     *
     * $meetId を渡すと、その大会の泳ぎだけを比べる（ホームの「この大会の泳ぎ」）。
     *
     * @param list<array<string, mixed>> $shapes
     * @return array{meet_name: string, meet_id: int, date: string, races: list<array<string, mixed>>}|null
     */
    public static function of(array $shapes, ?int $meetId = null): ?array
    {
        $latestDate = '';

        foreach ($shapes as $shape) {
            foreach ($shape['swims'] as $swim) {
                if ($meetId === null || $swim['meet_id'] === $meetId) {
                    $latestDate = max($latestDate, $swim['date']);
                }
            }
        }

        if ($latestDate === '') {
            return null;
        }

        $races = [];
        $meet = null;

        foreach ($shapes as $shape) {
            foreach ($shape['swims'] as $index => $swim) {
                if ($swim['date'] !== $latestDate || ($meetId !== null && $swim['meet_id'] !== $meetId)) {
                    continue;
                }

                $meet ??= ['meet_name' => $swim['meet_name'], 'meet_id' => $swim['meet_id']];
                $previous = $index > 0 ? $shape['swims'][$index - 1] : null;
                $crossCourse = false;

                // 同じコースで初めてなら、別のコース（25m / 50m プール）の 1 つ前を参考に出す。
                // コースが違うタイムは同じ物差しでないので、縮めた区間は塗らない。
                if ($previous === null) {
                    $previous = self::previousInOtherCourse($shapes, $shape, $latestDate);
                    $crossCourse = $previous !== null;
                }

                $scale = max($swim['time_cs'], $previous['time_cs'] ?? 0);
                $isLong = $shape['course'] === 'LCM';

                // 光らせるのは、本当に速くなったと言えるときだけ。同じコースの自己ベスト更新か、
                // 50m プール（ターンが少なく、ふつう 25m プールより遅くなる）で前回より速いとき。
                $highlight = $previous !== null && ($crossCourse
                    ? $isLong && $swim['time_cs'] < $previous['time_cs']
                    : $swim['is_pb']);

                $races[] = [
                    'label' => $shape['label'],
                    'swim' => $swim,
                    'previous' => $previous,
                    'cross_course' => $crossCourse,
                    'highlight' => $highlight,
                    'now_bar' => BarSvg::render(
                        $swim['time_cs'] / $scale,
                        $highlight || $previous === null ? 'now' : 'plain',
                        $previous !== null && !$crossCourse ? $previous['time_cs'] / $scale : null
                    ),
                    'prev_bar' => $previous === null ? null : BarSvg::render($previous['time_cs'] / $scale, 'prev'),
                ];
            }
        }

        // 同じコースで縮めた種目を先に（縮めた幅の大きい順）、次にコース違いで
        // 比べられる種目、最後に比べる相手の無い種目。
        $order = static fn (array $race): array => match (true) {
            $race['previous'] === null => [2, 0, $race['label']],
            $race['cross_course'] => [1, $race['swim']['time_cs'] - $race['previous']['time_cs'], $race['label']],
            default => [0, (int) $race['swim']['delta_cs'], $race['label']],
        };
        usort($races, static fn (array $a, array $b): int => $order($a) <=> $order($b));

        return $meet + ['date' => $latestDate, 'races' => $races];
    }

    /**
     * 同じ距離・泳法を、別のコースで最後に泳いだ記録（$before の日付より前）。
     *
     * @param list<array<string, mixed>> $shapes
     * @param array<string, mixed>       $shape
     * @return array<string, mixed>|null 泳ぎの行に 'course' を足したもの
     */
    private static function previousInOtherCourse(array $shapes, array $shape, string $before): ?array
    {
        $found = null;

        foreach ($shapes as $other) {
            if ($other['distance'] !== $shape['distance'] || $other['stroke'] !== $shape['stroke'] || $other['course'] === $shape['course']) {
                continue;
            }

            foreach ($other['swims'] as $swim) {
                if ($swim['date'] < $before && ($found === null || $swim['date'] >= $found['date'])) {
                    $found = $swim + ['course' => $other['course']];
                }
            }
        }

        return $found;
    }
}
