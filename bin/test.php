<?php

declare(strict_types=1);

/**
 * テストランナー。PHPUnit は入れない（依存ゼロの方針）。
 *
 *   php bin/test.php
 *
 * tests/cases/*.php は 'テスト名' => クロージャ の配列を return する。
 * パーサーのフィクスチャ方式のテストは M3 でここに追加する。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';
require APP_ROOT . '/tests/Assert.php';

use DropTime\Tests\AssertionFailed;

$files = glob(APP_ROOT . '/tests/cases/*.php');
$files = $files === false ? [] : $files;
sort($files, SORT_STRING);

$passed = 0;
$failures = [];

foreach ($files as $file) {
    /** @var array<string, callable> $cases */
    $cases = require $file;
    $group = basename($file, '.php');

    foreach ($cases as $name => $case) {
        $label = $group . ' / ' . $name;

        try {
            $case();
            $passed++;
            echo "\033[32m.\033[0m";
        } catch (AssertionFailed $e) {
            $failures[] = $label . "\n    " . $e->getMessage();
            echo "\033[31mF\033[0m";
        } catch (Throwable $e) {
            $failures[] = $label . "\n    " . $e::class . ': ' . $e->getMessage()
                . "\n    " . $e->getFile() . ':' . $e->getLine();
            echo "\033[31mE\033[0m";
        }
    }
}

echo "\n\n";

foreach ($failures as $failure) {
    echo "\033[31m失敗\033[0m " . $failure . "\n\n";
}

printf("%d 件成功, %d 件失敗\n", $passed, count($failures));

exit($failures === [] ? 0 : 1);
