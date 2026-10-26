<?php

declare(strict_types=1);

/**
 * ダウンロード済みの Hy-Tek 結果一式を取り込みキューに載せる。
 *
 *   php bin/import-hytek.php <大会ディレクトリまたは HTML ファイル> [--source <URL>]
 *
 * --source には結果の取得元 URL（速報サイトの大会ページ）を渡す。
 * 投入時に meets.source_file に記録され、大会ページに出典として表示される。
 *
 * 大会ディレクトリ = 速報サイトから保存したイベントページ (*.htm) の入った
 * フォルダ。各ページの <pre> テキストを 1 つの HTML にまとめ、Web の
 * アップロードと同じ経路（保存 → テキスト化 → パース）に流す。
 * DB への投入はここでは行わない。管理画面の確認画面で内容を見て、
 * 人間が承認したときだけ投入される（指示書 3.3 の流れを CLI でも守る）。
 *
 * 速報サイトからの自動取得は管理画面の「最新の記録を取得」（bin/fetch-latest.php）が行う。
 * こちらは人が手で集めたファイルを、確認画面を経て投入するための経路。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Import\HyTekBundle;
use DropTime\Import\ImportRepository;
use DropTime\Import\ImportService;
use DropTime\Import\UploadValidator;
use DropTime\Support\Config;

$path = '';
$sourceUrl = null;
$arguments = array_slice($argv, 1);

for ($i = 0; $i < count($arguments); $i++) {
    if ($arguments[$i] === '--source') {
        $sourceUrl = $arguments[$i + 1] ?? null;
        $i++;
    } elseif ($path === '') {
        $path = $arguments[$i];
    }
}

if ($path === '' || (!is_dir($path) && !is_file($path))) {
    fwrite(STDERR, "使い方: php bin/import-hytek.php <大会ディレクトリ | HTMLファイル> [--source <URL>]\n");
    exit(1);
}

Config::load(APP_ROOT . '/config/config.php');
date_default_timezone_set(Config::instance()->string('app.timezone', 'America/Toronto'));

// ── 対象ファイルを集めて 1 つの HTML にまとめる ──────────────────
if (is_file($path)) {
    $files = [$path];
    $label = basename($path);
} else {
    $files = HyTekBundle::pagesIn($path);
    $label = basename(rtrim($path, '/')) . '.html';
}

if ($files === []) {
    fwrite(STDERR, "対象の .htm ファイルがありません。\n");
    exit(1);
}

$bundle = HyTekBundle::combine($files);
$combined = $bundle['html'];
$skipped = $bundle['skipped'];

$temporary = tempnam(sys_get_temp_dir(), 'hytek');

if ($temporary === false || file_put_contents($temporary, $combined) === false) {
    fwrite(STDERR, "一時ファイルを作れませんでした。\n");
    exit(1);
}

// ── 通常のアップロード経路に流す ─────────────────────────────────
try {
    $uploaded = (new UploadValidator())->inspect($temporary, $label);
    $db = Database::instance();
    $id = ImportService::create($db)->receive($uploaded);
} finally {
    @unlink($temporary);
}

$repository = new ImportRepository(Database::instance());

if (is_string($sourceUrl) && $sourceUrl !== '') {
    $repository->mergeSummary($id, ['source_url' => $sourceUrl]);
}

$row = $repository->find($id);
$summary = $row !== null ? ImportRepository::summaryOf($row) : [];
$counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];

printf("取り込み #%d を作成しました（%d ページ、スキップ %d）。状態: %s\n", $id, count($files) - $skipped, $skipped, $row['status'] ?? '?');

if ($counts !== []) {
    printf(
        "  認識: 種目 %d / エントリー %d / 結果 %d / スプリット %d / 選手 %d / 未認識行 %d\n",
        $counts['events'] ?? 0,
        $counts['entries'] ?? 0,
        $counts['results'] ?? 0,
        $counts['splits'] ?? 0,
        $counts['swimmers'] ?? 0,
        $summary['unrecognized_total'] ?? 0
    );
}

if (isset($summary['meet']['name'])) {
    printf("  大会: %s（%s〜）\n", $summary['meet']['name'], $summary['meet']['start_date'] ?? '?');
}

if (($row['status'] ?? '') === 'failed') {
    printf("  失敗理由: %s\n", $summary['error'] ?? '不明');
    exit(1);
}

echo "管理画面の確認画面で内容を確かめてから投入してください:\n";
printf("  /admin/imports/%d\n", $id);
