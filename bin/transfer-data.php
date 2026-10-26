<?php

declare(strict_types=1);

/**
 * 大会データ（大会・種目・結果・選手・チーム・統合の判定）を別の環境へ運ぶ。
 * 開発環境で取り込み・確認・統合の判定まで済ませた内容を、本番で同じ作業を
 * やり直さずに載せるためのもの。
 *
 *   php bin/transfer-data.php export <出力.json.gz>
 *   php bin/transfer-data.php import <入力.json.gz>            # 本番が空のときだけ
 *   php bin/transfer-data.php import <入力.json.gz> --replace  # 大会データを入れ替える
 *
 * 運ぶのは公開情報から作った表だけ。ログイン・セッション・お気に入り・
 * 取り込み履歴（端末や保護者に紐づくもの）は運ばない。
 * --replace でも、本番のお気に入りは選手 ID が同じなら残る（ID ごと運ぶため）。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Support\Config;

Config::load(APP_ROOT . '/config/config.php');

/** 外部キーの親 → 子の順。 */
const TABLES = [
    'teams', 'swimmers', 'meets', 'meet_sessions', 'events', 'entries', 'results', 'splits',
    'swimmer_merges', 'swimmer_merge_candidates',
];

$command = $argv[1] ?? '';
$path = $argv[2] ?? '';
$replace = in_array('--replace', $argv, true);

if (!in_array($command, ['export', 'import'], true) || $path === '') {
    fwrite(STDERR, "使い方: php bin/transfer-data.php export|import <ファイル.json.gz> [--replace]\n");
    exit(2);
}

$db = Database::instance();
$pdo = $db->pdo();

if ($command === 'export') {
    $data = ['format' => 'droptime-transfer/1', 'exported_at' => gmdate('c'), 'tables' => []];

    foreach (TABLES as $table) {
        $data['tables'][$table] = $pdo->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        printf("%-26s %6d 行\n", $table, count($data['tables'][$table]));
    }

    file_put_contents($path, gzencode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 9));
    echo "書き出しました: {$path}\n";
    exit(0);
}

$data = json_decode((string) gzdecode((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);

if (($data['format'] ?? '') !== 'droptime-transfer/1') {
    fwrite(STDERR, "形式が違います。\n");
    exit(1);
}

$existing = (int) $db->selectValue('SELECT COUNT(*) FROM meets');

if ($existing > 0 && !$replace) {
    fwrite(STDERR, "本番にすでに大会が {$existing} 件あります。入れ替えるときは --replace を付けてください（先に bin/backup.php を）。\n");
    exit(1);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

try {
    $pdo->beginTransaction();

    if ($replace) {
        // お気に入りは消さない（選手 ID ごと運ぶので、同じ選手を指したまま残る）。
        foreach (array_reverse(TABLES) as $table) {
            $pdo->exec("DELETE FROM `{$table}`");
        }
    }

    foreach (TABLES as $table) {
        $rows = $data['tables'][$table] ?? [];

        foreach (array_chunk($rows, 300) as $chunk) {
            $columns = array_keys($chunk[0]);
            $columnList = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
            $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $statement = $pdo->prepare("INSERT INTO `{$table}` ({$columnList}) VALUES " . implode(', ', array_fill(0, count($chunk), $placeholders)));
            $values = [];

            foreach ($chunk as $row) {
                foreach ($columns as $column) {
                    $values[] = $row[$column];
                }
            }

            $statement->execute($values);
        }

        printf("%-26s %6d 行\n", $table, count($rows));
    }

    // 運ばれなかった選手を指すお気に入りは、指す先が無くなるので片付ける。
    $pdo->exec('DELETE f FROM favorites f LEFT JOIN swimmers s ON s.id = f.swimmer_id WHERE s.id IS NULL');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, 'エラー（何も変更していません）: ' . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "取り込みました。\n";
