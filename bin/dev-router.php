<?php

declare(strict_types=1);

/**
 * 開発用サーバのルータースクリプト。本番では使わない（Apache の .htaccess が同じ役目をする）。
 *
 *   php -S 127.0.0.1:8787 -t public bin/dev-router.php
 */
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$file = __DIR__ . '/../public' . $path;

// 実在する静的ファイルは組み込みサーバにそのまま配信させる。
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}

require __DIR__ . '/../public/index.php';
