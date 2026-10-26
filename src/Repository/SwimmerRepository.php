<?php

declare(strict_types=1);

namespace DropTime\Repository;

use DropTime\Db\Database;
use DropTime\Support\Str;

/**
 * 選手の読み取り。検索は PHP 側で正規化した文字列を normalized_key に
 * 当てるので、照合順序に依存せずアクセントや大文字小文字のゆれを吸収できる。
 */
final class SwimmerRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT s.*, t.name AS team_name, t.code AS team_code'
            . ' FROM swimmers s LEFT JOIN teams t ON t.id = s.team_id'
            . ' WHERE s.id = :id',
            ['id' => $id]
        );
    }

    /**
     * 名前で検索する。入力を正規化してから前方・部分一致で探す。
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 30): array
    {
        $normalized = Str::normalizeName($query);

        if ($normalized === '') {
            return [];
        }

        // LIKE のメタ文字はデータではなく検索語なのでエスケープする。
        $escaped = addcslashes($normalized, '%_\\');

        return $this->db->select(
            'SELECT s.*, t.name AS team_name FROM swimmers s'
            . ' LEFT JOIN teams t ON t.id = s.team_id'
            . ' WHERE s.normalized_key LIKE :pattern'
            . ' ORDER BY s.last_name, s.first_name, s.id'
            . ' LIMIT ' . max(1, $limit),
            ['pattern' => '%' . $escaped . '%']
        );
    }

    /**
     * 選手の全記録。大会日の新しい順 → 種目順。タイム推移の計算にも使う。
     *
     * @return list<array<string, mixed>>
     */
    public function resultsOf(int $swimmerId): array
    {
        return $this->db->select(
            'SELECT r.id AS result_id, r.final_time_cs, r.place, r.status, r.dq_reason, r.heat, r.lane, r.age,'
            . ' e.id AS event_id, e.event_number, e.round, e.gender, e.distance, e.stroke, e.course, e.is_relay, e.age_group, e.sort_order,'
            . ' m.id AS meet_id, m.name AS meet_name, m.start_date'
            . ' FROM results r'
            . ' JOIN events e ON e.id = r.event_id'
            . ' JOIN meets m ON m.id = e.meet_id'
            . ' WHERE r.swimmer_id = :swimmer_id'
            . ' ORDER BY m.start_date DESC, e.sort_order DESC',
            ['swimmer_id' => $swimmerId]
        );
    }

    /**
     * 指定した種目に出た全員の結果。順位を数え直すのに使う。
     *
     * 1 種目あたり最大でも 150 行ほど（実データの最大は 133 人）なので、
     * 選手 1 人分の種目をまとめて 1 回で引く。種目ごとに問い合わせると
     * 17 レース × 1 クエリになり、共有ホスティングでは体感できるほど遅い。
     *
     * @param list<int> $eventIds
     * @return array<int, list<array<string, mixed>>> 種目 ID => その種目の全結果
     */
    public function fieldsOf(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));

        if ($eventIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

        $rows = $this->db->select(
            'SELECT r.event_id, r.swimmer_id, r.final_time_cs, r.status, r.age,'
            . ' s.full_name, t.code AS team_code'
            . ' FROM results r'
            . ' LEFT JOIN swimmers s ON s.id = r.swimmer_id'
            . ' LEFT JOIN teams t ON t.id = r.team_id'
            . ' WHERE r.event_id IN (' . $placeholders . ')'
            . ' ORDER BY r.event_id, r.final_time_cs',
            $eventIds
        );

        $fields = array_fill_keys($eventIds, []);

        foreach ($rows as $row) {
            $fields[(int) $row['event_id']][] = $row;
        }

        return $fields;
    }

    /**
     * あるクラブに所属する選手の個人種目の全結果。クラブ内比較に使う。
     * 性別で絞る（同じクラブでも男女は別に比べる）。リレーは含めない。
     *
     * @return list<array<string, mixed>>
     */
    public function clubResults(int $teamId, ?string $gender): array
    {
        $params = ['team_id' => $teamId];
        $genderClause = '';

        if ($gender !== null && $gender !== '') {
            $genderClause = ' AND s.gender = :gender';
            $params['gender'] = $gender;
        }

        return $this->db->select(
            'SELECT r.swimmer_id, s.full_name, s.birth_year, s.gender,'
            . ' r.final_time_cs, r.status, r.age,'
            . ' e.id AS event_id, e.round, e.distance, e.stroke, e.course, e.is_relay, e.sort_order,'
            . ' m.id AS meet_id, m.name AS meet_name, m.start_date'
            . ' FROM results r'
            . ' JOIN swimmers s ON s.id = r.swimmer_id'
            . ' JOIN events e ON e.id = r.event_id'
            . ' JOIN meets m ON m.id = e.meet_id'
            . ' WHERE s.team_id = :team_id AND e.is_relay = 0' . $genderClause
            . ' ORDER BY r.swimmer_id, m.start_date, e.sort_order',
            $params
        );
    }
}
