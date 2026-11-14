<?php

declare(strict_types=1);

/**
 * 大会当日のカウントダウンの補正。本番サーバーの cron から 3 分おきに動かす。
 *
 *   php bin/live-progress.php           今日が会期中で、お気に入りの選手が出ている大会だけを確かめる
 *   php bin/live-progress.php --force   時間帯（6〜23 時）に関係なく確かめる（動作確認用）
 *
 * 速報サイトの「最後に終わった種目」を読み、その種目が終わった時刻を live_event_status に残す。
 * 1 大会につき通信は 2 回。見張る大会が無ければ通信しない（DB を 1 回見るだけ）。
 * ログは読めなかったとき（と --force のとき）だけ var/logs/live-progress.log に書く。
 *
 * cron の例（サーバーの時刻帯に関係なく、時間帯の判定はトロント時間で行う）:
 *   * /3 * * * * cd /home/USER/droptime.takumi.ca && php bin/live-progress.php >/dev/null 2>&1
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Fetch\LiveProgress;
use DropTime\Fetch\PortalClient;
use DropTime\Support\Config;

$force = in_array('--force', $argv, true);

Config::load(APP_ROOT . '/config/config.php');
$config = Config::instance();
date_default_timezone_set($config->string('app.timezone', 'America/Toronto'));

$hour = (int) date('G');

if (!$force && ($hour < 6 || $hour >= 23)) {
    exit(0);
}

@mkdir(APP_ROOT . '/var/logs', 0775, true);
$lock = fopen(APP_ROOT . '/var/logs/live-progress.lock', 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // 前の回がまだ動いている
}

$logFile = APP_ROOT . '/var/logs/live-progress.log';
$log = static function (string $line) use ($logFile): void {
    file_put_contents($logFile, date('Y-m-d H:i:s T') . '  ' . $line . "\n", FILE_APPEND);
};

$live = new LiveProgress(
    Database::instance(),
    new PortalClient($config->string('fetch.portal_url', 'https://results.swimmeets.ca/'), $config->int('fetch.delay_ms', 300)),
);

$meets = $live->meetsToWatch(date('Y-m-d'));

foreach ($meets as $meet) {
    try {
        $result = $live->check($meet);
    } catch (Throwable $e) {
        $log(sprintf('大会 %d: 失敗 %s', $meet['id'], $e->getMessage()));

        continue;
    }

    $line = $result['event'] !== null
        ? sprintf('大会 %d: 種目 %s（%s）は %s に終了', $meet['id'], $result['event'], $result['round'], $result['finished_at'])
        : sprintf('大会 %d: %s', $meet['id'], $result['note']);

    if ($force) {
        echo $line . "\n";
    }

    if ($result['event'] === null || $force) {
        $log($line);
    }
}

if ($force && $meets === []) {
    echo "今日、見張る大会はありません。\n";
}
