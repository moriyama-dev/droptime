<?php

declare(strict_types=1);

/**
 * 携帯への通知（Web Push）。本番サーバーの cron から 3 分おきに動かす。
 *
 *   php bin/push-notify.php            今日泳ぐお気に入りの選手がいる端末にだけ、必要な知らせを送る
 *   php bin/push-notify.php --dry-run  送らずに、送る予定の知らせを表示する
 *   php bin/push-notify.php --force    時間帯（6〜23 時）に関係なく動かす（動作確認用）
 *
 * 知らせの種類と時機は src/Push/RaceNotices.php。同じ知らせは 2 度送らない（push_sent）。
 * 端末が通知をやめた（中継サーバーが 404・410 を返した）購読は消す。
 * ログは送ったときと失敗したときだけ var/logs/push.log に書く（選手名は書かない）。
 *
 * cron の例（時間帯の判定はトロント時間で行う）:
 *   * /3 * * * * cd /home/USER/droptime.takumi.ca && php bin/push-notify.php >/dev/null 2>&1
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Push\PushRepository;
use DropTime\Push\RaceNotices;
use DropTime\Push\WebPush;
use DropTime\Repository\FavoriteRepository;
use DropTime\Repository\RacePlanRepository;
use DropTime\Support\Config;
use DropTime\Support\Lang;

$force = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

Config::load(APP_ROOT . '/config/config.php');
$config = Config::instance();
date_default_timezone_set($config->string('app.timezone', 'America/Toronto'));

// 定期処理が呼ばれた印（管理画面の「テスト」で、動いているかを見る）。
@mkdir(APP_ROOT . '/var/logs', 0775, true);
@touch(APP_ROOT . '/var/logs/push-notify.last');

$hour = (int) date('G');

if (!$force && !$dryRun && ($hour < 6 || $hour >= 23)) {
    exit(0);
}

$privatePem = $config->string('push.vapid_private', '');

if ($privatePem === '') {
    fwrite(STDERR, "push.vapid_private が設定にありません（php bin/push-keys.php で作る）。\n");
    exit(1);
}

@mkdir(APP_ROOT . '/var/logs', 0775, true);
$lock = fopen(APP_ROOT . '/var/logs/push-notify.lock', 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // 前の回がまだ動いている
}

$logFile = APP_ROOT . '/var/logs/push.log';
$log = static function (string $line) use ($logFile, $force, $dryRun): void {
    if ($force || $dryRun) {
        echo $line . "\n";
    }

    if (!$dryRun) {
        file_put_contents($logFile, date('Y-m-d H:i:s T') . '  ' . $line . "\n", FILE_APPEND);
    }
};

$db = Database::instance();
$pushes = new PushRepository($db);
$favorites = new FavoriteRepository($db);
$planner = new RacePlanRepository($db, $config->int('schedule.lanes', 8));
$webPush = new WebPush($privatePem, $config->string('push.subject', 'https://droptime.takumi.ca/'));
$leads = array_map('intval', (array) $config->get('push.lead_minutes', [30, 10]));
$basePath = $config->string('app.base_path', '');

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// 今日が会期中の大会が無ければ、購読を 1 件ずつ見るまでもない。
$anyMeet = $db->selectValue(
    'SELECT 1 FROM meets WHERE start_date <= :today AND COALESCE(end_date, start_date) >= :today2 LIMIT 1',
    ['today' => $today, 'today2' => $today]
);

if ($anyMeet === null && !$force) {
    exit(0);
}

$plans = []; // 入れ物ごとの計算結果（同じ入れ物の端末が複数あれば使い回す）

foreach ($pushes->all() as $subscription) {
    $profileId = (int) $subscription['profile_id'];
    $plans[$profileId] ??= $planner->plan($favorites->upcomingEntries($profileId, $today), $today, $now);

    Lang::init((string) $subscription['lang']);
    $notices = RaceNotices::due($plans[$profileId], $now, $leads, $basePath);

    if ($notices === []) {
        continue;
    }

    $allKeys = [];

    foreach ($notices as $notice) {
        array_push($allKeys, ...$notice['keys'], ...($notice['skip_if'] ?? []));
    }

    $sent = array_flip($pushes->sentKeys((int) $subscription['id'], array_values(array_unique($allKeys))));

    foreach ($notices as $notice) {
        if (isset($sent[$notice['keys'][0]])) {
            continue;
        }

        foreach ($notice['skip_if'] ?? [] as $closer) {
            if (isset($sent[$closer])) {
                continue 2;
            }
        }

        if ($dryRun) {
            $log(sprintf('送る予定 購読 %d: %s / %s', $subscription['id'], $notice['title'], str_replace("\n", ' / ', $notice['body'])));

            continue;
        }

        $payload = (string) json_encode([
            'title' => $notice['title'],
            'body' => $notice['body'],
            'url' => $notice['url'],
            'tag' => $notice['tag'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $status = $webPush->send([
                'endpoint' => (string) $subscription['endpoint'],
                'p256dh' => (string) $subscription['p256dh'],
                'auth' => (string) $subscription['auth'],
            ], $payload, $notice['ttl']);
        } catch (Throwable $e) {
            $log(sprintf('購読 %d: 失敗 %s', $subscription['id'], $e->getMessage()));

            continue;
        }

        if ($status === 404 || $status === 410) {
            $pushes->delete((int) $subscription['id']);
            $log(sprintf('購読 %d: 端末が通知をやめていたので消しました（%d）', $subscription['id'], $status));

            continue 2;
        }

        if ($status < 200 || $status >= 300) {
            $log(sprintf('購読 %d: 中継サーバーが %d を返しました（%s）', $subscription['id'], $status, $notice['keys'][0]));

            continue;
        }

        $pushes->markSent((int) $subscription['id'], $notice['keys']);
        $log(sprintf('購読 %d: 送りました（%s）', $subscription['id'], preg_replace('/\|\d+\|/', '|…|', $notice['keys'][0], 1)));
    }
}

if (!$dryRun && (int) date('i') < 3 && $hour === 6) {
    $pushes->prune();
}
