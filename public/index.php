<?php

declare(strict_types=1);

/**
 * DropTime のフロントコントローラ。公開されるのはこのファイルと assets だけ。
 *
 * ドキュメントルートを public/ 以外にした場合は、.htaccess の
 * SetEnv DROPTIME_ROOT でアプリ本体の絶対パスを渡す。
 */
$root = getenv('DROPTIME_ROOT');
define('APP_ROOT', is_string($root) && $root !== '' ? rtrim($root, '/') : dirname(__DIR__));

require APP_ROOT . '/src/bootstrap.php';

(new DropTime\Http\Kernel())->run();
