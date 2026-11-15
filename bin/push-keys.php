<?php

declare(strict_types=1);

/**
 * 携帯への通知（Web Push）の送り主の鍵（VAPID）を作り、config/config.php に貼る形で表示する。
 *
 *   php bin/push-keys.php
 *
 * 鍵は本番と VM で別にしてよい。作り直すと、それまでの購読はすべて使えなくなる
 * （各端末で通知をオンにし直す必要がある）ので、本番では 1 度作ったら変えない。
 * 秘密鍵は config.php にだけ置く（git には入れない）。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

$keys = DropTime\Push\WebPush::generateVapidKeys();

echo "    'push' => [\n";
echo "        'vapid_private' => <<<'PEM'\n" . rtrim($keys['private_pem']) . "\nPEM,\n";
echo "        'subject' => 'https://droptime.takumi.ca/',\n";
echo "        'lead_minutes' => [30, 10],\n";
echo "    ],\n";
