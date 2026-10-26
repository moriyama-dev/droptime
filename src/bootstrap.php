<?php

declare(strict_types=1);

/**
 * 依存ライブラリを持たないため、オートローダは自前で用意する。
 * PSR-4: DropTime\ => src/
 */
if (!defined('APP_ROOT')) {
    throw new RuntimeException('APP_ROOT が定義されていません。');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'DropTime\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = APP_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
