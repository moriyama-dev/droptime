<?php

declare(strict_types=1);

use DropTime\Evidence\EvidenceStore;
use DropTime\Tests\Assert;

/** 原本 2 ファイルを持つ一時ディレクトリと、空の保存先を用意する。 */
function evidenceFixture(): array
{
    $base = sys_get_temp_dir() . '/dt-evidence-' . bin2hex(random_bytes(4));
    mkdir($base . '/src', 0775, true);
    file_put_contents($base . '/src/a.htm', '<pre>Zedoseme, Biti  9 ZORC  38.74</pre>');
    file_put_contents($base . '/src/b.htm', '<pre>other</pre>');

    return [$base, new EvidenceStore($base . '/evidence')];
}

return [
    '原本を登録すると検証が通り、台帳に 1 行入る' => static function (): void {
        [$base, $store] = evidenceFixture();
        $meta = $store->registerOriginals($base . '/src', 'https://example.org/meet/', [11]);

        Assert::same('originals', $meta['kind']);
        Assert::same(2, count($meta['files']));
        Assert::same(true, $store->verify($meta['id'])['ok']);
        Assert::same(['ok' => true, 'entries' => 1], array_intersect_key($store->verifyLedger(), ['ok' => 1, 'entries' => 1]));
        Assert::same(1, count($store->all()));
        exec('rm -rf ' . escapeshellarg($base));
    },
    'ファイルを書き換えると検証が落ちる' => static function (): void {
        [$base, $store] = evidenceFixture();
        $meta = $store->registerOriginals($base . '/src', 'https://example.org/meet/');
        file_put_contents($store->root() . '/' . $meta['id'] . '/files/a.htm', '<pre>Zedoseme, Biti  9 ZORC  30.00</pre>');

        $check = $store->verify($meta['id']);
        Assert::same(false, $check['ok']);
        Assert::same(false, $check['files']['files/a.htm']);
        Assert::same(true, $check['files']['files/b.htm']);
        exec('rm -rf ' . escapeshellarg($base));
    },
    'meta.json を書き換えると台帳の鎖が切れる' => static function (): void {
        [$base, $store] = evidenceFixture();
        $first = $store->registerOriginals($base . '/src', 'https://example.org/one/');
        $store->registerOriginals($base . '/src', 'https://example.org/two/');
        $path = $store->root() . '/' . $first['id'] . '/meta.json';
        file_put_contents($path, str_replace('example.org/one', 'example.org/forged', (string) file_get_contents($path)));

        $ledger = $store->verifyLedger();
        Assert::same(false, $ledger['ok']);
        Assert::same(1, $ledger['broken_at']);
        exec('rm -rf ' . escapeshellarg($base));
    },
    '不正な ID やパスは拒否する' => static function (): void {
        [$base, $store] = evidenceFixture();
        $meta = $store->registerOriginals($base . '/src', 'https://example.org/meet/');

        Assert::same(null, $store->find('../etc'));
        Assert::same(null, $store->filePath($meta['id'], '../meta.json'));
        Assert::same(null, $store->filePath($meta['id'], 'files/../../x'));
        Assert::true($store->filePath($meta['id'], 'files/a.htm') !== null);
        exec('rm -rf ' . escapeshellarg($base));
    },
    'トロント時間で表示する' => static function (): void {
        Assert::same('2026-10-23 19:41 EDT', EvidenceStore::localTime('2026-10-23T23:41:38Z'));
        Assert::same('2026-01-10 07:00 EST', EvidenceStore::localTime('2026-01-10T12:00:00Z'));
    },
];
