<?php

declare(strict_types=1);

/**
 * 管理者のテスト用の通知を、指定の秒数だけ待ってから 1 件送る。
 * 管理画面の「テスト」（/admin/tools）から裏で起動される。手でも動かせる:
 *
 *   php bin/push-test.php --subscription <購読 ID> [--kind test|day|race30|race10] [--delay <秒>]
 *
 * 結果は var/logs/push.log に 1 行（選手名は書かない）。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Push\TestNotice;
use DropTime\Support\Config;

$options = getopt('', ['subscription:', 'kind:', 'delay:']);
$subscriptionId = (int) ($options['subscription'] ?? 0);
$kind = (string) ($options['kind'] ?? 'test');
$delay = max(0, min(900, (int) ($options['delay'] ?? 0)));

if ($subscriptionId <= 0) {
    fwrite(STDERR, "使い方: php bin/push-test.php --subscription <購読 ID> [--kind test|day|race30|race10] [--delay <秒>]\n");
    exit(1);
}

Config::load(APP_ROOT . '/config/config.php');
date_default_timezone_set(Config::instance()->string('app.timezone', 'America/Toronto'));

if ($delay > 0) {
    sleep($delay);
}

// 待っている間に DB の接続が切れないよう、接続は待ったあとに作る。
$row = Database::instance()->selectOne('SELECT * FROM push_subscriptions WHERE id = :id', ['id' => $subscriptionId]);

if ($row === null) {
    TestNotice::log(sprintf('テスト送信 購読 %d: 購読がありません', $subscriptionId));
    exit(1);
}

try {
    $status = TestNotice::send($row, $kind);
} catch (Throwable $e) {
    TestNotice::log(sprintf('テスト送信 購読 %d（%s・%d 秒後）: 失敗 %s', $subscriptionId, $kind, $delay, $e->getMessage()));
    exit(1);
}

$line = sprintf('テスト送信 購読 %d（%s・%d 秒後）: 中継サーバーの返事 HTTP %d', $subscriptionId, $kind, $delay, $status);
TestNotice::log($line);
echo $line . "\n";
exit($status >= 200 && $status < 300 ? 0 : 1);
