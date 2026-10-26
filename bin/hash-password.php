<?php

declare(strict_types=1);

/**
 * 合言葉のハッシュを生成する。
 * 引数ではなく標準入力から読む（シェルの履歴とプロセス一覧に残さないため）。
 *
 *   php bin/hash-password.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$isTty = stream_isatty(STDIN);

if ($isTty) {
    echo "合言葉を入力してください（表示されません）: ";
    shell_exec('stty -echo');
}

$password = fgets(STDIN);

if ($isTty) {
    shell_exec('stty echo');
    echo "\n";
}

if ($password === false) {
    fwrite(STDERR, "入力を読めませんでした。\n");
    exit(1);
}

$password = rtrim($password, "\r\n");

if (strlen($password) < 8) {
    fwrite(STDERR, "8 文字以上にしてください。\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), "\n";
