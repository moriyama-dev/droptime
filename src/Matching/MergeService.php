<?php

declare(strict_types=1);

namespace DropTime\Matching;

use DropTime\Db\Database;
use DropTime\Import\ImportException;
use DropTime\Support\Clock;

/**
 * 選手の統合と、その取り消し。
 *
 * 誤って別人を統合すると記録が壊れて発見しにくい。そのため統合時に
 *   - 消した選手の行そのもの（スナップショット）
 *   - 付け替えた行の ID（entries / results / favorites）
 * を swimmer_merges に JSON で保存し、そのまま巻き戻せるようにしている。
 */
final class MergeService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * remove 側の選手を keep 側へ統合する。統合記録の ID を返す。
     */
    public function merge(int $keepId, int $removeId): int
    {
        if ($keepId === $removeId) {
            throw new ImportException('同じ選手同士は統合できません。');
        }

        /** @var int $mergeId */
        $mergeId = $this->db->transaction(function (Database $db) use ($keepId, $removeId): int {
            $keep = $db->selectOne('SELECT * FROM swimmers WHERE id = :id', ['id' => $keepId]);
            $removed = $db->selectOne('SELECT * FROM swimmers WHERE id = :id', ['id' => $removeId]);

            if ($keep === null || $removed === null) {
                throw new ImportException('統合対象の選手が見つかりません。');
            }

            $moved = [
                'entries' => self::ids($db->select('SELECT id FROM entries WHERE swimmer_id = :id', ['id' => $removeId])),
                'results' => self::ids($db->select('SELECT id FROM results WHERE swimmer_id = :id', ['id' => $removeId])),
                'favorites_moved' => [],
                'favorites_deleted' => [],
                // keep 側が統合で得た属性を戻せるよう、統合前の値も残す。
                'kept_before' => [
                    'birth_year' => $keep['birth_year'],
                    'gender' => $keep['gender'],
                    'team_id' => $keep['team_id'],
                ],
            ];

            $db->execute('UPDATE entries SET swimmer_id = :keep WHERE swimmer_id = :remove', ['keep' => $keepId, 'remove' => $removeId]);
            $db->execute('UPDATE results SET swimmer_id = :keep WHERE swimmer_id = :remove', ['keep' => $keepId, 'remove' => $removeId]);

            // お気に入りは (profile, swimmer) が一意。keep 側を既に登録している
            // プロフィールの分は移せないので消し、取り消しに備えて記録する。
            foreach ($db->select('SELECT id, profile_id FROM favorites WHERE swimmer_id = :id', ['id' => $removeId]) as $favorite) {
                $duplicate = $db->selectValue(
                    'SELECT 1 FROM favorites WHERE profile_id = :profile AND swimmer_id = :keep',
                    ['profile' => (int) $favorite['profile_id'], 'keep' => $keepId]
                );

                if ($duplicate !== null) {
                    $db->execute('DELETE FROM favorites WHERE id = :id', ['id' => (int) $favorite['id']]);
                    $moved['favorites_deleted'][] = (int) $favorite['profile_id'];
                } else {
                    $db->execute('UPDATE favorites SET swimmer_id = :keep WHERE id = :id', ['keep' => $keepId, 'id' => (int) $favorite['id']]);
                    $moved['favorites_moved'][] = (int) $favorite['id'];
                }
            }

            // keep 側に欠けている属性は removed 側から補う。
            $db->execute(
                'UPDATE swimmers SET'
                . ' birth_year = COALESCE(birth_year, :birth_year),'
                . ' gender = COALESCE(gender, :gender),'
                . ' team_id = COALESCE(team_id, :team_id),'
                . ' updated_at = :now'
                . ' WHERE id = :id',
                [
                    'birth_year' => $removed['birth_year'],
                    'gender' => $removed['gender'],
                    'team_id' => $removed['team_id'],
                    'now' => Clock::utcNow(),
                    'id' => $keepId,
                ]
            );

            // removed 側を消す（関係する統合候補は CASCADE で消える）。
            $db->execute('DELETE FROM swimmers WHERE id = :id', ['id' => $removeId]);

            return $db->insert(
                'INSERT INTO swimmer_merges (kept_id, removed_id, removed_json, moved_json, merged_at)'
                . ' VALUES (:kept, :removed, :removed_json, :moved_json, :at)',
                [
                    'kept' => $keepId,
                    'removed' => $removeId,
                    'removed_json' => (string) json_encode($removed, JSON_UNESCAPED_UNICODE),
                    'moved_json' => (string) json_encode($moved, JSON_UNESCAPED_UNICODE),
                    'at' => Clock::utcNow(),
                ]
            );
        });

        return $mergeId;
    }

    /**
     * 統合を巻き戻す。消した選手を同じ ID で復元し、移した行を戻す。
     */
    public function undo(int $mergeId): void
    {
        $this->db->transaction(function (Database $db) use ($mergeId): void {
            $merge = $db->selectOne('SELECT * FROM swimmer_merges WHERE id = :id', ['id' => $mergeId]);

            if ($merge === null) {
                throw new ImportException('統合の記録が見つかりません。');
            }

            if ($merge['undone_at'] !== null) {
                throw new ImportException('この統合は既に取り消されています。');
            }

            /** @var array<string, mixed>|null $removed */
            $removed = json_decode((string) $merge['removed_json'], true);
            /** @var array<string, mixed>|null $moved */
            $moved = json_decode((string) $merge['moved_json'], true);

            if (!is_array($removed) || !is_array($moved)) {
                throw new ImportException('統合の記録が壊れています。');
            }

            $removedId = (int) $merge['removed_id'];

            // 同じ ID で復元する。付け替えた行の ID がそのまま戻せる。
            $db->execute(
                'INSERT INTO swimmers (id, full_name, last_name, first_name, normalized_key, birth_year, gender, team_id, created_at, updated_at)'
                . ' VALUES (:id, :full_name, :last_name, :first_name, :normalized_key, :birth_year, :gender, :team_id, :created_at, :updated_at)',
                [
                    'id' => $removedId,
                    'full_name' => $removed['full_name'],
                    'last_name' => $removed['last_name'],
                    'first_name' => $removed['first_name'],
                    'normalized_key' => $removed['normalized_key'],
                    'birth_year' => $removed['birth_year'],
                    'gender' => $removed['gender'],
                    'team_id' => $removed['team_id'],
                    'created_at' => $removed['created_at'],
                    'updated_at' => Clock::utcNow(),
                ]
            );

            foreach (['entries', 'results'] as $table) {
                $ids = is_array($moved[$table] ?? null) ? $moved[$table] : [];

                foreach ($ids as $id) {
                    $db->execute(
                        'UPDATE ' . $table . ' SET swimmer_id = :swimmer WHERE id = :id AND swimmer_id = :kept',
                        ['swimmer' => $removedId, 'id' => (int) $id, 'kept' => (int) $merge['kept_id']]
                    );
                }
            }

            foreach (is_array($moved['favorites_moved'] ?? null) ? $moved['favorites_moved'] : [] as $id) {
                $db->execute(
                    'UPDATE favorites SET swimmer_id = :swimmer WHERE id = :id',
                    ['swimmer' => $removedId, 'id' => (int) $id]
                );
            }

            foreach (is_array($moved['favorites_deleted'] ?? null) ? $moved['favorites_deleted'] : [] as $profileId) {
                $db->execute(
                    'INSERT IGNORE INTO favorites (profile_id, swimmer_id, created_at) VALUES (:profile, :swimmer, :at)',
                    ['profile' => (int) $profileId, 'swimmer' => $removedId, 'at' => Clock::utcNow()]
                );
            }

            // keep 側が統合で得た属性を統合前の値に戻す。
            if (is_array($moved['kept_before'] ?? null)) {
                $db->execute(
                    'UPDATE swimmers SET birth_year = :birth_year, gender = :gender, team_id = :team_id,'
                    . ' updated_at = :now WHERE id = :id',
                    [
                        'birth_year' => $moved['kept_before']['birth_year'],
                        'gender' => $moved['kept_before']['gender'],
                        'team_id' => $moved['kept_before']['team_id'],
                        'now' => Clock::utcNow(),
                        'id' => (int) $merge['kept_id'],
                    ]
                );
            }

            // もう一度人間が判定できるよう、候補として積み直す。
            $db->execute(
                'INSERT IGNORE INTO swimmer_merge_candidates (swimmer_id, candidate_id, reason, status, created_at)'
                . ' VALUES (:swimmer, :candidate, :reason, :status, :at)',
                [
                    'swimmer' => $removedId,
                    'candidate' => (int) $merge['kept_id'],
                    'reason' => '統合の取り消しで復元されました',
                    'status' => 'pending',
                    'at' => Clock::utcNow(),
                ]
            );

            $db->execute(
                'UPDATE swimmer_merges SET undone_at = :at WHERE id = :id',
                ['at' => Clock::utcNow(), 'id' => $mergeId]
            );
        });
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
