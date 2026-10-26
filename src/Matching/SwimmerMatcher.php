<?php

declare(strict_types=1);

namespace DropTime\Matching;

use DropTime\Db\Database;
use DropTime\Parser\ParsedSwimmer;
use DropTime\Support\Clock;

/**
 * 選手の同一人物判定。
 *
 * 大会ごとにファイルが別なので、取り込みのたびに「この名前は既存のどの子か」を
 * 決める必要がある。判定は normalized_key（PHP 側で正規化した氏名）の完全一致を
 * 前提に、生年とチームで絞り込む。DB の照合順序には一切依存しない。
 *
 * 自動で結び付けるのは信頼度が高い場合だけ。曖昧なら新しい選手として登録し、
 * swimmer_merge_candidates に積んで人間の判定に回す。誤統合は記録が壊れて
 * 発見しにくいため、迷ったら分けておくほうが安全（統合は後からできるが、
 * 自動で混ざったものを見つけるのは難しい）。
 */
final class SwimmerMatcher
{
    /** @var array<string, int> 同一取り込み内のキャッシュ。キーは key|birth|team */
    private array $resolved = [];

    /** @var array<string, int> 同じ大会の前回の投入で決まった選手。キーは key|team */
    private array $previous = [];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * 同じ大会を投入し直すとき、前回その大会で「この名前・このチーム」に
     * 結び付けた選手を覚えておく。
     *
     * 速報は大会中に何度も更新されるので、同じ大会を取り込み直すことがある。
     * そのたびに判定をやり直すと、同姓同名が居る子は毎回「区別できない」として
     * 新しい選手に分かれ、前回の結果まで新しい選手へ移ってしまう。同じ大会の
     * 同じ名前・同じチームは同じ子なので、前回の判定をそのまま使う
     *（管理者が統合した結果もそのまま引き継がれる）。
     */
    public function rememberMeet(int $meetId): void
    {
        $rows = $this->db->select(
            'SELECT DISTINCT s.normalized_key, x.team_id, x.swimmer_id FROM ('
            . ' SELECT r.swimmer_id, r.team_id FROM results r JOIN events e ON e.id = r.event_id'
            . ' WHERE e.meet_id = :meet_a AND r.swimmer_id IS NOT NULL'
            . ' UNION SELECT n.swimmer_id, n.team_id FROM entries n JOIN events e ON e.id = n.event_id'
            . ' WHERE e.meet_id = :meet_b AND n.swimmer_id IS NOT NULL'
            . ') x JOIN swimmers s ON s.id = x.swimmer_id',
            ['meet_a' => $meetId, 'meet_b' => $meetId]
        );

        $map = [];
        $ambiguous = [];

        foreach ($rows as $row) {
            $key = $row['normalized_key'] . '|' . ($row['team_id'] ?? '?');

            if (isset($map[$key]) && $map[$key] !== (int) $row['swimmer_id']) {
                $ambiguous[$key] = true; // 同じ大会・同じチームに同名が 2 人。前回の判定に頼らない
            }

            $map[$key] = (int) $row['swimmer_id'];
        }

        $this->previous = array_diff_key($map, $ambiguous);
    }

    /**
     * 選手を既存に結び付けるか、新規作成して ID を返す。
     */
    public function resolve(ParsedSwimmer $swimmer, ?int $teamId): int
    {
        $key = $swimmer->normalizedKey();
        $cacheKey = $key . '|' . ($swimmer->birthYear ?? '?') . '|' . ($teamId ?? '?');

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $previous = $this->previous[$key . '|' . ($teamId ?? '?')] ?? null;

        if ($previous !== null) {
            $this->fillMissing($previous, $swimmer, $teamId);

            return $this->resolved[$cacheKey] = $previous;
        }

        $candidates = $this->db->select(
            'SELECT id, birth_year, gender, team_id FROM swimmers WHERE normalized_key = :key',
            ['key' => $key]
        );

        $decision = self::decide($candidates, $swimmer->birthYear, $teamId);

        if ($decision['action'] === 'reuse') {
            $id = (int) $decision['id'];
            $this->fillMissing($id, $swimmer, $teamId);
        } else {
            $id = $this->create($swimmer, $teamId);

            foreach ($decision['review'] as $candidateId) {
                $this->queueCandidate($id, (int) $candidateId, (string) $decision['reason']);
            }
        }

        return $this->resolved[$cacheKey] = $id;
    }

