<?php

declare(strict_types=1);

namespace DropTime\Domain;

/**
 * 同じクラブの、年の近い仲間とのベストタイム比較。
 *
 * 成績タブの順位（その日のレースに出た全クラブの選手との比較）とは問いが違う。
 * こちらは「普段一緒に練習している、年の近い子たちの中でどのあたりか」に答える。
 * 大会もコースも日付も違う記録を並べるので、比べるのは種目（距離・泳法・コース）
 * ごとの自己ベストどうしにする。
 *
 * 年の近さは生年で測る。生年が登録されていない選手が大半なので、
 * レース時点の年齢から BirthEstimate で範囲を推定し、範囲が少しでも
 * 重なれば仲間に入れる（取りこぼしより、少し広めに拾うほうを選ぶ）。
 */
final class ClubComparison
{
    /**
     * @param int                        $childId 主役の選手
     * @param list<array<string, mixed>> $rows    クラブ全員の結果（SwimmerRepository::clubResults() の行）
     * @param int                        $band    生年の幅（±年）
     * @return array{
     *     child_years: ?array{0:int,1:int},
     *     peers: list<array{id:int, name:string, years:?array{0:int,1:int}, is_child:bool}>,
     *     shapes: list<array<string, mixed>>,
     *     other_shapes: list<array{label:string, count:int}>
     * }
     */
    public static function build(int $childId, array $rows, int $band): array
    {
        $bySwimmer = [];

        foreach ($rows as $row) {
            $bySwimmer[(int) $row['swimmer_id']][] = $row;
        }

        $years = [];
        $names = [];

        foreach ($bySwimmer as $id => $swimmerRows) {
            $names[$id] = (string) $swimmerRows[0]['full_name'];
            $known = $swimmerRows[0]['birth_year'] ?? null;
            $years[$id] = BirthEstimate::birthYears(
                $known === null ? null : (int) $known,
                array_map(static fn (array $r): array => [
                    'date' => (string) $r['start_date'],
                    'age' => isset($r['age']) && $r['age'] !== null ? (int) $r['age'] : null,
                ], $swimmerRows)
            );
        }

        $childYears = $years[$childId] ?? null;
        $peerIds = [];

        foreach (array_keys($bySwimmer) as $id) {
            if ($id === $childId) {
                $peerIds[] = $id;
                continue;
            }

            if ($childYears === null || $years[$id] === null) {
                continue;
            }

            // 範囲どうしが重なるか。幅は主役の側に広げる。
            if ($years[$id][1] >= $childYears[0] - $band && $years[$id][0] <= $childYears[1] + $band) {
                $peerIds[] = $id;
            }
        }

        // 種目ごとに、仲間それぞれの自己ベストを集める。
        $shapes = [];

        foreach ($peerIds as $id) {
            foreach (Progression::build($bySwimmer[$id]) as $shape) {
                $key = $shape['distance'] . '|' . $shape['stroke'] . '|' . $shape['course'];
                $shapes[$key] ??= [
                    'distance' => $shape['distance'],
                    'stroke' => $shape['stroke'],
                    'course' => $shape['course'],
                    'label' => $shape['label'],
                    'rows' => [],
                ];
                $shapes[$key]['rows'][] = [
                    'swimmer_id' => $id,
                    'name' => $names[$id],
                    'years' => $years[$id],
                    'time_cs' => (int) $shape['best']['time_cs'],
                    'date' => (string) $shape['best']['date'],
                    'meet_name' => (string) $shape['best']['meet_name'],
                    'swims' => count($shape['swims']),
                    'is_child' => $id === $childId,
                ];
            }
        }

        $mine = [];
        $others = [];

        foreach ($shapes as $shape) {
            usort($shape['rows'], static fn (array $a, array $b): int =>
                [$a['time_cs'], $a['name']] <=> [$b['time_cs'], $b['name']]);

            $childRank = null;
            $rank = 0;
            $previousTime = null;

            foreach ($shape['rows'] as $i => $row) {
                // 同タイムは同順位。
                if ($row['time_cs'] !== $previousTime) {
                    $rank = $i + 1;
                    $previousTime = $row['time_cs'];
                }
                $shape['rows'][$i]['rank'] = $rank;

                if ($row['is_child']) {
                    $childRank = $rank;
                    $above = null;

                    for ($j = $i - 1; $j >= 0; $j--) {
                        if ($shape['rows'][$j]['time_cs'] < $row['time_cs']) {
                            $above = $shape['rows'][$j];
                            break;
                        }
                    }
                    $shape['gap_above_cs'] = $above === null ? null : $row['time_cs'] - $above['time_cs'];
                }
            }

            $shape['count'] = count($shape['rows']);
            $shape['child_rank'] = $childRank;

            if ($childRank !== null) {
                $mine[] = $shape;
            } elseif ($shape['count'] > 0) {
                $others[] = ['label' => $shape['label'], 'count' => $shape['count'], 'stroke' => $shape['stroke'], 'distance' => $shape['distance'], 'course' => $shape['course']];
            }
        }

        $order = static function (array $a, array $b): int {
            $strokes = ['FR' => 1, 'BK' => 2, 'BR' => 3, 'FL' => 4, 'IM' => 5];

            return [$strokes[$a['stroke']] ?? 9, $a['distance'], $a['course']]
                <=> [$strokes[$b['stroke']] ?? 9, $b['distance'], $b['course']];
        };
        usort($mine, $order);
        usort($others, $order);

        $peers = [];

        foreach ($peerIds as $id) {
            $peers[] = ['id' => $id, 'name' => $names[$id], 'years' => $years[$id], 'is_child' => $id === $childId];
        }

        usort($peers, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'child_years' => $childYears,
            'peers' => $peers,
            'shapes' => $mine,
            'other_shapes' => array_map(static fn (array $s): array => ['label' => $s['label'], 'count' => $s['count']], $others),
        ];
    }
}
