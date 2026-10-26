<?php

declare(strict_types=1);

/**
 * マイグレーション実行。
 *
 *   php bin/migrate.php            未適用の SQL をすべて適用する
 *   php bin/migrate.php --status   適用済みと未適用の一覧を表示する
 *   php bin/migrate.php --dry-run  適用せずに対象だけ表示する
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Db\Migrator;
use DropTime\Support\Config;

$config = Config::load(APP_ROOT . '/config/config.php');
date_default_timezone_set($config->string('app.timezone', 'America/Toronto'));

$migrator = new Migrator(Database::instance(), APP_ROOT . '/migrations');
$argument = $argv[1] ?? '';

try {
    if ($argument === '--status') {
        $applied = $migrator->appliedFilenames();

        foreach ($migrator->files() as $file) {
            printf("%s %s\n", in_array($file, $applied, true) ? '[適用済]' : '[未適用]', $file);
        }

        exit(0);
    }

    $applied = $migrator->migrate($argument === '--dry-run');

    if ($applied === []) {
        echo "未適用のマイグレーションはありません。\n";
        exit(0);
    }

    foreach ($applied as $name) {
        echo ($argument === '--dry-run' ? '対象: ' : '適用: ') . $name . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'エラー: ' . $e->getMessage() . "\n");
    exit(1);
}