    /**
     * 判定そのもの。DB に触れないので単体で検証できる。
     *
     * @param list<array<string, mixed>> $candidates 同じ normalized_key を持つ既存選手
     * @return array{action: 'reuse', id: int}|array{action: 'create', review: list<int>, reason: string}
     */
    public static function decide(array $candidates, ?int $birthYear, ?int $teamId): array
    {
        if ($candidates === []) {
            return ['action' => 'create', 'review' => [], 'reason' => ''];
        }

        // 生年まで一致するものが 1 人だけなら、同一人物として扱ってよい。
        if ($birthYear !== null) {
            $exact = array_values(array_filter(
                $candidates,
                static fn (array $c): bool => $c['birth_year'] !== null && (int) $c['birth_year'] === $birthYear
            ));

            if (count($exact) === 1) {
                return ['action' => 'reuse', 'id' => (int) $exact[0]['id']];
            }

            if (count($exact) > 1) {
                // 同姓同名・同生年が複数。自動では選べない。
                return [
                    'action' => 'create',
                    'review' => array_map(static fn (array $c): int => (int) $c['id'], $exact),
                    'reason' => '同姓同名・同生年の選手が複数います',
                ];
            }

            // 生年違いの既存だけが居る場合。生年未記入の既存が 1 人だけ、かつ
            // チームが同じなら、その子の生年が今回判明したと見なす。
            $unknown = array_values(array_filter(
                $candidates,
                static fn (array $c): bool => $c['birth_year'] === null
            ));

            if (count($unknown) === 1 && self::sameTeam($unknown[0], $teamId)) {
                return ['action' => 'reuse', 'id' => (int) $unknown[0]['id']];
            }

            if ($unknown !== []) {
                return [
                    'action' => 'create',
                    'review' => array_map(static fn (array $c): int => (int) $c['id'], $unknown),
                    'reason' => '名前は一致しますが生年を確認できません',
                ];
            }

            // 既存は全員別の生年 → 別人（同姓同名の別の子）。
            return ['action' => 'create', 'review' => [], 'reason' => ''];
        }

        // 今回の取り込みに生年が無い場合。候補が 1 人でチームも一致するなら
        // 同一人物と見なす。そうでなければ保留に回す。
        if (count($candidates) === 1 && self::sameTeam($candidates[0], $teamId)) {
            return ['action' => 'reuse', 'id' => (int) $candidates[0]['id']];
        }

        return [
            'action' => 'create',
            'review' => array_map(static fn (array $c): int => (int) $c['id'], $candidates),
            'reason' => '生年が無く、同名の選手と区別できません',
        ];
    }

    /** @param array<string, mixed> $candidate */
    private static function sameTeam(array $candidate, ?int $teamId): bool
    {
        if ($teamId === null || $candidate['team_id'] === null) {
            // どちらかのチームが不明なら「矛盾は無い」ものとして扱う。
            return true;
        }

        return (int) $candidate['team_id'] === $teamId;
    }

    private function create(ParsedSwimmer $swimmer, ?int $teamId): int
    {
        $now = Clock::utcNow();

        return $this->db->insert(
            'INSERT INTO swimmers'
            . ' (full_name, last_name, first_name, normalized_key, birth_year, gender, team_id, created_at, updated_at)'
            . ' VALUES (:full_name, :last_name, :first_name, :normalized_key, :birth_year, :gender, :team_id, :created_at, :updated_at)',
            [
                'full_name' => $swimmer->fullName(),
                'last_name' => $swimmer->lastName,
                'first_name' => $swimmer->firstName,
                'normalized_key' => $swimmer->normalizedKey(),
                'birth_year' => $swimmer->birthYear,
                'gender' => $swimmer->gender,
                'team_id' => $teamId,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * 既存の選手に、今回の取り込みで判明した値を埋める。
     * 既に入っている値は上書きしない（古いファイルの再取り込みで壊さない）。
     */
    private function fillMissing(int $id, ParsedSwimmer $swimmer, ?int $teamId): void
    {
        $this->db->execute(
            'UPDATE swimmers SET'
            . ' birth_year = COALESCE(birth_year, :birth_year),'
            . ' gender = COALESCE(gender, :gender),'
            . ' team_id = COALESCE(:team_id, team_id),'
            . ' updated_at = :updated_at'
            . ' WHERE id = :id',
            [
                'birth_year' => $swimmer->birthYear,
                'gender' => $swimmer->gender,
                // チームは移籍があるため、今回の値が分かっているなら新しいほうを採る。
                'team_id' => $teamId,
                'updated_at' => Clock::utcNow(),
                'id' => $id,
            ]
        );
    }

    private function queueCandidate(int $swimmerId, int $candidateId, string $reason): void
    {
        // 同じ組は 1 回だけ積む。既にあれば黙って何もしない。
        $this->db->execute(
            'INSERT IGNORE INTO swimmer_merge_candidates (swimmer_id, candidate_id, reason, status, created_at)'
            . ' VALUES (:swimmer_id, :candidate_id, :reason, :status, :created_at)',
            [
                'swimmer_id' => $swimmerId,
                'candidate_id' => $candidateId,
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => Clock::utcNow(),
            ]
        );
    }
}
