<?php

declare(strict_types=1);

namespace DropTime\Db;

use DropTime\Support\Clock;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * セッションを MySQL に保存する。
 *
 * PHP 既定のファイルセッションは session.gc_maxlifetime と共有ホスティングの
 * /tmp 掃除に左右されるため、90 日の保持を約束できない。保持期間を自分で
 * 制御するために DB に置く。
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface, SessionIdInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly int $lifetimeSeconds,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function read(string $id): string
    {
        $row = $this->db->selectOne(
            'SELECT payload FROM auth_sessions WHERE id = :id AND last_activity >= :threshold',
            ['id' => $id, 'threshold' => Clock::timestamp() - $this->lifetimeSeconds]
        );

        if ($row === null) {
            return '';
        }

        $payload = $row['payload'];

        return is_string($payload) ? $payload : '';
    }

    public function write(string $id, string $data): bool
    {
        $now = Clock::timestamp();

        $this->db->execute(
            'INSERT INTO auth_sessions (id, payload, created_at, last_activity)'
            . ' VALUES (:id, :payload, :created_at, :last_activity)'
            . ' ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_activity = VALUES(last_activity)',
            ['id' => $id, 'payload' => $data, 'created_at' => $now, 'last_activity' => $now]
        );

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->execute('DELETE FROM auth_sessions WHERE id = :id', ['id' => $id]);

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        // 引数ではなく設定の保持期間を使う。php.ini の値に左右させない。
        return $this->db->execute(
            'DELETE FROM auth_sessions WHERE last_activity < :threshold',
            ['threshold' => Clock::timestamp() - $this->lifetimeSeconds]
        );
    }

    public function validateId(string $id): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            return false;
        }

        return $this->read($id) !== '';
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->db->execute(
            'UPDATE auth_sessions SET last_activity = :now WHERE id = :id',
            ['now' => Clock::timestamp(), 'id' => $id]
        );

        return true;
    }
}
