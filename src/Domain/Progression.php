<?php

declare(strict_types=1);

namespace DropTime\Domain;

use DropTime\Support\Lang;

/**
 * タイム推移と自己ベストの計算。
 *
 * 選手 × 種目の形（距離・泳法・コース）ごとに、大会日順のタイム系列を作る。
 * 同じ 100 自由形でもコースが違えば別系列。リレーと非計時（DQ 等）は
 * 系列に入れない。指示どおりウィンドウ関数は使わず、全部 PHP で計算する。
 */
final class Progression
{
    /** 表示順を安定させるための泳法の順序。 */
    private const STROKE_ORDER = ['FR' => 1, 'BK' => 2, 'BR' => 3, 'FL' => 4, 'IM' => 5, 'MED' => 6];

    /**
     * @param list<array<string, mixed>> $results SwimmerRepository::resultsOf() の行
     * @return list<array{
     *     distance: int, stroke: string, course: string, label: string,
     *     best: array{time_cs: int, date: string, meet_name: string}, total_drop_cs: int,
     *     swims: list<array{date: string, meet_id: int, meet_name: string, event_id: int,
     *                       round: string, time_cs: int, age: ?int, delta_cs: ?int,
     *                       is_pb: bool, rank: ?array<string, mixed>}>
     * }>
     */
    public static function build(array $results): array
    {
        $series = [];

        foreach (self::chronological($results) as $row) {
            if ((int) $row['is_relay'] === 1) {
                continue;
            }

            if (($row['status'] ?? 'OK') !== 'OK' || $row['final_time_cs'] === null) {
                continue;
            }

            $key = $row['distance'] . '|' . $row['stroke'] . '|' . $row['course'];
            $series[$key][] = $row;
        }

        $shapes = [];

        foreach ($series as $rows) {
            $shapes[] = self::buildShape($rows);
        }

        usort($shapes, static function (array $a, array $b): int {
            $strokeA = self::STROKE_ORDER[$a['stroke']] ?? 9;
            $strokeB = self::STROKE_ORDER[$b['stroke']] ?? 9;

            return [$strokeA, $a['distance'], $a['course']] <=> [$strokeB, $b['distance'], $b['course']];
        });

        return $shapes;
    }

    /**
     * 大会日 → プログラム順に並べ直す。予選と決勝が同日に並ぶため、
     * 日付だけでなく種目の並び順まで見る。
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    private static function chronological(array $results): array
    {
        usort($results, static fn (array $a, array $b): int =>
            [(string) $a['start_date'], (int) ($a['sort_order'] ?? 0)]
            <=> [(string) $b['start_date'], (int) ($b['sort_order'] ?? 0)]);

        return $results;
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows 時系列順の同一種目の結果
     * @return array<string, mixed>
     */
    private static function buildShape(array $rows): array
    {
        $swims = [];
        $bestSoFar = null;
        $previous = null;
        $best = null;

        foreach ($rows as $row) {
            $time = (int) $row['final_time_cs'];
            $isPb = $bestSoFar === null || $time < $bestSoFar;

            $swim = [
                'date' => (string) $row['start_date'],
                'meet_id' => (int) $row['meet_id'],
                'meet_name' => (string) $row['meet_name'],
                'event_id' => (int) $row['event_id'],
                'round' => (string) $row['round'],
                'time_cs' => $time,
                // レース時点の年齢。同い年どうしの順位を出すのに使う。
                'age' => isset($row['age']) && $row['age'] !== null ? (int) $row['age'] : null,
                'delta_cs' => $previous === null ? null : $time - $previous,
                'is_pb' => $isPb,
                // withRanks() が後から入れる。単体では null のまま。
                'rank' => null,
            ];

            if ($isPb) {
                $bestSoFar = $time;
                $best = ['time_cs' => $time, 'date' => $swim['date'], 'meet_name' => $swim['meet_name']];
            }

            $swims[] = $swim;
            $previous = $time;
        }

        $first = $rows[0];
        $stroke = Stroke::tryFrom((string) $first['stroke']);

        return [
            'distance' => (int) $first['distance'],
            'stroke' => (string) $first['stroke'],
            'course' => (string) $first['course'],
            'label' => Lang::t(
                'shape.label',
                (int) $first['distance'],
                $stroke?->label() ?? (string) $first['stroke'],
                (string) $first['course']
            ),
            'best' => $best,
            // 初めて泳いだときから自己ベストまでで縮めた合計。0 以上。
            // 1 回しか泳いでいなければ 0。
            'total_drop_cs' => $swims[0]['time_cs'] - $best['time_cs'],
            'swims' => $swims,
        ];
    }

    /**
     * 各泳ぎに「その大会での位置づけ」を付ける。
     *
     * 順位の計算には同じ種目に出た全員が要るため、DB から引いた結果を
     * 外から渡す。ここで一緒にやるのは、推移（自分の過去との比較）と
     * 順位（その日の他の選手との比較）を同じ 1 本の系列に載せるため。
     *
     * @param list<array<string, mixed>>              $shapes build() の戻り値
     * @param array<int, list<array<string, mixed>>>  $fields 種目 ID => その種目の全結果
     * @param int $younger 「同じ年齢」に年下を何歳まで含めるか
     * @param int $older   「同じ年齢」に年上を何歳まで含めるか
     * @return list<array<string, mixed>>
     */
    public static function withRanks(array $shapes, array $fields, int $younger = 0, int $older = 0): array
    {
        foreach ($shapes as $shapeIndex => $shape) {
            $bestPeer = null;

            foreach ($shape['swims'] as $swimIndex => $swim) {
                $rank = FieldRank::of(
                    [
                        'final_time_cs' => $swim['time_cs'],
                        'age' => $swim['age'],
                        'status' => 'OK',
                    ],
                    $fields[$swim['event_id']] ?? [],
                    $younger,
                    $older
                );

                $shapes[$shapeIndex]['swims'][$swimIndex]['rank'] = $rank;

                if ($rank === null || $rank['peer'] === null) {
                    continue;
                }

                // その種目での「いちばん良かった同年齢順位」。見出しに出す。
                if ($bestPeer === null || $rank['peer']['rank'] < $bestPeer['rank']) {
                    $bestPeer = $rank['peer'] + ['date' => $swim['date'], 'meet_name' => $swim['meet_name']];
                }
            }

            $shapes[$shapeIndex]['best_peer'] = $bestPeer;
        }

        return $shapes;
    }
}
