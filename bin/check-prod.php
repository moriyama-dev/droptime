<?php

declare(strict_types=1);

/**
 * デプロイ前後の点検。本番サーバー（DreamHost）の SSH で実行する。
 *
 *   php bin/check-prod.php
 *
 * 落ちる項目が 1 つでもあれば終了コード 1。公開前に全項目が OK であること。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Support\Config;

$ok = 0;
$ng = 0;

$check = static function (string $label, bool $passed, string $hint = '') use (&$ok, &$ng): void {
    if ($passed) {
        $ok++;
        echo "  OK   {$label}\n";
    } else {
        $ng++;
        echo "  NG   {$label}" . ($hint !== '' ? "  ← {$hint}" : '') . "\n";
    }
};

echo "1. PHP\n";
$check('PHP 8.2 以上', PHP_VERSION_ID >= 80200, '現在 ' . PHP_VERSION);

foreach (['mbstring', 'pdo_mysql', 'dom', 'json'] as $extension) {
    $check("拡張 {$extension}", extension_loaded($extension));
}

$check('拡張 gd（アイコン再生成にのみ必要・任意）', extension_loaded('gd'), '無くても動く');

echo "2. 設定\n";
$configPath = APP_ROOT . '/config/config.php';
$check('config/config.php がある', is_file($configPath), 'config.example.php をコピーして値を埋める');

if (!is_file($configPath)) {
    printf("\nOK %d / NG %d\n", $ok, $ng);
    exit(1);
}

$config = Config::load($configPath);

$check('app.env が prod', $config->string('app.env') === 'prod', '本番では必ず prod（エラー詳細を画面に出さない）');
$check('security.cookie_secure が true', $config->bool('security.cookie_secure', false), 'HTTPS 前提。false のままにしない');
$check('保護者の合言葉が設定済み', $config->string('auth.password_hash') !== '');
$check(
    '管理の合言葉が保護者と別物',
    $config->string('auth.admin_password_hash') !== ''
    && $config->string('auth.admin_password_hash') !== $config->string('auth.password_hash'),
    '空なら管理画面は無効のまま'
);
$check('タイムゾーンが妥当', in_array($config->string('app.timezone'), DateTimeZone::listIdentifiers(), true));

echo "3. ディレクトリとファイル\n";

foreach (['uploads', 'logs', 'backups'] as $name) {
    $path = $config->string('paths.' . $name, APP_ROOT . '/var/' . $name);
    $check("var/{$name} が書き込み可能", is_dir($path) && is_writable($path));
}

// 出典の証拠。無くても動くが、「公開されていた」ことを示せなくなる。
$evidenceStore = new DropTime\Evidence\EvidenceStore($config->string('paths.evidence', APP_ROOT . '/var/evidence'));
$evidenceCount = count($evidenceStore->all());
$check('出典の証拠（var/evidence）が届いている', $evidenceCount > 0, $evidenceCount . ' 件');
$check('出典の証拠の台帳が改ざんされていない', $evidenceStore->verifyLedger()['ok']);

$check('var/.htaccess（二重防御）がある', is_file(APP_ROOT . '/var/.htaccess'));
$check('public/robots.txt がある', is_file(APP_ROOT . '/public/robots.txt'));
$check('public/.htaccess がある', is_file(APP_ROOT . '/public/.htaccess'));
$check('config が公開ディレクトリの外', !str_starts_with(realpath($configPath) ?: '', realpath(APP_ROOT . '/public') ?: '~'));

echo "4. 外部コマンド\n";
$pdftotext = $config->string('tools.pdftotext', '/usr/bin/pdftotext');
$check('pdftotext がある', is_file($pdftotext) && is_executable($pdftotext), $pdftotext);
$mysqldump = $config->string('tools.mysqldump', '/usr/bin/mysqldump');
$check('mysqldump がある（無ければ backup.php --php を使う）', is_file($mysqldump) && is_executable($mysqldump), '任意');

echo "5. データベース\n";

try {
    $db = Database::instance();
    $db->selectValue('SELECT 1');
    $check('接続できる', true);

    $charset = $db->selectOne("SHOW VARIABLES LIKE 'character_set_connection'");
    $check('接続の文字セットが utf8mb4', ($charset['Value'] ?? '') === 'utf8mb4');

    $applied = array_map(
        static fn (array $row): string => (string) $row['filename'],
        $db->select('SELECT filename FROM migrations ORDER BY filename')
    );
    $files = array_map('basename', glob(APP_ROOT . '/migrations/*.sql') ?: []);
    sort($files, SORT_STRING);
    $pending = array_diff($files, $applied);
    $check(
        'マイグレーションが全部適用済み',
        $pending === [],
        $pending === [] ? '' : '未適用: ' . implode(', ', $pending) . ' → php bin/migrate.php'
    );
} catch (Throwable $e) {
    $check('接続できる', false, 'config の db.* を確認');
}

printf("\nOK %d / NG %d\n", $ok, $ng);
exit($ng === 0 ? 0 : 1);
