<?php

declare(strict_types=1);

namespace DropTime\Import;

use DropTime\Db\Database;
use DropTime\Support\Clock;

/**
 * imports テーブルの読み書き。状態遷移はすべてここを通す。
 *
 * 状態: uploaded → extracted → parsed → committed
 *       途中の失敗は failed、人が捨てたら discarded。
 */
final class ImportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $summary */
    public function create(UploadedFile $file, string $rawRelative): int
    {
        return $this->db->insert(
            'INSERT INTO imports (original_name, raw_path, sha256, byte_size, mime, status, uploaded_at)'
            . ' VALUES (:original_name, :raw_path, :sha256, :byte_size, :mime, :status, :uploaded_at)',
            [
                'original_name' => $file->originalName,
                'raw_path' => $rawRelative,
                'sha256' => $file->sha256,
                'byte_size' => $file->size,
                'mime' => $file->mime,
                'status' => 'uploaded',
                'uploaded_at' => Clock::utcNow(),
            ]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM imports WHERE id = :id', ['id' => $id]);
    }

    /**
     * 同じ中身のファイルが既に取り込まれていないか。捨てた分は数えない。
     *
     * @return array<string, mixed>|null
     */
    public function findDuplicate(string $sha256, int $excludeId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM imports WHERE sha256 = :sha256 AND id <> :id AND status <> :discarded'
            . ' ORDER BY id DESC LIMIT 1',
            ['sha256' => $sha256, 'id' => $excludeId, 'discarded' => 'discarded']
        );
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->select(
            'SELECT i.*, m.name AS meet_name FROM imports i'
            . ' LEFT JOIN meets m ON m.id = i.meet_id'
            . ' ORDER BY i.id DESC LIMIT ' . max(1, $limit)
        );
    }

    public function markExtracted(int $id, string $textRelative): void
    {
        $this->db->execute(
            'UPDATE imports SET status = :status, text_path = :text_path WHERE id = :id',
            ['status' => 'extracted', 'text_path' => $textRelative, 'id' => $id]
        );
    }

    /** @param array<string, mixed> $summary */
    public function markParsed(int $id, string $parser, array $summary): void
    {
        $this->db->execute(
            'UPDATE imports SET status = :status, parser = :parser, summary_json = :summary WHERE id = :id',
            [
                'status' => 'parsed',
                'parser' => $parser,
                'summary' => self::encode($summary),
                'id' => $id,
            ]
        );
    }

    /**
     * summary_json に値を追記する（状態は変えない）。出典 URL の記録などに使う。
     *
     * @param array<string, mixed> $extra
     */
    public function mergeSummary(int $id, array $extra): void
    {
        $row = $this->find($id);

        if ($row === null) {
            return;
        }

        $summary = array_merge(self::summaryOf($row), $extra);

        $this->db->execute(
            'UPDATE imports SET summary_json = :summary WHERE id = :id',
            ['summary' => self::encode($summary), 'id' => $id]
        );
    }

    public function markCommitted(int $id, int $meetId): void
    {
        $this->db->execute(
            'UPDATE imports SET status = :status, meet_id = :meet_id, committed_at = :committed_at WHERE id = :id',
            [
                'status' => 'committed',
                'meet_id' => $meetId,
                'committed_at' => Clock::utcNow(),
                'id' => $id,
            ]
        );
    }

    public function markFailed(int $id, string $reason): void
    {
        $this->db->execute(
            'UPDATE imports SET status = :status, summary_json = :summary WHERE id = :id',
            ['status' => 'failed', 'summary' => self::encode(['error' => $reason]), 'id' => $id]
        );
    }

    public function markDiscarded(int $id): void
    {
        $this->db->execute(
            'UPDATE imports SET status = :status WHERE id = :id',
            ['status' => 'discarded', 'id' => $id]
        );
    }

    /**
     * summary_json を配列に戻す。壊れていたら空配列（画面には出さないだけで済ませる）。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function summaryOf(array $row): array
    {
        $json = $row['summary_json'] ?? null;

        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $summary */
    private static function encode(array $summary): string
    {
        return (string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
