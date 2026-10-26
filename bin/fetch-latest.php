<?php

declare(strict_types=1);

/**
 * 速報サイトから最新の記録を取りに行き、DB に投入する。
 * 管理画面の「最新の記録を取得」ボタンが裏でこれを起動する（手で実行してもよい）。
 *
 *   php bin/fetch-latest.php                     全大会を確認し、中身が変わった大会を読み直す
 *   php bin/fetch-latest.php --folder 2026hof    その大会フォルダだけ（状態に関係なく）
 *   php bin/fetch-latest.php --no-evidence       出典の証拠（スクショ）を撮らない
 *
 * 取り込むのは、クラブ（config の fetch.club_code、既定 ZORC）の選手が 1 人でも
 * 載っている大会の、結果のページだけ。大会前の Psych Sheet は取り込まない。
 * 新しく投入した大会は、そのまま出典の証拠も保存する（ヘッドレス Chrome がある環境だけ）。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Evidence\HeadlessBrowser;
use DropTime\Fetch\FetchJob;
use DropTime\Fetch\LatestRecordsFetcher;
use DropTime\Fetch\PortalClient;
use DropTime\Support\Config;

$onlyFolder = null;
$evidence = true;
$args = array_slice($argv, 1);

for ($i = 0; $i < count($args); $i++) {
    match ($args[$i]) {
        '--folder' => $onlyFolder = rawurlencode(rawurldecode((string) ($args[++$i] ?? ''))),
        '--no-evidence' => $evidence = false,
        default => null,
    };
}

Config::load(APP_ROOT . '/config/config.php');
$config = Config::instance();
date_default_timezone_set($config->string('app.timezone', 'America/Toronto'));

$job = FetchJob::fromConfig();

if (!$job->acquire()) {
    fwrite(STDERR, "別の取得が実行中です。終わってからもう一度実行してください。\n");
    exit(2);
}

$log = static function (string $line): void {
    echo date('H:i:s') . '  ' . $line . "\n";
};

$startedAt = date('c');
$job->writeStatus(['state' => 'running', 'started_at' => $startedAt]);
$log('最新の記録の取得を始めます（' . date('Y-m-d H:i T') . '）');

// 新しく投入した大会の、公開状態の証拠を撮る。capture-source.php に任せる。
$chrome = HeadlessBrowser::defaultPath();
$canCapture = $evidence && $config->bool('fetch.evidence', true) && is_executable($chrome);

if ($evidence && !$canCapture) {
    $log('ヘッドレス Chrome が無いため、出典の証拠は撮りません（開発環境で bin/capture-source.php を実行してください）。');
}

$favorites = Database::instance()->select(
    'SELECT DISTINCT s.last_name, s.first_name FROM favorites f JOIN swimmers s ON s.id = f.swimmer_id'
);

$afterCommit = static function (int $meetId, string $url, string $originals) use ($canCapture, $favorites, $log): void {
    if (!$canCapture) {
        return;
    }

    $run = static function (array $arguments) use ($log): void {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/bin/capture-source.php');

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg((string) $argument);
        }

        exec($command . ' 2>&1', $output, $code);

        foreach ($output as $line) {
            $log('    ' . $line);
        }

        if ($code !== 0) {
            $log('    （証拠の保存に失敗。後で bin/capture-source.php で撮り直せます）');
        }
    };

    $log('  出典の証拠を保存しています');
    $run([$url, '--meet', $meetId, '--note', 'Meet results index (fetched automatically)']);
    $run(['--originals', $originals, '--source', $url, '--meet', $meetId]);

    foreach ($favorites as $swimmer) {
        $run(['--swimmer-pages', $swimmer['last_name'] . ', ' . $swimmer['first_name'], '--meet', $meetId]);
    }
};

$fetcher = new LatestRecordsFetcher(
    Database::instance(),
    new PortalClient(
        $config->string('fetch.portal_url', 'https://results.swimmeets.ca/'),
        $config->int('fetch.delay_ms', 300)
    ),
    $job->statePath(),
    APP_ROOT . '/var/manual/sources',
    $config->string('fetch.club_code', 'ZORC'),
    $log,
    $afterCommit(...),
);

try {
    $summary = $fetcher->run($onlyFolder);
} catch (Throwable $e) {
    $log('失敗: ' . $e->getMessage());
    $job->writeStatus(['state' => 'failed', 'started_at' => $startedAt, 'finished_at' => date('c'), 'error' => $e->getMessage()]);
    exit(1);
}

$pendingMerges = (int) Database::instance()->selectValue(
    'SELECT COUNT(*) FROM swimmer_merge_candidates WHERE status = :status',
    ['status' => 'pending']
);

$log(sprintf(
    '完了: 確認 %d 大会（うち変化なし %d）/ 投入 %d 大会 / 対象外 %d / 失敗 %d（通信 %d 回）',
    $summary['checked'],
    $summary['unchanged'],
    count($summary['imported']),
    count($summary['skipped']),
    count($summary['failed']),
    $summary['requests']
));

$job->writeStatus([
    'state' => 'done',
    'started_at' => $startedAt,
    'finished_at' => date('c'),
    'summary' => $summary,
    'pending_merges' => $pendingMerges,
]);

exit($summary['failed'] === [] ? 0 : 1);
