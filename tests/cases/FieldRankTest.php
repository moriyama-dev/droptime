<?php

declare(strict_types=1);

use DropTime\Domain\FieldRank;
use DropTime\Tests\Assert;

/** @return array<string, mixed> */
function rankRow(int $timeCs, ?int $age = null, string $status = 'OK'): array
{
    return ['final_time_cs' => $status === 'OK' ? $timeCs : null, 'age' => $age, 'status' => $status];
}

return [
    '全体順位はタイムから数える' => static function (): void {
        $me = rankRow(3874, 9);
        $field = [rankRow(3482, 10), rankRow(3499, 10), rankRow(3501, 10), $me, rankRow(3929, 9)];

        $rank = FieldRank::of($me, $field);

        Assert::same(4, $rank['overall']['rank']);
        Assert::same(5, $rank['overall']['count']);
        Assert::same(1, $rank['overall']['beaten'], '後ろは 1 人');
    },
    '同い年だけに絞ると順位が変わる' => static function (): void {
        // 実データの縮図: 10 歳が上位を占め、9 歳の中では先頭という形。
        $me = rankRow(3874, 9);
        $field = [rankRow(3482, 10), rankRow(3499, 10), rankRow(3501, 10), $me, rankRow(3929, 9), rankRow(3950, 9)];

        $rank = FieldRank::of($me, $field);

        Assert::same('peer', $rank['basis'], '同年齢が主役になる');
        Assert::same(1, $rank['peer']['rank']);
        Assert::same(3, $rank['peer']['count']);
        Assert::same(9, $rank['peer']['age']);
        Assert::same(4, $rank['overall']['rank'], '全体順位も併記できる');
    },
    'すぐ上・すぐ下との差が出る' => static function (): void {
        $me = rankRow(5054, 8);
        $field = [rankRow(4846, 8), rankRow(5010, 8), $me, rankRow(5300, 8)];

        $rank = FieldRank::of($me, $field);

        Assert::same(44, $rank['peer']['gap_ahead_cs'], '1 つ上まで 0.44 秒');
        Assert::same(246, $rank['peer']['gap_behind_cs'], '1 つ下まで 2.46 秒');
    },
    '1 位なら上との差は無い' => static function (): void {
        $me = rankRow(3800, 9);
        $rank = FieldRank::of($me, [$me, rankRow(3874, 9), rankRow(3900, 9)]);

        Assert::same(1, $rank['peer']['rank']);
        Assert::null($rank['peer']['gap_ahead_cs']);
        Assert::same(74, $rank['peer']['gap_behind_cs']);
    },
    '同着は同順位' => static function (): void {
        $me = rankRow(4000, 9);
        $rank = FieldRank::of($me, [rankRow(3900, 9), rankRow(4000, 9), $me, rankRow(4100, 9)]);

        Assert::same(2, $rank['peer']['rank'], '同着の 2 人はどちらも 2 位');
        Assert::same(100, $rank['peer']['gap_ahead_cs'], '同着は「1 つ上」ではなく、その先の選手との差を出す');
        Assert::same(100, $rank['peer']['gap_behind_cs']);
    },
    '同い年が 1 人しか居なければ同年齢順位は出さない' => static function (): void {
        $me = rankRow(5054, 8);
        $rank = FieldRank::of($me, [rankRow(4846, 10), rankRow(5010, 10), $me]);

        Assert::null($rank['peer'], '自分だけの「1 位」は作らない');
        Assert::same('overall', $rank['basis']);
    },
    '年齢が無ければ全体順位だけ' => static function (): void {
        $me = rankRow(5054);
        $rank = FieldRank::of($me, [rankRow(4846), rankRow(5010), $me]);

        Assert::null($rank['peer']);
        Assert::same(3, $rank['overall']['rank']);
    },
    '失格には順位が無い' => static function (): void {
        $me = rankRow(0, 8, 'DQ');

        Assert::null(FieldRank::of($me, [rankRow(4846, 8), rankRow(5010, 8), $me]));
    },
    '非計時の行は母数に入らない' => static function (): void {
        $me = rankRow(5054, 8);
        $rank = FieldRank::of($me, [rankRow(4846, 8), rankRow(0, 8, 'DQ'), rankRow(0, 8, 'NS'), $me]);

        Assert::same(2, $rank['overall']['count'], '完泳した 2 人だけが母数');
        Assert::same(2, $rank['overall']['rank']);
    },
    '上位何パーセントかを出す' => static function (): void {
        $field = [];

        for ($i = 0; $i < 100; $i++) {
            $field[] = rankRow(3000 + $i * 10, 9);
        }

        $me = $field[9]; // 10 位 / 100 人

        Assert::same(10, FieldRank::of($me, $field)['peer']['top_percent']);
    },
];
