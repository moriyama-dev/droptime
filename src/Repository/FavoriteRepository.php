<?php

declare(strict_types=1);

namespace DropTime\Repository;

use DropTime\Db\Database;
use DropTime\Support\Clock;

/**
 * 保護者のお気に入りと、その入れ物（プロフィール）。
 *
 * プロフィールは合言葉とは別の軽い識別で、引き継ぎコードの SHA-256 だけを
 * 持つ。個人アカウントではないので、氏名やメールは持たない。
 */
final class FavoriteRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function createProfile(): int
    {
        $now = Clock::utcNow();

        // key_hash は発行までの間、推測不能なダミーで埋めておく（UNIQUE のため）。
        return $this->db->insert(
            'INSERT INTO profiles (key_hash, created_at, last_seen_at) VALUES (:key_hash, :created_at, :last_seen_at)',
            ['key_hash' => hash('sha256', 'unissued:' . bin2hex(random_bytes(32))), 'created_at' => $now, 'last_seen_at' => $now]
        );
    }

    public function profileExists(int $id): bool
    {
        return $this->db->selectValue('SELECT 1 FROM profiles WHERE id = :id', ['id' => $id]) !== null;
    }

    public function findProfileByKeyHash(string $keyHash): ?int
    {
        $id = $this->db->selectValue('SELECT id FROM profiles WHERE key_hash = :key_hash', ['key_hash' => $keyHash]);

        return $id === null ? null : (int) $id;
    }

    /** 引き継ぎコードを差し替える。古いコードはその時点で無効になる。 */
    public function setKeyHash(int $profileId, string $keyHash): void
    {
        $this->db->execute(
            'UPDATE profiles SET key_hash = :key_hash WHERE id = :id',
            ['key_hash' => $keyHash, 'id' => $profileId]
        );
    }

    public function touch(int $profileId): void
    {
        $this->db->execute(
            'UPDATE profiles SET last_seen_at = :now WHERE id = :id',
            ['now' => Clock::utcNow(), 'id' => $profileId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function favoritesOf(int $profileId): array
    {
        return $this->db->select(
            'SELECT s.*, t.name AS team_name FROM favorites f'
            . ' JOIN swimmers s ON s.id = f.swimmer_id'
            . ' LEFT JOIN teams t ON t.id = s.team_id'
            . ' WHERE f.profile_id = :profile_id'
            . ' ORDER BY s.last_name, s.first_name',
            ['profile_id' => $profileId]
        );
    }

    /** @return list<int> */
    public function favoriteIdsOf(int $profileId): array
    {
        $rows = $this->db->select(
            'SELECT swimmer_id FROM favorites WHERE profile_id = :profile_id',
            ['profile_id' => $profileId]
        );

        return array_map(static fn (array $row): int => (int) $row['swimmer_id'], $rows);
    }

    public function add(int $profileId, int $swimmerId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO favorites (profile_id, swimmer_id, created_at) VALUES (:profile_id, :swimmer_id, :created_at)',
            ['profile_id' => $profileId, 'swimmer_id' => $swimmerId, 'created_at' => Clock::utcNow()]
        );
    }

    public function remove(int $profileId, int $swimmerId): void
    {
        $this->db->execute(
            'DELETE FROM favorites WHERE profile_id = :profile_id AND swimmer_id = :swimmer_id',
            ['profile_id' => $profileId, 'swimmer_id' => $swimmerId]
        );
    }

    /**
     * お気に入り選手の「これから泳ぐ」エントリー。
     * 終わっていない大会（終了日または開始日が今日以降）のものだけを返す。
     *
     * @return list<array<string, mixed>>
     */
    public function upcomingEntries(int $profileId, string $todayIso): array
    {
        return $this->db->select(
            'SELECT en.heat, en.lane, en.seed_time_cs, en.seed_is_ntime,'
            . ' s.id AS swimmer_id, s.full_name AS swimmer_name,'
            . ' e.id AS event_id, e.event_number, e.round, e.gender, e.age_group, e.distance, e.stroke,'
            . ' e.course, e.is_relay, e.sort_order, e.scheduled_time,'
            . ' m.id AS meet_id, m.name AS meet_name, m.start_date, m.end_date, m.venue'
            . ' FROM favorites f'
            . ' JOIN entries en ON en.swimmer_id = f.swimmer_id'
            . ' JOIN swimmers s ON s.id = f.swimmer_id'
            . ' JOIN events e ON e.id = en.event_id'
            . ' JOIN meets m ON m.id = e.meet_id'
            . ' WHERE f.profile_id = :profile_id'
            . ' AND COALESCE(m.end_date, m.start_date) >= :today'
            . ' ORDER BY m.start_date, e.sort_order, en.heat, en.lane',
            ['profile_id' => $profileId, 'today' => $todayIso]
        );
    }

    /**
     * お気に入り選手の最近の結果。ホームの下段に出す。
     *
     * @return list<array<string, mixed>>
     */
    public function recentResults(int $profileId, int $limit = 10): array
    {
        return $this->db->select(
            'SELECT r.final_time_cs, r.place, r.status,'
            . ' s.id AS swimmer_id, s.full_name AS swimmer_name,'
            . ' e.id AS event_id, e.event_number, e.round, e.gender, e.age_group, e.distance, e.stroke, e.course, e.is_relay,'
            . ' m.id AS meet_id, m.name AS meet_name, m.start_date'
            . ' FROM favorites f'
            . ' JOIN results r ON r.swimmer_id = f.swimmer_id'
            . ' JOIN swimmers s ON s.id = f.swimmer_id'
            . ' JOIN events e ON e.id = r.event_id'
            . ' JOIN meets m ON m.id = e.meet_id'
            . ' WHERE f.profile_id = :profile_id'
            . ' ORDER BY m.start_date DESC, e.sort_order DESC'
            . ' LIMIT ' . max(1, $limit),
            ['profile_id' => $profileId]
        );
    }
}
