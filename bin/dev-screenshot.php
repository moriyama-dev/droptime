<?php

declare(strict_types=1);

/**
 * 開発用: ログインした状態の画面をヘッドレス Chrome で撮る。
 *
 *   php bin/dev-screenshot.php <出力先ディレクトリ> <パス> [<パス> ...]
 *       [--base http://127.0.0.1] [--width 390] [--lang ja] [--admin] [--fav <選手ID>] [--js <撮る前に実行する JS>]
 *
 * 合言葉は環境変数 DROPTIME_PARENT_PASS / DROPTIME_ADMIN_PASS から読む
 * （本番と同じ合言葉なので、リポジトリには書かない）。
 * UI の確認用で、本番では使わない。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Evidence\HeadlessBrowser;

$base = 'http://127.0.0.1';
$width = 390;
$lang = 'ja';
$admin = false;
$favs = [];
$script = null;
$positional = [];
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    match ($args[$i]) {
        '--base' => $base = rtrim($args[++$i], '/'),
        '--width' => $width = (int) $args[++$i],
        '--lang' => $lang = $args[++$i],
        '--admin' => $admin = true,
        '--fav' => $favs[] = $args[++$i],
        '--js' => $script = $args[++$i],
        default => $positional[] = $args[$i],
    };
}
if (count($positional) < 2) {
    fwrite(STDERR, "使い方: php bin/dev-screenshot.php <出力先> <パス>...\n");
    exit(2);
}
if ((string) getenv('DROPTIME_PARENT_PASS') === '' || ($admin && (string) getenv('DROPTIME_ADMIN_PASS') === '')) {
    fwrite(STDERR, "環境変数 DROPTIME_PARENT_PASS（--admin のときは DROPTIME_ADMIN_PASS も）に合言葉を入れてください。\n");
    exit(2);
}
$outDir = array_shift($positional);
@mkdir($outDir, 0775, true);

$jar = tempnam(sys_get_temp_dir(), 'dtjar');
$curl = static function (string $url, ?array $post = null) use ($jar): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Accept-Language: ja'],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
};
$login = static function (string $path, string $pass) use ($curl, $base): void {
    $html = $curl($base . $path);
    preg_match('/name="_token" value="([a-f0-9]+)"/', $html, $m);
    $curl($base . $path, ['_token' => $m[1] ?? '', 'passphrase' => $pass]);
};
$login('/login', (string) getenv('DROPTIME_PARENT_PASS'));
foreach ($favs as $swimmerId) {
    $page = $curl($base . '/favorites');
    preg_match('/name="_token" value="([a-f0-9]+)"/', $page, $m);
    $curl($base . '/favorites/add', ['_token' => $m[1] ?? '', 'swimmer_id' => $swimmerId]);
}
if ($admin) {
    $login('/admin/login', (string) getenv('DROPTIME_ADMIN_PASS'));
}

$cookies = [];
foreach (file($jar) ?: [] as $line) {
    $cols = explode("\t", trim($line));
    if (count($cols) === 7) {
        $cookies[] = ['name' => $cols[5], 'value' => $cols[6]];
    }
}
unlink($jar);

$browser = new HeadlessBrowser(HeadlessBrowser::defaultPath());
foreach ($positional as $path) {
    $shot = $browser->capture($base . $path, [
        'width' => $width, 'height' => 844, 'cookies' => $cookies, 'lang' => $lang, 'wait_ms' => 1500,
    ] + ($script === null ? [] : ['script' => $script]));
    $name = trim(preg_replace('/[^a-z0-9]+/i', '-', $path) ?? 'page', '-') ?: 'home';
    file_put_contents("{$outDir}/{$name}.png", $shot['png']);
    echo "{$outDir}/{$name}.png ({$shot['status']})\n";
}
$browser->close();
