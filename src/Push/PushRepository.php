<?php

declare(strict_types=1);

namespace DropTime\Push;

use DropTime\Db\Database;
use DropTime\Support\Clock;

/** 通知の購読と、送った通知の記録。 */
final class PushRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** 購読を保存する。同じ端末（endpoint）なら入れ物・鍵・言語を新しくする。 */
    public function save(int $profileId, string $endpoint, string $p256dh, string $auth, string $lang): int
    {
        $hash = hash('sha256', $endpoint);

        $this->db->execute(
            'INSERT INTO push_subscriptions (profile_id, endpoint_hash, endpoint, p256dh, auth, lang, created_at)'
            . ' VALUES (:profile_id, :hash, :endpoint, :p256dh, :auth, :lang, :now)'
            . ' ON DUPLICATE KEY UPDATE profile_id = VALUES(profile_id), p256dh = VALUES(p256dh), auth = VALUES(auth), lang = VALUES(lang)',
            ['profile_id' => $profileId, 'hash' => $hash, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'lang' => $lang, 'now' => Clock::utcNow()]
        );

        return (int) $this->db->selectValue('SELECT id FROM push_subscriptions WHERE endpoint_hash = :hash', ['hash' => $hash]);
    }

    public function deleteByEndpoint(string $endpoint): void
    {
        $this->db->execute('DELETE FROM push_subscriptions WHERE endpoint_hash = :hash', ['hash' => hash('sha256', $endpoint)]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM push_subscriptions WHERE id = :id', ['id' => $id]);
    }

    /** @return ?array<string, mixed> */
    public function findByEndpoint(string $endpoint): ?array
    {
        return $this->db->selectOne('SELECT * FROM push_subscriptions WHERE endpoint_hash = :hash', ['hash' => hash('sha256', $endpoint)]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM push_subscriptions ORDER BY profile_id, id');
    }

    /** @param list<string> $keys @return list<string> 送り済みのもの */
    public function sentKeys(int $subscriptionId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $params = ['id' => $subscriptionId];
        $holders = [];

        foreach (array_values($keys) as $i => $key) {
            $params['k' . $i] = $key;
            $holders[] = ':k' . $i;
        }

        $rows = $this->db->select(
            'SELECT notice_key FROM push_sent WHERE subscription_id = :id AND notice_key IN (' . implode(', ', $holders) . ')',
            $params
        );

        return array_map(static fn (array $row): string => (string) $row['notice_key'], $rows);
    }

    /** @param list<string> $keys */
    public function markSent(int $subscriptionId, array $keys): void
    {
        $now = Clock::utcNow();

        foreach ($keys as $key) {
            $this->db->execute(
                'INSERT IGNORE INTO push_sent (subscription_id, notice_key, sent_at) VALUES (:id, :key, :now)',
                ['id' => $subscriptionId, 'key' => $key, 'now' => $now]
            );
        }

        $this->db->execute('UPDATE push_subscriptions SET last_sent_at = :now WHERE id = :id', ['now' => $now, 'id' => $subscriptionId]);
    }

    /** 古い記録を消す（2 週間より前）。 */
    public function prune(): void
    {
        $this->db->execute('DELETE FROM push_sent WHERE sent_at < :before', ['before' => gmdate('Y-m-d H:i:s', Clock::timestamp() - 14 * 86400)]);
    }
}
