<?php

declare(strict_types=1);

namespace DropTime\Db;

use DropTime\Support\Config;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * DB アクセスはすべてこのクラスを通す。
 * SQL は必ずプリペアドステートメントで実行し、値を文字列連結しない。
 *
 * 接続時に sql_mode と time_zone を明示的に設定する。共有ホスティングの
 * 既定値に挙動を左右されないようにするため。
 */
final class Database
{
    private const SQL_MODE = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION';

    private static ?self $instance = null;

    private ?PDO $pdo = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $config = Config::instance();
        $socket = $config->get('db.socket');

        if (is_string($socket) && $socket !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $config->string('db.name'));
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config->string('db.host', '127.0.0.1'),
                $config->int('db.port', 3306),
                $config->string('db.name')
            );
        }

        try {
            $pdo = new PDO($dsn, $config->string('db.user'), $config->string('db.password'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // プレースホルダは MySQL 側で解釈させる。
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            // 接続情報が例外メッセージに載るため、そのままは投げない。
            throw new RuntimeException('データベースに接続できません。', 0, $e);
        }

        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
        // UTC 固定。NOW() を使う箇所が混入しても環境差が出ないようにする。
        $pdo->exec("SET SESSION time_zone = '+00:00'");

        $this->pdo = $pdo;

        return $pdo;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $params */
    public function selectValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $params
     * @return int 影響行数
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string, mixed> $params */
    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * トランザクション。例外が出たら必ずロールバックして再送出する。
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params === [] ? null : $params);

        return $statement;
    }
}
