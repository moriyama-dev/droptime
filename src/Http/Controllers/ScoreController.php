<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Chart\BarSvg;
use DropTime\Chart\LineChartSvg;
use DropTime\Domain\ClubComparison;
use DropTime\Domain\Progression;
use DropTime\Http\Controller;
use DropTime\Http\HttpException;
use DropTime\Http\ProfileSession;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Repository\FavoriteRepository;
use DropTime\Repository\SwimmerRepository;

/**
 * 「成績」タブ。1 人の選手について 2 つの問いに答える画面。
 *
 *   ① その大会で、同じ種目に出た選手の中で何番目だったか
 *   ② 自分の過去の同じ種目と比べて、どう変わってきたか
 *
 * ① を同年齢の中での順位を主役にして出すのは、年代別の部
 *（10 & Under など）が 2〜3 歳差を同居させるため。この年頃の 2 歳差は
 * 体格差がそのままタイム差になるので、全体順位だけでは実力が読めない。
 * 全体順位は事実として併記する。
 */
final class ScoreController extends Controller
{
    /** 「同じ年齢」を年下・年上へ広げられる上限（それぞれ）。 */
    private const MAX_AGE_SPREAD = 3;

    /** 選手を指定せずに来たとき、お気に入りの先頭へ送る。 */
    public function index(Request $request): Response
    {
        $favorites = $this->favoriteSwimmers();

        if ($favorites !== []) {
            return $this->redirectToRoute('scores.show', ['swimmer' => (int) $favorites[0]['id']]);
        }

        return $this->view('parent/scores-empty', []);
    }

    public function show(Request $request): Response
    {
        $swimmers = new SwimmerRepository($this->context->db);
        $swimmer = $swimmers->find($request->intParam('swimmer'));

        if ($swimmer === null) {
            throw HttpException::notFound('選手が見つかりません。');
        }

        $results = $swimmers->resultsOf((int) $swimmer['id']);

        // 順位を数えるには同じ種目に出た全員が要る。種目をまとめて 1 回で引く。
        $fields = $swimmers->fieldsOf(array_map(
            static fn (array $row): int => (int) $row['event_id'],
            $results
        ));

        // 「同じ年齢」の幅。既定は同い年だけ。?younger=1 で 1 歳下まで、?older=1 で 1 歳上まで含める。
        $ageRange = [
            'younger' => max(0, min(self::MAX_AGE_SPREAD, (int) $request->queryString('younger', '0'))),
            'older' => max(0, min(self::MAX_AGE_SPREAD, (int) $request->queryString('older', '0'))),
        ];

        $shapes = Progression::withRanks(Progression::build($results), $fields, $ageRange['younger'], $ageRange['older']);
        $club = $this->clubComparison($swimmers, $swimmer, 1, false);

        return $this->view('parent/scores', [
            'swimmer' => $swimmer,
            'switcher' => $this->switcher((int) $swimmer['id'], $swimmer),
            'highlights' => self::highlights($shapes),
            'latest' => self::latestComparison($shapes),
            'history' => self::history($shapes),
            'ranking' => self::ranking($shapes, $club),
            'clubPeers' => $club === null ? 0 : count($club['peers']) - 1,
            'ageRange' => $ageRange,
            'maxAgeSpread' => self::MAX_AGE_SPREAD,
        ]);
    }

    /**
     * 同じクラブの、年の近い仲間と自己ベストを比べる画面。
     *
     * 成績の画面（show）の ③ は仲間の中での順位だけを出す。こちらは
     * 仲間全員のタイムを並べた一覧で、上部の切り替えで行き来できる。
     *
     * ?band=1|2 で生年の幅、?gender=all で男女をまとめて比べる。
     */
    public function club(Request $request): Response
    {
        $swimmers = new SwimmerRepository($this->context->db);
        $swimmer = $swimmers->find($request->intParam('swimmer'));

        if ($swimmer === null) {
            throw HttpException::notFound('選手が見つかりません。');
        }

        $band = (int) $request->queryString('band', '1');
        $band = in_array($band, [1, 2], true) ? $band : 1;
        $allGenders = $request->queryString('gender') === 'all';

        return $this->view('parent/scores-club', [
            'swimmer' => $swimmer,
            'switcher' => $this->switcher((int) $swimmer['id'], $swimmer),
            'comparison' => $this->clubComparison($swimmers, $swimmer, $band, $allGenders),
            'band' => $band,
            'allGenders' => $allGenders,
        ]);
    }

