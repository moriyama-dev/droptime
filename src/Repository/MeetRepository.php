<?php

declare(strict_types=1);

namespace DropTime\Repository;

use DropTime\Db\Database;

/**
 * 大会・種目・エントリー・結果の読み取り。保護者向け画面はここだけを使う。
 * 書き込みは取り込み（Import）側の仕事で、ここには置かない。
 */
final class MeetRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            'SELECT m.*,'
            . ' (SELECT COUNT(*) FROM events e WHERE e.meet_id = m.id) AS event_count,'
            . ' (SELECT COUNT(*) FROM results r JOIN events e ON e.id = r.event_id WHERE e.meet_id = m.id) AS result_count'
            . ' FROM meets m ORDER BY m.start_date DESC, m.id DESC'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM meets WHERE id = :id', ['id' => $id]);
    }

    /**
     * プログラム（種目一覧）。組数と結果の有無も一緒に返す。
     *
     * @return list<array<string, mixed>>
     */
    public function programOf(int $meetId): array
    {
        return $this->db->select(
            'SELECT e.*,'
            . ' (SELECT COUNT(DISTINCT en.heat) FROM entries en WHERE en.event_id = e.id) AS heat_count,'
            . ' (SELECT COUNT(*) FROM entries en WHERE en.event_id = e.id) AS entry_count,'
            . ' (SELECT COUNT(*) FROM results r WHERE r.event_id = e.id) AS result_count'
            . ' FROM events e WHERE e.meet_id = :meet_id'
            . ' ORDER BY e.sort_order, e.id',
            ['meet_id' => $meetId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findEvent(int $meetId, int $eventId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM events WHERE id = :id AND meet_id = :meet_id',
            ['id' => $eventId, 'meet_id' => $meetId]
        );
    }

    /**
     * 種目のエントリー。組・レーン順。
     *
     * @return list<array<string, mixed>>
     */
    public function entriesOf(int $eventId): array
    {
        return $this->db->select(
            'SELECT en.*, s.full_name AS swimmer_name, s.id AS swimmer_id, t.name AS team_name, t.code AS team_code'
            . ' FROM entries en'
            . ' LEFT JOIN swimmers s ON s.id = en.swimmer_id'
            . ' LEFT JOIN teams t ON t.id = en.team_id'
            . ' WHERE en.event_id = :event_id'
            . ' ORDER BY en.heat IS NULL, en.heat, en.lane IS NULL, en.lane',
            ['event_id' => $eventId]
        );
    }

    /**
     * 種目の結果。順位順（順位なしは後ろ）。スプリットは splitsFor でまとめて引く。
     *
     * @return list<array<string, mixed>>
     */
    public function resultsOf(int $eventId): array
    {
        return $this->db->select(
            'SELECT r.*, s.full_name AS swimmer_name, s.id AS swimmer_id, t.name AS team_name, t.code AS team_code'
            . ' FROM results r'
            . ' LEFT JOIN swimmers s ON s.id = r.swimmer_id'
            . ' LEFT JOIN teams t ON t.id = r.team_id'
            . ' WHERE r.event_id = :event_id'
            . ' ORDER BY r.place IS NULL, r.place, r.final_time_cs IS NULL, r.final_time_cs',
            ['event_id' => $eventId]
        );
    }

    /**
     * 複数の結果のスプリットをまとめて引く。
     *
     * @param list<int> $resultIds
     * @return array<int, list<array<string, mixed>>> result_id => スプリットの列
     */
    public function splitsFor(array $resultIds): array
    {
        if ($resultIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($resultIds), '?'));

        $rows = $this->db->select(
            'SELECT * FROM splits WHERE result_id IN (' . $placeholders . ') ORDER BY result_id, distance',
            array_values($resultIds)
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['result_id']][] = $row;
        }

        return $grouped;
    }
}
