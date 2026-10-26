<?php

declare(strict_types=1);

/**
 * 公開リポジトリ（GitHub・public）に個人情報を入れないための見張り。
 *
 *   php bin/privacy-check.php --staged         # コミットに入る内容（pre-commit が呼ぶ）
 *   php bin/privacy-check.php --push <範囲>    # push する全コミットの差分とメッセージ（pre-push が呼ぶ）
 *   php bin/privacy-check.php --all            # 今の作業ツリーの git 管理下のファイル全部
 *   php bin/privacy-check.php --stdin          # 標準入力の文章（Claude が gh で Issue などを作る前に、フックが呼ぶ）
 *   php bin/privacy-check.php --export-ci      # GitHub Actions 用の語のハッシュを標準出力へ（bin/privacy-sync-github.sh が呼ぶ）
 *
 * 見るもの:
 *   1. DB に登録された選手の姓名（「姓, 名」「名 姓」「姓 名」の並び）。大会の結果に載った子どもたち全員
 *   2. var/privacy/extra.txt の語（1 行 1 語。クラブ名・家族の名前・住所など。git 管理外）。無い・空なら止める
 *   3. 機密情報の型（秘密鍵・トークン・パスワードのハッシュ・Tailscale の IP・個人のメールアドレスなど。src/Support/PrivacyRules.php）
 *   4. 公開してはいけないファイル（config/config.php・var/ の中身・DB のダンプ・.claude/）
 *   5. コミットの作成者のメールアドレス（GitHub の非公開用アドレス users.noreply.github.com だけを通す）
 *
 * DB に接続できないとき・extra.txt が無いときは「確かめられない」ので止める（見逃して公開するより止まるほうが安全）。
 * 名前のテストデータが要るときは、架空の名前を使う（tests/fixtures は置き換え済み）。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Support\Config;
use DropTime\Support\PrivacyRules;

$mode = $argv[1] ?? '';

if (!in_array($mode, ['--staged', '--push', '--all', '--stdin', '--export-ci'], true)) {
    fwrite(STDERR, "使い方: php bin/privacy-check.php --staged | --push <範囲> | --all | --stdin | --export-ci\n");
    exit(2);
}


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
$extraTerms = [];

foreach (is_file($extraFile) ? (file($extraFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
    $line = mb_strtolower(trim($line));

    if ($line !== '' && !str_starts_with($line, '#')) {
        $extraTerms[] = $line;
        $needles[$line] = true;
    }
}

if ($extraTerms === []) {
    fwrite(STDERR, "privacy-check: var/privacy/extra.txt が無いか空です（クラブ名・家族の名前などの一覧）。止めます。\n");
    fwrite(STDERR, "  中身は Yoshi に聞いて作ってください（CLAUDE.md「公開リポジトリと個人情報」）。\n");
    exit(1);
}

$needles = array_keys($needles);

// ── GitHub Actions 用のハッシュ（語の平文は GitHub に渡さない） ─────────────

if ($mode === '--export-ci') {
    $saltFile = APP_ROOT . '/var/privacy/ci-salt';

    if (!is_file($saltFile)) {
        file_put_contents($saltFile, bin2hex(random_bytes(32)) . "\n");
        chmod($saltFile, 0600);
    }

    $salt = trim((string) file_get_contents($saltFile));

    // CI が見る語: extra.txt の語と、クラブの選手（extra.txt の語を名前かコードに含むチーム）の姓名。
    $terms = $extraTerms;
    $pdo = Database::instance()->pdo();
    $teamIds = [];

    foreach ($pdo->query('SELECT id, code, name FROM teams')->fetchAll(PDO::FETCH_NUM) as [$id, $code, $name]) {
        $teamWords = ' ' . implode(' ', PrivacyRules::words($code . ' ' . $name)) . ' ';

        foreach ($extraTerms as $term) {
            $termWords = implode(' ', PrivacyRules::words($term));

            if ($termWords !== '' && str_contains($teamWords, ' ' . $termWords . ' ')) {
                $teamIds[] = (int) $id;
                $terms[] = (string) $name;
                break;
            }
        }
    }

    if ($teamIds !== []) {
        $statement = $pdo->query('SELECT DISTINCT last_name, first_name FROM swimmers WHERE team_id IN (' . implode(',', $teamIds) . ')');

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$last, $first]) {
            if (mb_strlen(trim((string) $last)) >= 2 && mb_strlen(trim((string) $first)) >= 2) {
                $terms[] = "{$last} {$first}";
                $terms[] = "{$first} {$last}";
            }
        }
    }

    $hashes = [];

    foreach ($terms as $term) {
        $hash = PrivacyRules::termHash($salt, $term);

        if ($hash !== null) {
            $hashes[$hash] = true;
        }
    }

    // 塩は 1 行目、ハッシュは 2 行目以降（GitHub の秘密の値 PRIVACY_TERMS にそのまま入れる）。
    echo $salt, "\n", implode("\n", array_keys($hashes)), "\n";
    fwrite(STDERR, sprintf("privacy-check: CI 用に %d 語のハッシュを書き出しました（チーム %d）。\n", count($hashes), count($teamIds)));
    exit(0);
}


/** @return list<string> 見つかった語 */
$scan = static function (string $text) use ($needles): array {
    $text = mb_strtolower($text);

    foreach (PrivacyRules::ALLOWED_TERMS as $allowedTerm) {
        $text = str_replace($allowedTerm, '', $text);
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
$problems = [];

if ($mode === '--stdin') {
    $targets['渡された文章'] = (string) stream_get_contents(STDIN);
} elseif ($mode === '--staged') {
    $email = trim($git('config user.email'));

    if (!PrivacyRules::isPublicEmail($email)) {
        $problems[] = "コミットの作成者のメールアドレスが公開用ではありません: {$email}"
            . '（git config user.email に GitHub の users.noreply.github.com のアドレスを設定する）';
    }

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
        $targets["コミット {$commit} のメッセージ"] = $git('log -1 --format=%an%n%cn%n%B ' . escapeshellarg($commit));

        foreach (array_filter(explode("\n", $git('log -1 --format=%ae%n%ce ' . escapeshellarg($commit)))) as $email) {
            if (!PrivacyRules::isPublicEmail($email)) {
                $problems[] = "コミット {$commit} の作成者のメールアドレスが公開用ではありません: {$email}";
            }
        }
        // 差分は足した行（+）だけを見る。消した行は新しく公開するものではない
        // （公開済みの名前を消すコミットまで止めてしまわないように）。
        $diff = $git('show --format= --no-color --unified=0 ' . escapeshellarg($commit));
        $added = array_filter(explode("\n", $diff), static fn (string $line): bool => str_starts_with($line, '+'));
        $targets["コミット {$commit} の差分"] = implode("\n", $added);

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

foreach (array_unique($paths) as $path) {
    if (PrivacyRules::isForbiddenPath($path)) {
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

    foreach (PrivacyRules::findSecrets($content) as $kind) {
        $problems[] = "機密情報（{$kind}）: {$label}";
    }
}

if ($problems !== []) {
    fwrite(STDERR, "privacy-check: 公開リポジトリに入れてはいけないものが見つかりました。止めます。\n");

    foreach (array_slice(array_unique($problems), 0, 50) as $problem) {
        fwrite(STDERR, "  - {$problem}\n");
    }

    fwrite(STDERR, "架空の名前に置き換えるか、伏せるか、git 管理から外してから、やり直してください（CLAUDE.md の「公開リポジトリと個人情報」）。\n");
    fwrite(STDERR, "--no-verify で回避しないこと。\n");
    exit(1);
}

printf("privacy-check: OK（%d 件を %d 語で確認）\n", count($targets), count($needles));
exit(0);