    /**
     * @param array<string, mixed> $swimmer
     * @return array<string, mixed>|null 所属クラブが分からなければ null
     */
    private function clubComparison(SwimmerRepository $swimmers, array $swimmer, int $band, bool $allGenders): ?array
    {
        if ($swimmer['team_id'] === null) {
            return null;
        }

        $rows = $swimmers->clubResults(
            (int) $swimmer['team_id'],
            $allGenders ? null : ($swimmer['gender'] !== null ? (string) $swimmer['gender'] : null)
        );

        return ClubComparison::build((int) $swimmer['id'], $rows, $band);
    }

    /**
     * ② の見出しに添える数と、③ の見出しに添える最高順位。
     * どれも「順位が振るわない日でも残るもの」を選んでいる。
     *
     * @param list<array<string, mixed>> $shapes
     * @return array{races: int, pb_count: int, total_drop_cs: int, best_peer: ?array<string, mixed>}
     */
    private static function highlights(array $shapes): array
    {
        $races = 0;
        $pbCount = 0;
        $totalDrop = 0;
        $bestPeer = null;

        foreach ($shapes as $shape) {
            $totalDrop += (int) $shape['total_drop_cs'];

            foreach ($shape['swims'] as $swim) {
                $races++;

                // 初泳ぎは自動的にベストになるので「更新」には数えない。
                if ($swim['is_pb'] && $swim['delta_cs'] !== null) {
                    $pbCount++;
                }
            }

            $peer = $shape['best_peer'] ?? null;

            if ($peer === null) {
                continue;
            }

            if ($bestPeer === null || $peer['rank'] < $bestPeer['rank']) {
                $bestPeer = $peer + ['label' => $shape['label']];
            }
        }

        return [
            'races' => $races,
            'pb_count' => $pbCount,
            'total_drop_cs' => $totalDrop,
            'best_peer' => $bestPeer,
        ];
    }

