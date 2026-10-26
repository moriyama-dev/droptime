<?php

declare(strict_types=1);

namespace DropTime\Matching;

use DropTime\Db\Database;
use DropTime\Support\Clock;
use DropTime\Support\Str;

/**
 * チームの突き合わせ。コードがあればコードで、無ければ正規化した名前で探す。
 * 見つからなければ作る。選手ほど慎重にしないのは、チームは数が少なく
 * 誤結合してもデータが壊れない（表示のグルーピングにしか使わない）ため。
 */
final class TeamResolver
{
    /** @var array<string, int> */
    private array $resolved = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function resolve(?string $code, ?string $name): ?int
    {
        $code = self::cleanCode($code);
        $name = $name !== null ? Str::collapseSpaces($name) : null;

        if ($code === null && ($name === null || $name === '')) {
            return null;
        }

        $cacheKey = ($code ?? '') . '|' . ($name ?? '');

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $id = $this->find($code, $name) ?? $this->create($code, $name);

        return $this->resolved[$cacheKey] = $id;
    }

    private function find(?string $code, ?string $name): ?int
    {
        if ($code !== null) {
            $row = $this->db->selectOne('SELECT id, name FROM teams WHERE code = :code', ['code' => $code]);

            if ($row !== null) {
                // コード一致で見つけた場合、名前がコードのままなら正式名称で埋める。
                if ($name !== null && $name !== '' && $row['name'] === $code) {
                    $this->db->execute(
                        'UPDATE teams SET name = :name, normalized_key = :key WHERE id = :id',
                        ['name' => $name, 'key' => Str::normalizeName($name), 'id' => (int) $row['id']]
                    );
                }

                return (int) $row['id'];
            }
        }

        if ($name !== null && $name !== '') {
            $row = $this->db->selectOne(
                'SELECT id, code FROM teams WHERE normalized_key = :key',
                ['key' => Str::normalizeName($name)]
            );

            if ($row !== null) {
                // 名前で見つけてコードが未登録なら、今回判明したコードを埋める。
                if ($code !== null && $row['code'] === null) {
                    $this->db->execute('UPDATE teams SET code = :code WHERE id = :id', [
                        'code' => $code,
                        'id' => (int) $row['id'],
                    ]);
                }

                return (int) $row['id'];
            }
        }

        return null;
    }

    private function create(?string $code, ?string $name): int
    {
        $display = ($name !== null && $name !== '') ? $name : (string) $code;

        return $this->db->insert(
            'INSERT INTO teams (code, name, normalized_key, created_at)'
            . ' VALUES (:code, :name, :key, :created_at)',
            [
                'code' => $code,
                'name' => $display,
                'key' => Str::normalizeName($display),
                'created_at' => Clock::utcNow(),
            ]
        );
    }

    private static function cleanCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = strtoupper(Str::collapseSpaces($code));

        return $code === '' ? null : $code;
    }
}
