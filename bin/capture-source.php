<?php

declare(strict_types=1);

/**
 * 出典の証拠を保存する。アプリの「データの出典」画面に出る。
 *
 *   php bin/capture-source.php <URL> [--meet <大会ID>] [--note <メモ>]
 *       1 ページを Cookie もログインも無しで開き、全画面スクショ・HTML・応答ヘッダを保存
 *
 *   php bin/capture-source.php --all-meets
 *       全大会の出典 URL（meets.source_file）を撮る
 *
 *   php bin/capture-source.php --swimmer-pages "<姓, 名>" [--meet <大会ID>]
 *       取り込みの原本（var/manual/sources/<大会>）からその選手が載ったページを探し、
 *       公開サイト上の同じページを撮る。「この記録は公開されていた」の直接の証拠
 *
 *   php bin/capture-source.php --originals <原本ディレクトリ> --source <URL> [--meet <大会ID>]
 *       取り込みに使った原本ファイル一式を写してハッシュを記録
 *
 *   php bin/capture-source.php --verify
 *       保存済みの全ファイルのハッシュと台帳の鎖を検証
 *
 * ヘッドレス Chrome が要る（既定は ~/.local/share/headless-chrome/chrome、
 * 環境変数 DROPTIME_CHROME で変更可）。共有ホスティングでは動かないので、
 * 開発環境で撮って var/evidence をディレクトリごと本番へ運ぶ。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Evidence\EvidenceStore;
use DropTime\Evidence\HeadlessBrowser;
use DropTime\Support\Config;

Config::load(APP_ROOT . '/config/config.php');

$args = array_slice($argv, 1);
$opts = ['meet' => [], 'note' => '', 'source' => null, 'originals' => null, 'swimmer' => null];
$mode = 'url';
$url = null;

for ($i = 0; $i < count($args); $i++) {
    switch ($args[$i]) {
        case '--meet': $opts['meet'][] = (int) $args[++$i]; break;
        case '--note': $opts['note'] = $args[++$i]; break;
        case '--source': $opts['source'] = $args[++$i]; break;
        case '--originals': $mode = 'originals'; $opts['originals'] = $args[++$i]; break;
        case '--all-meets': $mode = 'all-meets'; break;
        case '--swimmer-pages': $mode = 'swimmer'; $opts['swimmer'] = $args[++$i]; break;
        case '--verify': $mode = 'verify'; break;
        default: $url = $args[$i];
    }
}

$store = EvidenceStore::fromConfig();
$browser = null;
$browserFor = static function () use (&$browser): HeadlessBrowser {
    return $browser ??= new HeadlessBrowser(HeadlessBrowser::defaultPath());
};
$report = static function (array $meta): void {
    $status = $meta['http']['status'] ?? '-';
    echo "保存: {$meta['id']}  HTTP {$status}  {$meta['url']}\n";
};

try {
    switch ($mode) {
        case 'verify':
            $bad = 0;
            foreach ($store->all() as $item) {
                $result = $store->verify($item['id']);
                echo ($result['ok'] ? 'OK  ' : 'NG  ') . $item['id'] . "\n";
                $bad += $result['ok'] ? 0 : 1;
            }
            $ledger = $store->verifyLedger();
            echo ($ledger['ok'] ? 'OK  ' : 'NG  ') . "台帳 {$ledger['entries']} 件" . ($ledger['ok'] ? '' : "（{$ledger['broken_at']} 行目で鎖が切れています）") . "\n";
            exit($bad === 0 && $ledger['ok'] ? 0 : 1);

        case 'originals':
            if ($opts['source'] === null) {
                throw new RuntimeException('--source <URL> を指定してください。');
            }
            $report($store->registerOriginals($opts['originals'], $opts['source'], $opts['meet'], $opts['note']));
            break;

        case 'all-meets':
            $meets = Database::instance()->select("SELECT id, name, source_file FROM meets WHERE source_file LIKE 'http%' ORDER BY start_date");
            foreach ($meets as $meet) {
                $report($store->captureUrl((string) $meet['source_file'], $browserFor(), [(int) $meet['id']], 'Meet results index: ' . $meet['name']));
            }
            break;

        case 'swimmer':
            // 原本のフォルダ名と出典 URL の末尾が一致する大会を対応させる。
            $meets = Database::instance()->select("SELECT id, name, source_file FROM meets WHERE source_file LIKE 'http%'");
            $needle = (string) $opts['swimmer'];
            foreach ($meets as $meet) {
                // --meet を付けたときは、その大会だけ（新しく取り込んだ大会の分だけ撮るため）。
                if ($opts['meet'] !== [] && !in_array((int) $meet['id'], $opts['meet'], true)) {
                    continue;
                }
                $folder = basename(rtrim(parse_url((string) $meet['source_file'], PHP_URL_PATH) ?: '', '/'));
                $dir = APP_ROOT . '/var/manual/sources/' . $folder;
                if ($folder === '' || !is_dir($dir)) {
                    continue;
                }
                foreach (glob($dir . '/*.htm*') ?: [] as $file) {
                    if (stripos((string) file_get_contents($file), $needle) === false) {
                        continue;
                    }
                    $pageUrl = rtrim((string) $meet['source_file'], '/') . '/' . basename($file);
                    $report($store->captureUrl($pageUrl, $browserFor(), [(int) $meet['id']], "Results page listing {$needle}: {$meet['name']}"));
                }
            }
            break;

        default:
            if ($url === null) {
                fwrite(STDERR, "使い方は bin/capture-source.php の先頭のコメントを参照。\n");
                exit(2);
            }
            $report($store->captureUrl($url, $browserFor(), $opts['meet'], $opts['note']));
    }
} catch (Throwable $e) {
    fwrite(STDERR, '失敗: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $browser?->close();
}
