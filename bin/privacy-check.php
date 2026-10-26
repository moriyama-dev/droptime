<?php

declare(strict_types=1);

/**
 * 公開リポジトリ（GitHub・public）に個人情報を入れないための見張り。
 *
 *   php bin/privacy-check.php --staged         # コミットに入る内容（pre-commit が呼ぶ）
 *   php bin/privacy-check.php --push <範囲>    # push する全コミットの差分とメッセージ（pre-push が呼ぶ）
 *   php bin/privacy-check.php --all            # 今の作業ツリーの git 管理下のファイル全部
 *
 * 見るもの:
 *   1. DB に登録された選手の姓名（「姓, 名」「名 姓」「姓 名」の並び）。大会の結果に載った子どもたち全員
 *   2. var/privacy/extra.txt の語（1 行 1 語。家族の名前・住所・電話など。git 管理外）
 *   3. 公開してはいけないファイル（config/config.php・var/ の中身・DB のダンプ・.claude/）
 *
 * DB に接続できないときは「確かめられない」ので止める（見逃して公開するより止まるほうが安全）。
 * 名前のテストデータが要るときは、架空の名前を使う（tests/fixtures は置き換え済み）。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Support\Config;

$mode = $argv[1] ?? '';

if (!in_array($mode, ['--staged', '--push', '--all'], true)) {
    fwrite(STDERR, "使い方: php bin/privacy-check.php --staged | --push <範囲> | --all\n");
    exit(2);
}

/** 公開してはいけないパス。var/ は .htaccess と .gitignore だけ許す。 */
$forbiddenPath = static function (string $path): bool {
    if (preg_match('#^var/[^/]+/(\.htaccess|\.gitignore)$#', $path) === 1 || $path === 'var/.htaccess') {
        return false;
    }

    return $path === 'config/config.php'
        || $path === 'REVISION'
        || str_starts_with($path, 'var/')
        || str_starts_with($path, '.claude/')
        || preg_match('/\.(sql\.gz|json\.gz|bundle)$/', $path) === 1;
};

// ── 探す語を集める ───────────────────────────────────────────────

$needles = [];

try {
    Config::load(APP_ROOT . '/config/config.php');
    $rows = Database::instance()->pdo()
        ->query('SELECT DISTINCT last_name, first_name FROM swimmers')
        ->fetchAll(PDO::FETCH_NUM);
} catch (Throwable $e) {
    fwrite(STDERR, "privacy-check: DB に接続できないため、個人名が入っていないか確かめられません。止めます。\n");
    fwrite(STDERR, '  ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($rows as [$last, $first]) {
    $last = mb_strtolower(trim((string) $last));
    $first = mb_strtolower(trim((string) $first));

    if (mb_strlen($last) < 2 || mb_strlen($first) < 2) {
        continue;
    }

    $needles["{$last}, {$first}"] = true;
    $needles["{$first} {$last}"] = true;
    $needles["{$last} {$first}"] = true; // normalized_key の並び
}

$extraFile = APP_ROOT . '/var/privacy/extra.txt';

if (is_file($extraFile)) {
    foreach (file($extraFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = mb_strtolower(trim($line));

        if ($line !== '' && !str_starts_with($line, '#')) {
            $needles[$line] = true;
        }
    }
}

$needles = array_keys($needles);

/** GitHub のアカウント名など、個人名を含んでも公開してよい語。 */
const ALLOWED = ['moriyama-dev'];

/** @return list<string> 見つかった語 */
$scan = static function (string $text) use ($needles): array {
    $text = mb_strtolower($text);

    foreach (ALLOWED as $allowed) {
        $text = str_replace($allowed, '', $text);
    }

    // 空白の並びを 1 つにそろえる（固定幅の表でも見つけるため）
    $text = ' ' . (string) preg_replace('/[ \t]+/u', ' ', $text) . ' ';
    $found = [];

    foreach ($needles as $needle) {
        $offset = 0;

        while (($pos = strpos($text, $needle, $offset)) !== false) {
            $before = mb_substr(substr($text, 0, $pos), -1);
            $after = mb_substr(substr($text, $pos + strlen($needle)), 0, 1);

            if (preg_match('/\p{L}/u', $before . $after) !== 1) {
                $found[] = $needle;
                break;
            }

            $offset = $pos + 1;
        }
    }

    return $found;
};

$git = static function (string $args): string {
    return (string) shell_exec('git -C ' . escapeshellarg(APP_ROOT) . ' ' . $args . ' 2>/dev/null');
};

// ── 調べる中身を集める（ラベル => 中身） ───────────────────────────

$targets = [];
$paths = [];

if ($mode === '--staged') {
    $paths = array_filter(explode("\0", $git('diff --cached --name-only -z --diff-filter=ACMR')));

    foreach ($paths as $path) {
        $targets[$path] = $git('show ' . escapeshellarg(':' . $path));
    }
} elseif ($mode === '--all') {
    $paths = array_filter(explode("\0", $git('ls-files -z')));

    foreach ($paths as $path) {
        $full = APP_ROOT . '/' . $path;
        $targets[$path] = is_file($full) ? (string) file_get_contents($full) : '';
    }
} else {
    $range = $argv[2] ?? '';

    if ($range === '') {
        fwrite(STDERR, "--push には範囲（例: origin/main..HEAD か、初回は HEAD）が要ります。\n");
        exit(2);
    }

    foreach (array_filter(explode("\n", $git('rev-list ' . escapeshellarg($range)))) as $commit) {
        $targets["コミット {$commit} のメッセージ"] = $git('log -1 --format=%an%n%B ' . escapeshellarg($commit));
        $targets["コミット {$commit} の差分"] = $git('show --format= --no-color --unified=0 ' . escapeshellarg($commit));

        foreach (array_filter(explode("\0", $git('show --format= --name-only -z ' . escapeshellarg($commit)))) as $path) {
            $paths[] = $path;
        }
    }

    // 範囲の先端のツリー全体も見る（以前のコミットから残っているものも公開されるため）
    $tip = trim($git('rev-parse ' . escapeshellarg(preg_replace('/^.*\.\./', '', $range) ?: 'HEAD')));

    foreach (array_filter(explode("\0", $git('ls-tree -r -z --name-only ' . escapeshellarg($tip)))) as $path) {
        $paths[] = $path;
        $targets["{$tip}:{$path}"] = $git('show ' . escapeshellarg($tip . ':' . $path));
    }
}

// ── 判定 ─────────────────────────────────────────────────────────

$problems = [];

foreach (array_unique($paths) as $path) {
    if ($forbiddenPath($path)) {
        $problems[] = "公開してはいけないファイル: {$path}";
    }
}

foreach ($targets as $label => $content) {
    if (str_contains(substr($content, 0, 8000), "\0")) {
        continue; // 画像などのバイナリ
    }

    foreach ($scan($content) as $needle) {
        $problems[] = "個人名・個人情報: {$label} に「{$needle}」";
    }
}

if ($problems !== []) {
    fwrite(STDERR, "privacy-check: 公開リポジトリに入れてはいけないものが見つかりました。止めます。\n");

    foreach (array_slice(array_unique($problems), 0, 50) as $problem) {
        fwrite(STDERR, "  - {$problem}\n");
    }

    fwrite(STDERR, "架空の名前に置き換えるか、git 管理から外してから、やり直してください（CLAUDE.md の「公開リポジトリと個人情報」）。\n");
    exit(1);
}

printf("privacy-check: OK（%d 件を %d 語で確認）\n", count($targets), count($needles));
exit(0);
