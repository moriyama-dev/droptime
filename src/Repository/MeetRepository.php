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

    /**
     * 大会のセッション（開始時刻つき）と、その中の種目の並び。カウントダウンの見積もりに使う。
     *
     * @return list<array{id: int, code: ?string, date: ?string, start_time: ?string,
     *                    events: list<array{event_number: string, round: string, distance: int,
     *                                       heat_count: int, entry_count: int, result_count: int,
     *                                       times: list<array{heat: ?int, cs: ?int}>}>}>
     *         times は所要時間の見積もりに使うタイム。エントリータイム（タイムなしは null）、
     *         エントリーの無い種目（決勝など）は結果のタイム。
     */
    public function sessionsOf(int $meetId): array
    {
        $sessions = [];

        foreach ($this->db->select(
            'SELECT id, code, session_date, start_time FROM meet_sessions WHERE meet_id = :meet_id ORDER BY sort_order, id',
            ['meet_id' => $meetId]
        ) as $row) {
            $sessions[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'code' => $row['code'] === null ? null : (string) $row['code'],
                'date' => $row['session_date'] === null ? null : (string) $row['session_date'],
                'start_time' => $row['start_time'] === null ? null : (string) $row['start_time'],
                'events' => [],
            ];
        }

        if ($sessions === []) {
            return [];
        }

        $times = [];

        foreach ($this->db->select(
            'SELECT en.event_id, en.heat, en.seed_time_cs, en.seed_is_ntime FROM entries en'
            . ' JOIN events e ON e.id = en.event_id'
            . ' WHERE e.meet_id = :meet_id AND e.meet_session_id IS NOT NULL',
            ['meet_id' => $meetId]
        ) as $row) {
            $times[(int) $row['event_id']][] = [
                'heat' => $row['heat'] === null ? null : (int) $row['heat'],
                'cs' => (int) $row['seed_is_ntime'] === 1 || $row['seed_time_cs'] === null ? null : (int) $row['seed_time_cs'],
            ];
        }

        foreach ($this->db->select(
            'SELECT r.event_id, r.heat, r.final_time_cs FROM results r'
            . ' JOIN events e ON e.id = r.event_id'
            . ' WHERE e.meet_id = :meet_id AND e.meet_session_id IS NOT NULL'
            . ' AND NOT EXISTS (SELECT 1 FROM entries en WHERE en.event_id = e.id)',
            ['meet_id' => $meetId]
        ) as $row) {
            $times[(int) $row['event_id']][] = [
                'heat' => $row['heat'] === null ? null : (int) $row['heat'],
                'cs' => $row['final_time_cs'] === null ? null : (int) $row['final_time_cs'],
            ];
        }

        foreach ($this->db->select(
            'SELECT e.id, e.meet_session_id, e.event_number, e.round, e.distance,'
            . ' (SELECT COUNT(DISTINCT en.heat) FROM entries en WHERE en.event_id = e.id) AS heat_count,'
            . ' (SELECT COUNT(*) FROM entries en WHERE en.event_id = e.id) AS entry_count,'
            . ' (SELECT COUNT(*) FROM results r WHERE r.event_id = e.id) AS result_count'
            . ' FROM events e WHERE e.meet_id = :meet_id AND e.meet_session_id IS NOT NULL'
            . ' ORDER BY e.meet_session_id, e.session_position',
            ['meet_id' => $meetId]
        ) as $row) {
            $sessionId = (int) $row['meet_session_id'];

            if (!isset($sessions[$sessionId])) {
                continue;
            }

            $sessions[$sessionId]['events'][] = [
                'event_number' => (string) $row['event_number'],
                'round' => (string) $row['round'],
                'distance' => (int) $row['distance'],
                'heat_count' => (int) $row['heat_count'],
                'entry_count' => (int) $row['entry_count'],
                'result_count' => (int) $row['result_count'],
                'times' => $times[(int) $row['id']] ?? [],
            ];
        }

        return array_values($sessions);
    }

    /**
     * 速報サイトで見つけた、終わった種目とその時刻（本番の cron が書く）。新しい順。
     *
     * @return list<array{event_number: string, round: string, finished_at: string}>
     */
    public function liveStatusOf(int $meetId): array
    {
        return array_map(
            static fn (array $row): array => [
                'event_number' => (string) $row['event_number'],
                'round' => (string) $row['round'],
                'finished_at' => (string) $row['finished_at'],
            ],
            $this->db->select(
                'SELECT event_number, round, finished_at FROM live_event_status WHERE meet_id = :meet_id ORDER BY finished_at DESC',
                ['meet_id' => $meetId]
            )
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
