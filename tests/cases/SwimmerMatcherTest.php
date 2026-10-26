<?php

declare(strict_types=1);

use DropTime\Matching\SwimmerMatcher;
use DropTime\Tests\Assert;

/**
 * 判定ロジック（decide）のみを検証する。DB には触れない。
 * 候補は「同じ normalized_key を持つ既存選手」の行。
 */
return [
    '既存が居なければ新規作成' => static function (): void {
        $d = SwimmerMatcher::decide([], 2014, 1);
        Assert::same('create', $d['action']);
        Assert::same([], $d['review']);
    },
    '生年まで一致する 1 人が居れば再利用' => static function (): void {
        $d = SwimmerMatcher::decide(
            [['id' => 5, 'birth_year' => 2014, 'gender' => 'F', 'team_id' => 1]],
            2014,
            2 // チームが違っても（移籍）生年一致なら同一人物
        );
        Assert::same('reuse', $d['action']);
        Assert::same(5, $d['id']);
    },
    '同姓同名・同生年が複数なら保留' => static function (): void {
        $d = SwimmerMatcher::decide(
            [
                ['id' => 5, 'birth_year' => 2014, 'gender' => null, 'team_id' => 1],
                ['id' => 6, 'birth_year' => 2014, 'gender' => null, 'team_id' => 2],
            ],
            2014,
            1
        );
        Assert::same('create', $d['action']);
        Assert::same([5, 6], $d['review']);
        Assert::true($d['reason'] !== '');
    },
    '生年未記入の既存 1 人・同チームなら再利用（生年が判明した）' => static function (): void {
        $d = SwimmerMatcher::decide(
            [['id' => 7, 'birth_year' => null, 'gender' => null, 'team_id' => 3]],
            2013,
            3
        );
        Assert::same('reuse', $d['action']);
        Assert::same(7, $d['id']);
    },
    '生年未記入の既存が別チームなら保留' => static function (): void {
        $d = SwimmerMatcher::decide(
            [['id' => 7, 'birth_year' => null, 'gender' => null, 'team_id' => 3]],
            2013,
            4
        );
        Assert::same('create', $d['action']);
        Assert::same([7], $d['review']);
    },
    '生年が全員違えば同名の別人として新規作成' => static function (): void {
        $d = SwimmerMatcher::decide(
            [['id' => 8, 'birth_year' => 2010, 'gender' => null, 'team_id' => 1]],
            2015,
            1
        );
        Assert::same('create', $d['action']);
        Assert::same([], $d['review'], '生年が明確に違うなら保留にもしない');
    },
    '今回の生年が不明でも候補 1 人・同チームなら再利用' => static function (): void {
        $d = SwimmerMatcher::decide(
            [['id' => 9, 'birth_year' => 2012, 'gender' => null, 'team_id' => 2]],
            null,
            2
        );
        Assert::same('reuse', $d['action']);
        Assert::same(9, $d['id']);
    },
    '今回の生年が不明で候補が複数なら保留' => static function (): void {
        $d = SwimmerMatcher::decide(
            [
                ['id' => 9, 'birth_year' => 2012, 'gender' => null, 'team_id' => 2],
                ['id' => 10, 'birth_year' => 2014, 'gender' => null, 'team_id' => 2],
            ],
            null,
            2
        );
        Assert::same('create', $d['action']);
        Assert::same([9, 10], $d['review']);
    },
];
