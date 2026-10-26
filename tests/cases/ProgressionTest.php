<?php

declare(strict_types=1);

use DropTime\Domain\Progression;
use DropTime\Tests\Assert;

/** @return array<string, mixed> */
function progressionRow(string $date, int $timeCs, string $course = 'SCM', string $status = 'OK', int $isRelay = 0, int $sortOrder = 0, ?int $age = null, int $eventId = 1): array
{
    return [
        'start_date' => $date,
        'sort_order' => $sortOrder,
        'final_time_cs' => $timeCs,
        'status' => $status,
        'is_relay' => $isRelay,
        'distance' => 50,
        'stroke' => 'FR',
        'course' => $course,
        'meet_id' => 1,
        'meet_name' => 'Test Meet',
        'event_id' => $eventId,
        'round' => 'timed',
        'age' => $age,
    ];
}

return [
    '時系列に並び自己ベストが付く' => static function (): void {
        $shapes = Progression::build([
            progressionRow('2026-02-08', 4380),
            progressionRow('2025-10-19', 4512),
        ]);

        Assert::same(1, count($shapes));
        $swims = $shapes[0]['swims'];
        Assert::same('2025-10-19', $swims[0]['date'], '古い順に並ぶ');
        Assert::true($swims[0]['is_pb'], '初記録もベスト扱い');
        Assert::null($swims[0]['delta_cs']);
        Assert::true($swims[1]['is_pb']);
        Assert::same(-132, $swims[1]['delta_cs']);
        Assert::same(4380, $shapes[0]['best']['time_cs']);
    },
    '遅くなった泳ぎはベスト扱いにならない' => static function (): void {
        $shapes = Progression::build([
            progressionRow('2025-10-19', 3850),
            progressionRow('2026-02-08', 3910),
        ]);

        $swims = $shapes[0]['swims'];
        Assert::false($swims[1]['is_pb']);
        Assert::same(60, $swims[1]['delta_cs']);
        Assert::same(3850, $shapes[0]['best']['time_cs'], 'ベストは古いほうのまま');
    },
    'コースが違えば別系列' => static function (): void {
        $shapes = Progression::build([
            progressionRow('2025-10-19', 4512, 'SCM'),
            progressionRow('2026-05-24', 4690, 'LCM'),
        ]);

        Assert::same(2, count($shapes));
    },
    'リレーと非計時は系列に入れない' => static function (): void {
        $shapes = Progression::build([
            progressionRow('2025-10-19', 4512),
            progressionRow('2026-02-08', 4380, 'SCM', 'DQ'),
            progressionRow('2026-03-01', 4300, 'SCM', 'OK', 1),
        ]);

        Assert::same(1, count($shapes));
        Assert::same(1, count($shapes[0]['swims']));
    },
    '初回から自己ベストまでの累計短縮が出る' => static function (): void {
        $shapes = Progression::build([
            progressionRow('2025-07-18', 11804),
            progressionRow('2026-02-20', 10878),
        ]);

        // 実データ: Aria の 100m 個人メドレー（SCM）は 9.26 秒縮んでいる。
        Assert::same(926, $shapes[0]['total_drop_cs']);
    },
    '遅くなっても累計短縮は負にならない' => static function (): void {
        $shapes = Progression::build([
            progressionRow('2025-10-19', 3850),
            progressionRow('2026-02-08', 3910),
        ]);

        Assert::same(0, $shapes[0]['total_drop_cs'], 'ベストが初回のままなら 0');
    },
    'withRanks が各泳ぎに同年齢順位を付ける' => static function (): void {
        $shapes = Progression::build([
            progressionRow('2025-07-18', 5566, 'SCM', 'OK', 0, 0, 8, 101),
            progressionRow('2026-02-20', 5054, 'SCM', 'OK', 0, 0, 8, 102),
        ]);

        $fields = [
            101 => [
                ['final_time_cs' => 5200, 'age' => 8, 'status' => 'OK'],
                ['final_time_cs' => 5566, 'age' => 8, 'status' => 'OK'],
                ['final_time_cs' => 5800, 'age' => 8, 'status' => 'OK'],
                ['final_time_cs' => 4900, 'age' => 10, 'status' => 'OK'],
            ],
            102 => [
                ['final_time_cs' => 4846, 'age' => 8, 'status' => 'OK'],
                ['final_time_cs' => 5010, 'age' => 8, 'status' => 'OK'],
                ['final_time_cs' => 5054, 'age' => 8, 'status' => 'OK'],
                ['final_time_cs' => 4500, 'age' => 10, 'status' => 'OK'],
            ],
        ];

        $shapes = Progression::withRanks($shapes, $fields);
        $swims = $shapes[0]['swims'];

        Assert::same(2, $swims[0]['rank']['peer']['rank'], '8 歳 3 人の中で 2 位');
        Assert::same(3, $swims[1]['rank']['peer']['rank'], '次の大会は 3 位');
        Assert::same(2, $shapes[0]['best_peer']['rank'], '最高順位は 2 位のほう');
        Assert::same(4, $swims[0]['rank']['overall']['count'], '全体は 10 歳も含めた 4 人');
    },
    'withRanks は同年齢が足りなければ順位を全体に落とす' => static function (): void {
        $shapes = Progression::build([progressionRow('2026-02-20', 5054, 'SCM', 'OK', 0, 0, 8, 102)]);

        $shapes = Progression::withRanks($shapes, [
            102 => [
                ['final_time_cs' => 4846, 'age' => 10, 'status' => 'OK'],
                ['final_time_cs' => 5054, 'age' => 8, 'status' => 'OK'],
            ],
        ]);

        Assert::null($shapes[0]['swims'][0]['rank']['peer']);
        Assert::same('overall', $shapes[0]['swims'][0]['rank']['basis']);
        Assert::null($shapes[0]['best_peer']);
    },
    'withRanks に渡す種目が無くても壊れない' => static function (): void {
        $shapes = Progression::withRanks(
            Progression::build([progressionRow('2026-02-20', 5054, 'SCM', 'OK', 0, 0, 8, 999)]),
            []
        );

        Assert::null($shapes[0]['swims'][0]['rank'], '母集団が無ければ順位は付かない');
        Assert::null($shapes[0]['best_peer']);
    },
    '同日の予選決勝は種目順に並ぶ' => static function (): void {
        $shapes = Progression::build([
            progressionRow('2026-02-08', 4300, 'SCM', 'OK', 0, 20),
            progressionRow('2026-02-08', 4350, 'SCM', 'OK', 0, 5),
        ]);

        $swims = $shapes[0]['swims'];
        Assert::same(4350, $swims[0]['time_cs'], 'sort_order の小さいほう（予選）が先');
        Assert::same(-50, $swims[1]['delta_cs']);
    },
];
