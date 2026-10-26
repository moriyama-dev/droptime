<?php

declare(strict_types=1);

/**
 * DB のバックアップ。var/backups に日付入りで保存し、設定した世代数だけ残す。
 *
 *   php bin/backup.php          mysqldump があればそれを使い、無ければ PHP で書き出す
 *   php bin/backup.php --php    常に PHP 版で書き出す（本番に mysqldump が無い場合の確認用）
 *
 * 出力は gzip 圧縮した SQL。復元は次のとおり。
 *   gunzip -c droptime-YYYY-MM-DD-HHMMSS.sql.gz | mysql -u USER -p DBNAME
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Support\Clock;
use DropTime\Support\Config;

$config = Config::load(APP_ROOT . '/config/config.php');
date_default_timezone_set($config->string('app.timezone', 'America/Toronto'));

$directory = $config->string('paths.backups', APP_ROOT . '/var/backups');
$forcePhp = ($argv[1] ?? '') === '--php';

if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
    fwrite(STDERR, "バックアップ先を作成できません: {$directory}\n");
    exit(1);
}

$stamp = Clock::now()->setTimezone(Clock::localZone())->format('Y-m-d-His');
$finalPath = uniquePath($directory . '/droptime-' . $stamp, '.sql.gz');
$plainPath = substr($finalPath, 0, -3);

$dumpBinary = $config->string('tools.mysqldump', '/usr/bin/mysqldump');
$useDump = !$forcePhp && $dumpBinary !== '' && is_executable($dumpBinary);

try {
    if ($useDump) {
        dumpWithMysqldump($config, $dumpBinary, $plainPath);
        $method = 'mysqldump';
    } else {
        dumpWithPhp($plainPath);
        $method = 'PHP';
    }

    gzipFile($plainPath, $finalPath);
    unlink($plainPath);

    printf("%s で書き出しました: %s (%d バイト)\n", $method, basename($finalPath), (int) filesize($finalPath));

    rotate($directory, $config->int('backup.keep', 7));
} catch (Throwable $e) {
    fwrite(STDERR, 'エラー: ' . $e->getMessage() . "\n");

    foreach ([$plainPath, $finalPath] as $partial) {
        if (is_file($partial)) {
            unlink($partial);
        }
    }

    exit(1);
}

/**
 * 同じ秒に 2 回実行しても既存のバックアップを上書きしないようにする。
 */
function uniquePath(string $base, string $extension): string
{
    $candidate = $base . $extension;
    $counter = 2;

    while (file_exists($candidate)) {
        $candidate = $base . '-' . $counter . $extension;
        $counter++;
    }

    return $candidate;
}

/**
 * mysqldump 版。
 * パスワードは ps に出さないよう、権限 0600 の一時 defaults ファイル経由で渡す。
 */
function dumpWithMysqldump(Config $config, string $binary, string $outputPath): void
{
    $defaults = tempnam(sys_get_temp_dir(), 'dt-my-');

    if ($defaults === false) {
        throw new RuntimeException('一時ファイルを作成できません。');
    }

    chmod($defaults, 0600);

    $ini = "[client]\n"
        . 'user=' . $config->string('db.user') . "\n"
        . 'password=' . $config->string('db.password') . "\n"
        . 'host=' . $config->string('db.host', '127.0.0.1') . "\n"
        . 'port=' . $config->int('db.port', 3306) . "\n";

    file_put_contents($defaults, $ini);

    // --no-tablespaces は MySQL 8 で PROCESS 権限が無い共有ホスティング向け。
    $command = sprintf(
        '%s --defaults-extra-file=%s --single-transaction --default-character-set=utf8mb4 '
        . '--no-tablespaces --hex-blob --result-file=%s %s 2>&1',
        escapeshellarg($binary),
        escapeshellarg($defaults),
        escapeshellarg($outputPath),
        escapeshellarg($config->string('db.name'))
    );

    $output = shell_exec($command);
    unlink($defaults);

    if (!is_file($outputPath) || filesize($outputPath) === 0) {
        throw new RuntimeException('mysqldump に失敗しました: ' . trim((string) $output));
    }
}

/**
 * PHP 版。本番に mysqldump が無かった場合の代替。
 * 大きなテーブルでもメモリを使わないよう、非バッファ問い合わせで 1 行ずつ書き出す。
 */
function dumpWithPhp(string $outputPath): void
{
    $db = Database::instance();
    $pdo = $db->pdo();

    $handle = fopen($outputPath, 'wb');

    if ($handle === false) {
        throw new RuntimeException('書き出し先を開けません: ' . $outputPath);
    }

    fwrite($handle, "-- DropTime backup (PHP)\n");
    fwrite($handle, "SET NAMES utf8mb4;\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    /** @var list<string> $tables */
    $tables = array_map(
        static fn (array $row): string => (string) array_values($row)[0],
        $db->select('SHOW TABLES')
    );

    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        $create = $db->selectOne('SHOW CREATE TABLE ' . $quoted);
        $createSql = is_array($create) ? (string) (array_values($create)[1] ?? '') : '';

        fwrite($handle, "DROP TABLE IF EXISTS {$quoted};\n");
        fwrite($handle, $createSql . ";\n\n");

        $statement = $pdo->query('SELECT * FROM ' . $quoted);

        if ($statement === false) {
            continue;
        }

        $rowCount = 0;

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $columns = array_map(
                static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`',
                array_keys($row)
            );

            $values = array_map(
                static fn (mixed $v): string => quoteValue($pdo, $v),
                array_values($row)
            );

            fwrite($handle, sprintf(
                "INSERT INTO %s (%s) VALUES (%s);\n",
                $quoted,
                implode(', ', $columns),
                implode(', ', $values)
            ));

            $rowCount++;
        }

        $statement->closeCursor();
        fwrite($handle, sprintf("-- %s: %d 行\n\n", $table, $rowCount));
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);

    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
}

function quoteValue(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    $string = (string) $value;

    // セッションの payload などバイナリを含みうる列は 16 進リテラルで書き出す。
    if (!mb_check_encoding($string, 'UTF-8')) {
        return '0x' . bin2hex($string);
    }

    return $pdo->quote($string);
}

function gzipFile(string $source, string $destination): void
{
    $in = fopen($source, 'rb');
    $out = gzopen($destination, 'wb9');

    if ($in === false || $out === false) {
        throw new RuntimeException('圧縮に失敗しました。');
    }

    while (!feof($in)) {
        $chunk = fread($in, 262144);

        if ($chunk === false) {
            break;
        }

        gzwrite($out, $chunk);
    }

    fclose($in);
    gzclose($out);
}

/** 新しいものから keep 世代だけ残して削除する。 */
function rotate(string $directory, int $keep): void
{
    $files = glob($directory . '/droptime-*.sql.gz');

    if ($files === false || count($files) <= $keep) {
        return;
    }

    // ファイル名に日時が入っているので名前順で古い順になる。
    sort($files, SORT_STRING);
    $obsolete = array_slice($files, 0, count($files) - $keep);

    foreach ($obsolete as $file) {
        unlink($file);
        echo '削除: ' . basename($file) . "\n";
    }
}
