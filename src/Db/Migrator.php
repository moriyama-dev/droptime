<?php

declare(strict_types=1);

namespace DropTime\Db;

use DropTime\Support\Clock;
use PDOException;
use RuntimeException;

/**
 * マイグレーションは migrations/*.sql の平文 SQL。
 * 本番へは同じファイルをそのまま流して空から構築する。
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $directory,
    ) {
    }

    /**
     * 未適用のファイルを順に適用し、適用したファイル名を返す。
     *
     * @return list<string>
     */
    public function migrate(bool $dryRun = false): array
    {
        $this->ensureMigrationsTable();

        $applied = [];

        foreach ($this->pending() as $filename) {
            $path = $this->directory . '/' . $filename;
            $sql = file_get_contents($path);

            if ($sql === false) {
                throw new RuntimeException('マイグレーションを読めません: ' . $path);
            }

            $statements = self::splitStatements($sql);

            if ($dryRun) {
                $applied[] = sprintf('%s (%d 文)', $filename, count($statements));
                continue;
            }

            // MySQL の DDL は暗黙コミットされるためトランザクションで包めない。
            // どの文で失敗したかを明示して、手で戻せるようにする。
            foreach ($statements as $index => $statement) {
                try {
                    $this->db->pdo()->exec($statement);
                } catch (PDOException $e) {
                    throw new RuntimeException(
                        sprintf('%s の %d 番目の文で失敗しました: %s', $filename, $index + 1, $e->getMessage()),
                        0,
                        $e
                    );
                }
            }

            $this->db->execute(
                'INSERT INTO migrations (filename, applied_at) VALUES (:filename, :applied_at)',
                ['filename' => $filename, 'applied_at' => Clock::utcNow()]
            );

            $applied[] = $filename;
        }

        return $applied;
    }

    /** @return list<string> */
    public function pending(): array
    {
        $applied = $this->appliedFilenames();

        return array_values(array_filter($this->files(), static fn (string $f): bool => !in_array($f, $applied, true)));
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = glob($this->directory . '/*.sql');

        if ($files === false) {
            return [];
        }

        $names = array_map(static fn (string $p): string => basename($p), $files);
        sort($names, SORT_STRING);

        return array_values($names);
    }

    /** @return list<string> */
    public function appliedFilenames(): array
    {
        $this->ensureMigrationsTable();

        $rows = $this->db->select('SELECT filename FROM migrations ORDER BY id');

        return array_map(static fn (array $r): string => (string) $r['filename'], $rows);
    }

    /**
     * SQL ファイルを文単位に分割する。
     * 引用符・バッククォート・コメントの中の ';' を区切りと誤認しないよう、
     * 素朴な走査を行う。ストアドプロシージャなど DELIMITER を要する構文は非対応。
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // 行コメント
            if (($char === '-' && $next === '-') || $char === '#') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }

            // ブロックコメント
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            // 引用符の内側はそのまま写す
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                $i++;

                while ($i < $length) {
                    $inner = $sql[$i];
                    $buffer .= $inner;
                    $i++;

                    if ($inner === '\\' && $quote !== '`' && $i < $length) {
                        $buffer .= $sql[$i];
                        $i++;
                        continue;
                    }

                    if ($inner === $quote) {
                        break;
                    }
                }

                continue;
            }

            if ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                $i++;
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . ' id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' filename VARCHAR(190) NOT NULL,'
            . ' applied_at DATETIME NOT NULL,'
            . ' PRIMARY KEY (id),'
            . ' UNIQUE KEY uq_migrations_filename (filename)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
