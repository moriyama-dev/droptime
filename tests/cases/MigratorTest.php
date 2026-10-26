<?php

declare(strict_types=1);

use DropTime\Db\Migrator;
use DropTime\Tests\Assert;

return [
    '単純な文を分割する' => static function (): void {
        $statements = Migrator::splitStatements("SELECT 1; SELECT 2;");
        Assert::same(2, count($statements));
        Assert::same('SELECT 1', $statements[0]);
    },
    '行コメントを除去する' => static function (): void {
        $statements = Migrator::splitStatements("-- コメント; ここは区切りではない\nSELECT 1;");
        Assert::same(1, count($statements));
        Assert::same('SELECT 1', $statements[0]);
    },
    'ブロックコメントを除去する' => static function (): void {
        $statements = Migrator::splitStatements("/* a; b */ SELECT 1;");
        Assert::same(1, count($statements));
    },
    '文字列リテラル内のセミコロンで分割しない' => static function (): void {
        $statements = Migrator::splitStatements("INSERT INTO t VALUES ('a;b'); SELECT 1;");
        Assert::same(2, count($statements));
        Assert::same("INSERT INTO t VALUES ('a;b')", $statements[0]);
    },
    'バッククォート内のセミコロンで分割しない' => static function (): void {
        $statements = Migrator::splitStatements("CREATE TABLE `a;b` (id INT);");
        Assert::same(1, count($statements));
    },
    '末尾のセミコロンが無くても 1 文として扱う' => static function (): void {
        Assert::same(1, count(Migrator::splitStatements('SELECT 1')));
    },
    '初期マイグレーションを分割できる' => static function (): void {
        $sql = (string) file_get_contents(APP_ROOT . '/migrations/001_initial.sql');
        $statements = Migrator::splitStatements($sql);

        Assert::same(13, count($statements), 'CREATE TABLE の数と一致すること');

        foreach ($statements as $statement) {
            Assert::true(
                str_starts_with($statement, 'CREATE TABLE'),
                '想定外の文: ' . substr($statement, 0, 40)
            );
        }
    },
];