    /**
     * ① いちばん新しい大会の泳ぎと、それぞれの「1 つ前」の泳ぎ。
     *
     * 1 つ前は同じ種目（距離・泳法・コース）の直前の泳ぎ。同じ大会の予選と
     * 決勝なら、決勝の 1 つ前は予選になる。棒の長さはタイムに比例させ、
     * 0 から描く（縮めた分を誇張しない）。縮めた区間は別の色で塗る。
     *
     * @param list<array<string, mixed>> $shapes
     * @return array{meet_name: string, meet_id: int, date: string, races: list<array<string, mixed>>}|null
     */
    private static function latestComparison(array $shapes): ?array
    {
        $latestDate = '';

        foreach ($shapes as $shape) {
            foreach ($shape['swims'] as $swim) {
                $latestDate = max($latestDate, $swim['date']);
            }
        }

        if ($latestDate === '') {
            return null;
        }

        $races = [];
        $meet = null;

        foreach ($shapes as $shape) {
            foreach ($shape['swims'] as $index => $swim) {
                if ($swim['date'] !== $latestDate) {
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

    /**
     * ② 種目ごとのこれまで。
     *
     * 2 回以上泳いだ種目は折れ線、1 回だけの種目は小さな表にまとめる
     *（グラフにならない種目が並ぶと、改善の見える種目が埋もれるため）。
     * 先頭には「種目ごとに縮めた秒数」の棒グラフを置き、どの種目で
     * いちばん伸びたかを一目で分かるようにする。
     *
     * @param list<array<string, mixed>> $shapes
     * @return array{drops: list<array<string, mixed>>, charts: list<array<string, mixed>>, singles: list<array<string, mixed>>}
     */
    private static function history(array $shapes): array
    {
        $charts = [];
        $singles = [];
        $drops = [];

        foreach ($shapes as $shape) {
            if (count($shape['swims']) < 2) {
                $singles[] = $shape;
                continue;
            }

            $charts[] = ['shape' => $shape, 'svg' => LineChartSvg::render($shape)];

            if ($shape['total_drop_cs'] > 0) {
                $first = (int) $shape['swims'][0]['time_cs'];
                $drops[] = [
                    'label' => $shape['label'],
                    'drop_cs' => (int) $shape['total_drop_cs'],
                    'percent' => $shape['total_drop_cs'] / $first * 100,
                ];
            }
        }

        usort($drops, static fn (array $a, array $b): int => $b['drop_cs'] <=> $a['drop_cs']);
        $maxDrop = $drops === [] ? 1 : $drops[0]['drop_cs'];

        foreach ($drops as $i => $drop) {
            $drops[$i]['bar'] = BarSvg::render($drop['drop_cs'] / $maxDrop, 'drop');
        }

        // 縮めた種目ほど上に。
        usort($charts, static fn (array $a, array $b): int =>
            $b['shape']['total_drop_cs'] <=> $a['shape']['total_drop_cs']);

        return ['drops' => $drops, 'charts' => $charts, 'singles' => $singles];
    }

    /**
     * ③ 種目ごとの順位を 2 つの物差しで。
     *
     *   同じ年齢: その種目のいちばん新しいレースで、同い年の中で何位か
     *             （同い年が居なければ全体順位に落とし、そう明記する）
     *   同じクラブ: 同じクラブ・同じ性別・生年 ±1 の仲間の中で、自己ベストが何位か
     *
     * 棒は「何人より前に居るか」に比例させる（1 位で満タン）。
     *
     * @param list<array<string, mixed>>  $shapes
     * @param array<string, mixed>|null   $club ClubComparison::build() の戻り値
     * @return list<array<string, mixed>>
     */
    private static function ranking(array $shapes, ?array $club): array
    {
        $clubByKey = [];

        foreach ($club['shapes'] ?? [] as $clubShape) {
            $clubByKey[$clubShape['distance'] . '|' . $clubShape['stroke'] . '|' . $clubShape['course']] = $clubShape;
        }

        $rows = [];

        foreach ($shapes as $shape) {
            $age = null;

            foreach (array_reverse($shape['swims']) as $swim) {
                if ($swim['rank'] === null) {
                    continue;
                }

                $isPeer = $swim['rank']['peer'] !== null;
                $standing = $isPeer ? $swim['rank']['peer'] : $swim['rank']['overall'];
                $age = [
                    'rank' => (int) $standing['rank'],
                    'count' => (int) $standing['count'],
                    'is_peer' => $isPeer,
                    'age' => $swim['age'],
                    'age_min' => $isPeer ? (int) $standing['age_min'] : null,
                    'age_max' => $isPeer ? (int) $standing['age_max'] : null,
                    'date' => $swim['date'],
                    'gap_ahead_cs' => $standing['gap_ahead_cs'],
                    'bar' => self::rankBar((int) $standing['rank'], (int) $standing['count']),
                ];
                break;
            }

            $clubShape = $clubByKey[$shape['distance'] . '|' . $shape['stroke'] . '|' . $shape['course']] ?? null;
            $clubCell = null;

            if ($clubShape !== null && $clubShape['count'] > 1 && $clubShape['child_rank'] !== null) {
                $clubCell = [
                    'rank' => (int) $clubShape['child_rank'],
                    'count' => (int) $clubShape['count'],
                    'gap_ahead_cs' => $clubShape['gap_above_cs'] ?? null,
                    'bar' => self::rankBar((int) $clubShape['child_rank'], (int) $clubShape['count']),
                ];
            }

            if ($age === null && $clubCell === null) {
                continue;
            }

            $rows[] = ['label' => $shape['label'], 'age' => $age, 'club' => $clubCell];
        }

        // 良かった種目から。どちらかの物差しで上位に居れば上に来る。
        $score = static fn (array $row): float => min(
            $row['age'] === null ? 1.0 : ($row['age']['rank'] - 1) / max(1, $row['age']['count']),
            $row['club'] === null ? 1.0 : ($row['club']['rank'] - 1) / max(1, $row['club']['count'])
        );
        usort($rows, static fn (array $a, array $b): int => [$score($a), $a['label']] <=> [$score($b), $b['label']]);

        return $rows;
    }

    /** 順位の棒。1〜3 位はメダルの色にする。 */
    private static function rankBar(int $rank, int $count): string
    {
        $variant = match ($rank) {
            1 => 'gold',
            2 => 'silver',
            3 => 'bronze',
            default => 'now',
        };

        return BarSvg::render(($count - $rank + 1) / max(1, $count), $variant);
    }

    /**
     * 上部の選手切り替え。お気に入りに加え、今見ている選手が
     * お気に入りでない場合はその選手も末尾に足す。
     *
     * @param array<string, mixed> $current
     * @return list<array<string, mixed>>
     */
    private function switcher(int $currentId, array $current): array
    {
        $swimmers = $this->favoriteSwimmers();

        foreach ($swimmers as $swimmer) {
            if ((int) $swimmer['id'] === $currentId) {
                return $swimmers;
            }
        }

        $swimmers[] = $current;

        return $swimmers;
    }

    /** @return list<array<string, mixed>> */
    private function favoriteSwimmers(): array
    {
        $profileId = ProfileSession::currentId();

        if ($profileId === null) {
            return [];
        }

        return (new FavoriteRepository($this->context->db))->favoritesOf($profileId);
    }
}
