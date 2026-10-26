<?php

declare(strict_types=1);

/**
 * GitHub Actions 側の個人情報の見張り（.github/workflows/privacy.yml が呼ぶ）。
 *
 *   PRIVACY_TERMS=<秘密の値> php bin/privacy-ci.php <前のコミット> <今のコミット>
 *
 * VM の git フック（bin/privacy-check.php）を通らない変更、たとえば GitHub の Web 画面での
 * 直接編集を、push の直後に見つけるためのもの。見つかったら失敗し、GitHub から Yoshi に
 * メールが届く（公開を止めることはできないので、すぐ消すための合図）。
 *
 * GitHub には語の平文を渡さない。秘密の値 PRIVACY_TERMS は 1 行目が塩、2 行目以降が語のハッシュ
 * （VM で bin/privacy-sync-github.sh が作って登録する）。見つけた語は出力せず、ファイルと行番号だけを出す。
 * 秘密の値が無いときは確かめられないので失敗する。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/Support/PrivacyRules.php';

use DropTime\Support\PrivacyRules;

$lines = array_values(array_filter(array_map('trim', explode("\n", (string) getenv('PRIVACY_TERMS')))));

if (count($lines) < 2) {
    fwrite(STDERR, "privacy-ci: 秘密の値 PRIVACY_TERMS がありません。確かめられないので失敗にします（VM で bin/privacy-sync-github.sh を実行する）。\n");
    exit(1);
}

$salt = array_shift($lines);
$hashes = array_fill_keys($lines, true);

$before = $argv[1] ?? '';
$after = $argv[2] ?? 'HEAD';
$git = static fn (string $args): string => (string) shell_exec('git ' . $args . ' 2>/dev/null');

$targets = [];
$problems = [];
$paths = array_filter(explode("\0", $git('ls-tree -r -z --name-only ' . escapeshellarg($after))));

foreach ($paths as $path) {
    $targets[$path] = $git('show ' . escapeshellarg($after . ':' . $path));
}

// 前のコミットが分かるとき（ふつうの push）だけ、新しく入ったコミットを見る。
// 初回・手動・定期の実行は先端のツリーだけを見る（公開済みの古い履歴では失敗させない）。
$range = preg_match('/^0*$/', $before) === 1 ? '' : "{$before}..{$after}";

foreach ($range === '' ? [] : array_filter(explode("\n", $git('rev-list ' . escapeshellarg($range)))) as $commit) {
    $short = substr($commit, 0, 7);
    $targets["コミット {$short} のメッセージ"] = $git('log -1 --format=%an%n%cn%n%B ' . escapeshellarg($commit));

    foreach (array_filter(explode("\n", $git('log -1 --format=%ae%n%ce ' . escapeshellarg($commit)))) as $email) {
        if (!PrivacyRules::isPublicEmail($email)) {
            $problems[] = "コミット {$short} の作成者のメールアドレスが公開用ではない";
        }
    }

    foreach (array_filter(explode("\0", $git('show --format= --name-only -z ' . escapeshellarg($commit)))) as $path) {
        $paths[] = $path;
    }
}

foreach (array_unique($paths) as $path) {
    if (PrivacyRules::isForbiddenPath($path)) {
        $problems[] = "公開してはいけないファイル: {$path}";
    }
}

foreach ($targets as $label => $content) {
    if (str_contains(substr($content, 0, 8000), "\0")) {
        continue;
    }

    $hit = PrivacyRules::findHashedTerms($salt, $hashes, $content);

    if ($hit !== []) {
        $problems[] = "個人名・個人情報: {$label}（" . implode(', ', array_slice(array_unique($hit), 0, 10)) . ' 行目）';
    }

    foreach (PrivacyRules::findSecrets($content) as $kind) {
        $problems[] = "機密情報（{$kind}）: {$label}";
    }
}

if ($problems !== []) {
    echo "privacy-ci: 公開リポジトリに出してはいけないものが見つかりました。すぐに伏せてください。\n";

    foreach (array_slice(array_unique($problems), 0, 100) as $problem) {
        echo "  - {$problem}\n";
    }

    exit(1);
}

printf("privacy-ci: OK（%d 件を %d 語で確認）\n", count($targets), count($hashes));
