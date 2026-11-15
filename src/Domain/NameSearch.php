<?php

declare(strict_types=1);

namespace DropTime\Domain;

use DropTime\Support\Str;

/**
 * 選手名のあいまい検索（お気に入りの「選手をさがす」と入力中の候補）。
 *
 * - 語順を問わない: 「Jane Doe」でも「doe jane」でも当たる。
 * - 打ちかけでも当たる: 「ari mori」→ 各語を名前の語の頭と比べる。
 * - 綴りの間違いを推測する: 1 文字の抜け・余分・置き換え・隣どうしの入れ替えを
 *   語の長さに応じて許し（4〜5 文字は 1 か所、6 文字以上は 2 か所）、
 *   さらに発音が同じ綴り（metaphone）も拾う。
 * - 空白なしの「janedoe」もつなげた名前と比べる。
 *
 * 入力の語はすべて、名前の別々の語に当たらないといけない（AND）。
 * 綴りの推測を使わずに当たった選手が 1 人でもいれば、推測の結果は出さない
 * （正しく打ったのに、似た名前の他人が混ざらないように）。
 */
final class NameSearch
{
    /** これ以上の点なら「綴りどおりに当たった」とみなす。 */
    private const EXACT_THRESHOLD = 0.75;

    /**
     * @param list<array{id: int, last_name: string, first_name: string, preferred?: bool}> $candidates
     *        preferred は同点のときに上に出す選手（自分のクラブなど）。
     * @return array{exact: bool, hits: list<array{id: int, score: float}>}
     *         exact=false のときは「もしかして」の結果。
     */
    public static function search(string $query, array $candidates, int $limit = 30): array
    {
        $queryTokens = self::tokens($query);

        if ($queryTokens === []) {
            return ['exact' => true, 'hits' => []];
        }

        $joinedQuery = implode('', $queryTokens);
        $cache = [];
        $exact = [];
        $fuzzy = [];

        foreach ($candidates as $candidate) {
            $nameTokens = self::tokens($candidate['last_name'] . ' ' . $candidate['first_name']);

            if ($nameTokens === []) {
                continue;
            }

            $match = self::matchTokens($queryTokens, $nameTokens, $cache);

            // 空白を入れ忘れた入力（janedoe）。つなげた名前とだけ比べる。
            if ($match === null && count($queryTokens) === 1 && strlen($joinedQuery) >= 6) {
                $joined = self::joinedScore($joinedQuery, $nameTokens);
                $match = $joined === null ? null : [$joined, $joined];
            }

            if ($match === null) {
                continue;
            }

            [$score, $weakest] = $match;

            $hit = [
                'id' => (int) $candidate['id'],
                'score' => $score,
                'preferred' => (bool) ($candidate['preferred'] ?? false),
                'sort' => implode(' ', $nameTokens),
            ];

            // 1 語でも推測で当たったなら「もしかして」に回す。
            if ($weakest >= self::EXACT_THRESHOLD) {
                $exact[] = $hit;
            } else {
                $fuzzy[] = $hit;
            }
        }

        $isExact = $exact !== [] || $fuzzy === [];
        $hits = $isExact ? $exact : $fuzzy;

        usort($hits, static function (array $a, array $b): int {
            return [$b['score'], $b['preferred'], $a['sort'], $a['id']]
                <=> [$a['score'], $a['preferred'], $b['sort'], $b['id']];
        });

        $hits = array_slice($hits, 0, max(1, $limit));

        return [
            'exact' => $isExact,
            'hits' => array_map(
                static fn (array $h): array => ['id' => $h['id'], 'score' => round($h['score'], 3)],
                $hits
            ),
        ];
    }

