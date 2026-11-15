<?php

declare(strict_types=1);

use DropTime\Domain\NameSearch;
use DropTime\Tests\Assert;

$people = static fn (): array => [
    ['id' => 1, 'last_name' => 'Doe', 'first_name' => 'Jane'],
    ['id' => 2, 'last_name' => 'Tanabe', 'first_name' => 'Hana'],
    ['id' => 3, 'last_name' => 'Kowalski', 'first_name' => 'Catherine'],
    ['id' => 4, 'last_name' => 'Tanaka', 'first_name' => 'Hanako'],
    ['id' => 5, 'last_name' => 'Doerr', 'first_name' => 'Janet'],
    ['id' => 6, 'last_name' => 'Tanaka', 'first_name' => 'Hanako', 'preferred' => true],
];
$ids = static fn (array $r): array => array_map(static fn (array $h): int => $h['id'], $r['hits']);

return [
    '名・姓の順でも当たる' => static function () use ($people, $ids): void {
        $r = NameSearch::search('Hana Tanabe', $people());
        Assert::true($r['exact']);
        Assert::same(2, $ids($r)[0]);
    },
    '姓・名の順でも当たる' => static function () use ($people, $ids): void {
        Assert::same(2, $ids(NameSearch::search('tanabe hana', $people()))[0]);
    },
    '打ちかけの語でも当たる' => static function () use ($people, $ids): void {
        $found = $ids(NameSearch::search('han tanab', $people()));
        Assert::same([2], $found);
    },
    '完全一致が打ちかけより上に来る' => static function () use ($people, $ids): void {
        $found = $ids(NameSearch::search('jane doe', $people()));
        Assert::same(1, $found[0]);
    },
    '入力の語はすべて当たらないといけない' => static function () use ($people, $ids): void {
        Assert::same([], $ids(NameSearch::search('jane tanaka', $people())));
    },
    '1 文字の打ち間違いを推測する（もしかして）' => static function () use ($people, $ids): void {
        $r = NameSearch::search('Hana Tanbe', $people());
        Assert::false($r['exact']);
        Assert::same(2, $ids($r)[0]);
    },
    '隣どうしの入れ替えを 1 か所と数える' => static function () use ($people, $ids): void {
        Assert::same(1, NameSearch::editDistance('tanbae', 'tanabe'));
        $r = NameSearch::search('hana tnaabe', $people());
        Assert::same(2, $ids($r)[0]);
    },
    '発音が同じ綴りを拾う' => static function () use ($people, $ids): void {
        $r = NameSearch::search('Katherine Kowalski', $people());
        Assert::same(3, $ids($r)[0]);
    },
    '空白を入れ忘れても当たる' => static function () use ($people, $ids): void {
        Assert::same([2], $ids(NameSearch::search('hanatanabe', $people())));
    },
    '短すぎる語は綴りの推測をしない' => static function () use ($people, $ids): void {
        Assert::same([], $ids(NameSearch::search('xoe', $people())));
    },
    '綴りどおりに当たる選手がいれば推測の結果は混ぜない' => static function () use ($people, $ids): void {
        $r = NameSearch::search('tanaka', $people());
        Assert::true($r['exact']);
        Assert::false(in_array(2, $ids($r), true));
    },
    '同点なら自分のクラブを上に出す' => static function () use ($people, $ids): void {
        Assert::same([6, 4], $ids(NameSearch::search('hanako tanaka', $people())));
    },
    '空の入力は何も返さない' => static function () use ($people, $ids): void {
        Assert::same([], $ids(NameSearch::search('  ', $people())));
    },
];
