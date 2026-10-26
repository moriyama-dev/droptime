<?php

declare(strict_types=1);

use DropTime\Parser\ParserRegistry;
use DropTime\Tests\Assert;
use DropTime\Tests\AssertionFailed;

/**
 * フィクスチャ方式のパーサーテスト。
 *
 * tests/fixtures/ に <パーサー名>.<ケース名>.in.txt と
 * <パーサー名>.<ケース名>.expected.json を対で置くと、全件を自動で回す。
 * in.txt は pdftotext -layout の生出力（または HTML から取り出したテキスト）を
 * そのまま保存する。expected.json は ParseResult::toArray() と完全一致すること。
 *
 * 新しい書式に対応するときは、先にフィクスチャを足して既存のテストが
 * 壊れないことを確認してから直す。
 */
$cases = [];

$inputs = glob(APP_ROOT . '/tests/fixtures/*.in.txt');
$inputs = $inputs === false ? [] : $inputs;
sort($inputs, SORT_STRING);

foreach ($inputs as $inputPath) {
    $base = basename($inputPath, '.in.txt');
    $expectedPath = APP_ROOT . '/tests/fixtures/' . $base . '.expected.json';
    $parserName = explode('.', $base)[0];

    $cases['フィクスチャ ' . $base] = static function () use ($inputPath, $expectedPath, $parserName): void {
        if (!is_file($expectedPath)) {
            throw new AssertionFailed('期待値ファイルがありません: ' . basename($expectedPath));
        }

        $parser = ParserRegistry::default()->byName($parserName);

        if ($parser === null) {
            throw new AssertionFailed('フィクスチャ名の先頭がパーサー名になっていません: ' . $parserName);
        }

        $input = (string) file_get_contents($inputPath);
        $expected = json_decode((string) file_get_contents($expectedPath), true);

        if (!is_array($expected)) {
            throw new AssertionFailed('期待値 JSON を読めません: ' . basename($expectedPath));
        }

        $actual = $parser->parse($input)->toArray();

        // 差分を見つけやすいよう、JSON 文字列にして比較する。
        Assert::same(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    };
}

if ($cases === []) {
    $cases['フィクスチャ置き場が存在する'] = static function (): void {
        Assert::true(is_dir(APP_ROOT . '/tests/fixtures'));
    };
}

return $cases;