    /**
     * 入力の 1 語と名前の 1 語の近さ（0 = 当たらない、1 = 完全一致）。
     */
    public static function tokenScore(string $q, string $n): float
    {
        if ($q === $n) {
            return 1.0;
        }

        $qLen = strlen($q);
        $nLen = strlen($n);

        if (str_starts_with($n, $q)) {
            // 打ちかけ。打った割合が大きいほど少しだけ上に。
            return 0.85 + 0.1 * ($qLen / $nLen);
        }

        if ($qLen >= 3 && str_contains($n, $q)) {
            return 0.76;
        }

        $allowed = self::allowedEdits($qLen);
        $best = 0.0;

        if ($allowed > 0) {
            // 語全体の綴り違い（Tanbae → tanabe）。
            if (abs($qLen - $nLen) <= $allowed && levenshtein($q, $n) <= $allowed + 1) {
                $d = self::editDistance($q, $n);

                if ($d <= $allowed) {
                    $best = max($best, 0.7 - 0.1 * $d);
                }
            }

            // 打ちかけの綴り違い（Tanab → tanabe は上で拾える。Tnaab → tanabe はここ）。
            if ($nLen > $qLen) {
                $d = self::editDistance($q, substr($n, 0, $qLen));

                if ($d <= $allowed) {
                    $best = max($best, 0.6 - 0.1 * $d);
                }
            }
        }

        // 発音が同じ綴り（Katherine / Catherine、Kowalski / Kovalski）。
        if ($best === 0.0 && $qLen >= 4 && $nLen >= 4) {
            $mq = metaphone($q);

            if ($mq !== '' && $mq === metaphone($n)) {
                $best = 0.55;
            }
        }

        return $best;
    }

    /**
     * 1 か所の抜け・余分・置き換え・隣どうしの入れ替えを、それぞれ 1 と数える距離
     * （Optimal String Alignment）。levenshtein() は入れ替えを 2 と数えるため。
     */
    public static function editDistance(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);

        if ($la === 0) {
            return $lb;
        }

        if ($lb === 0) {
            return $la;
        }

        $d = [];

        for ($i = 0; $i <= $la; $i++) {
            $d[$i] = [$i];
        }

        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $v = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);

                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $v = min($v, $d[$i - 2][$j - 2] + 1);
                }

                $d[$i][$j] = $v;
            }
        }

        return $d[$la][$lb];
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        $normalized = Str::normalizeName($value);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(explode(' ', $normalized)));
    }

    private static function allowedEdits(int $length): int
    {
        if ($length <= 3) {
            return 0;
        }

        return $length <= 5 ? 1 : 2;
    }

    /**
     * 入力の語をそれぞれ名前の別々の語に割り当て、[平均点, いちばん低い語の点] を返す。
     * 1 語でも当たらなければ null。名前の語は 2〜4 個なので総当たりで足りる。
     *
     * @param list<string> $queryTokens
     * @param list<string> $nameTokens
     * @param array<string, float> $cache
     * @return array{0: float, 1: float}|null
     */
    private static function matchTokens(array $queryTokens, array $nameTokens, array &$cache): ?array
    {
        if (count($queryTokens) > count($nameTokens)) {
            return null;
        }

        $scores = [];

        foreach ($queryTokens as $qi => $q) {
            $any = false;

            foreach ($nameTokens as $ni => $n) {
                $key = $q . '|' . $n;
                $s = $cache[$key] ??= self::tokenScore($q, $n);
                $scores[$qi][$ni] = $s;
                $any = $any || $s > 0.0;
            }

            if (!$any) {
                return null;
            }
        }

        $best = self::bestAssignment($scores, 0, [], count($queryTokens));

        return $best === null ? null : [$best[0] / count($queryTokens), $best[1]];
    }

    /**
     * @param array<int, array<int, float>> $scores
     * @param array<int, true> $used
     * @return array{0: float, 1: float}|null [合計点, いちばん低い語の点]
     */
    private static function bestAssignment(array $scores, int $qi, array $used, int $count): ?array
    {
        if ($qi === $count) {
            return [0.0, 1.0];
        }

        $best = null;

        foreach ($scores[$qi] as $ni => $s) {
            if ($s <= 0.0 || isset($used[$ni])) {
                continue;
            }

            $rest = self::bestAssignment($scores, $qi + 1, $used + [$ni => true], $count);

            if ($rest !== null && ($best === null || $s + $rest[0] > $best[0])) {
                $best = [$s + $rest[0], min($s, $rest[1])];
            }
        }

        return $best;
    }

    /** @param list<string> $nameTokens */
    private static function joinedScore(string $joinedQuery, array $nameTokens): ?float
    {
        $orders = [implode('', $nameTokens), implode('', array_reverse($nameTokens))];
        $best = null;

        foreach ($orders as $joined) {
            if ($joined === $joinedQuery) {
                return 0.9;
            }

            if (abs(strlen($joined) - strlen($joinedQuery)) <= 2 && levenshtein($joined, $joinedQuery) <= 3) {
                $d = self::editDistance($joinedQuery, $joined);

                if ($d <= 2) {
                    $best = max($best ?? 0.0, 0.7 - 0.1 * $d);
                }
            }
        }

        return $best;
    }
}
