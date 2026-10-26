<?php

declare(strict_types=1);

/**
 * 本番の PHP は 8.2、開発 VM は 8.3 のため、8.3 以降でしか動かない書き方が
 * 紛れ込んでいないかを機械的に検査する。php -l と併せて実行する。
 *
 *   php bin/lint-php82.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$directories = ['/src', '/templates', '/bin', '/public', '/tests', '/lang'];

/** @var array<string, string> 正規表現 => 説明 */
$forbidden = [
    '/\bjson_validate\s*\(/' => 'json_validate() は PHP 8.3 以降',
    '/\bmb_str_pad\s*\(/' => 'mb_str_pad() は PHP 8.3 以降',
    '/\bstream_context_set_options\s*\(/' => 'stream_context_set_options() は PHP 8.3 以降',
    '/#\[\s*\\\\?Override\s*\]/' => '#[\Override] は PHP 8.3 以降',
    '/\bReflectionMethod::createFromMethodName\b/' => 'PHP 8.3 以降',
    '/\bgetBytesFromString\s*\(/' => 'Randomizer::getBytesFromString() は PHP 8.3 以降',
    '/\bgetFloat\s*\(/' => 'Randomizer::getFloat() は PHP 8.3 以降',
    '/\bDateTime(?:Immutable)?::createFromTimestamp\b/' => 'PHP 8.4 以降',
    '/\barray_(?:find|any|all)\s*\(/' => 'PHP 8.4 以降',
    '/^\s*(?:public|protected|private)\s+const\s+[a-zA-Z_\\\\|]+\s+[A-Z_]/m' => '型付きクラス定数は PHP 8.3 以降',
];

$violations = [];

foreach ($directories as $directory) {
    $path = $root . $directory;

    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        // 検査パターンそのものを持つこのファイルは対象外。
        if ($file->getRealPath() === __FILE__) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            continue;
        }

        foreach ($forbidden as $pattern => $reason) {
            if (preg_match($pattern, $contents) === 1) {
                $violations[] = sprintf('%s: %s', str_replace($root . '/', '', $file->getPathname()), $reason);
            }
        }
    }
}

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, 'PHP 8.2 互換性違反: ' . $violation . "\n");
    }

    exit(1);
}

echo "PHP 8.2 互換性チェック: 問題なし\n";
