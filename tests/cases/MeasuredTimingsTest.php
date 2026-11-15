<?php

declare(strict_types=1);

use DropTime\Domain\LiveSchedule;
use DropTime\Domain\MeasuredTimings;
use DropTime\Tests\Assert;

return [
    '会場のキーは会場名から。無ければ、施設の名前と分かる主催者名から' => static function (): void {
        Assert::same('toronto pan am sports centre', MeasuredTimings::venueKey('Toronto Pan Am Sports Centre', 'North York Aquatic Club'));
        Assert::same('toronto pan am sports centre', MeasuredTimings::venueKey('', 'Toronto Pan Am Sports Centre'));
        Assert::same('toronto pan am sports centre', MeasuredTimings::venueKey(null, ' toronto Pan-Am  Sports Centre '));
        Assert::same('city of markham pan am centre', MeasuredTimings::venueKey(null, 'City of Markham Pan Am Centre'));
    },
    'クラブの名前は会場ではないので、会場が分からない扱いにする' => static function (): void {
        Assert::same(null, MeasuredTimings::venueKey(null, 'North York Aquatic Club'));
        Assert::same(null, MeasuredTimings::venueKey('', 'Etobicoke Swim Club'));
        Assert::same(null, MeasuredTimings::venueKey(null, null));
    },
    '続けて泳いだ 2 種目の時刻の差が、あとの種目の実測になる' => static function (): void {
        $measured = MeasuredTimings::fromSession('2026-11-14', [
            ['key' => 'A', 'finished_at' => '2026-11-14 17:43:00'],
            ['key' => 'B', 'finished_at' => '2026-11-14 17:56:00'],
            ['key' => 'C', 'finished_at' => null],
            ['key' => 'D', 'finished_at' => '2026-11-14 18:30:00'],
            ['key' => 'E', 'finished_at' => '2026-11-14 18:37:00'],
        ]);

        Assert::same(['B', 'E'], array_keys($measured), '最初の種目と、前の種目の時刻が無い種目は実測にしない');
        Assert::same(780, $measured['B']['actual_seconds']);
        Assert::same(420, $measured['E']['actual_seconds']);
    },
    'あとから載り直した時刻（別の日・前の種目より早い）は実測にしない' => static function (): void {
        $measured = MeasuredTimings::fromSession('2026-11-14', [
            ['key' => 'A', 'finished_at' => '2026-11-14 09:00:00'],
            ['key' => 'B', 'finished_at' => '2026-11-15 08:00:00'],
            ['key' => 'C', 'finished_at' => '2026-11-14 10:00:00'],
            ['key' => 'D', 'finished_at' => '2026-11-14 09:50:00'],
            ['key' => 'E', 'finished_at' => '2026-11-14 09:50:00'],
        ]);

        Assert::same([], $measured);
    },
    '同じ種目・同じ距離の実測が 2 回以上あれば、計算式に掛ける倍率（中央値）を出す' => static function (): void {
        $row = static fn (string $stroke, int $distance, int $actual, int $model, bool $relay = false): array => [
            'stroke' => $stroke, 'distance' => $distance, 'is_relay' => $relay, 'actual_seconds' => $actual, 'model_seconds' => $model,
        ];
        $factors = MeasuredTimings::factors([
            $row('FR', 50, 420, 600),   // 0.7
            $row('FR', 50, 360, 600),   // 0.6
            $row('FR', 50, 540, 600),   // 0.9
            $row('FR', 50, 60, 600),    // 0.1 = まとめて載っただけ → 使わない
            $row('BK', 100, 900, 1000), // 1 回だけ → まだ使わない
            $row('FR', 200, 800, 1000, true),
            $row('FR', 200, 1000, 1000, true),
        ]);

        Assert::same(3, $factors[MeasuredTimings::eventKey('FR', 50, false)]['samples']);
        Assert::same(0.7, round((float) $factors[MeasuredTimings::eventKey('FR', 50, false)]['factor'], 4));
        Assert::same(null, $factors[MeasuredTimings::eventKey('BK', 100, false)]['factor'], '1 回では足りない');
        Assert::same(1, $factors[MeasuredTimings::eventKey('BK', 100, false)]['samples']);
        Assert::same(0.9, round((float) $factors[MeasuredTimings::eventKey('FR', 200, true)]['factor'], 4), 'リレーは個人種目と分ける');
        Assert::false(isset($factors[MeasuredTimings::eventKey('FR', 200, false)]));
    },
    '実測がある種目は計算式にその倍率を掛け、実測の無い種目は計算式のまま' => static function (): void {
        $event = static fn (string $number, string $stroke): array => [
            'event_number' => $number, 'round' => 'timed', 'distance' => 50, 'stroke' => $stroke, 'is_relay' => false,
            'heat_count' => 0, 'entry_count' => 80, 'result_count' => 0,
            'times' => array_fill(0, 80, ['heat' => null, 'cs' => 5000]),
        ];
        $sessions = [['date' => '2026-11-15', 'start_time' => '14:00', 'events' => [$event('1', 'FR'), $event('2', 'BK'), $event('3', 'FR')]]];
        $factors = [
            MeasuredTimings::eventKey('FR', 50, false) => ['factor' => 0.5, 'samples' => 4],
            MeasuredTimings::eventKey('BK', 50, false) => ['factor' => null, 'samples' => 1],
        ];
        // 計算式: 80 人 ÷ 8 レーン = 10 組 ×（50 秒 + 入れ替え 50 秒）= 1000 秒
        $plan = LiveSchedule::forMeet($sessions, [], '2026-11-15 12:00:00', 8, $factors);

        Assert::same(4, $plan['1|timed']['measured']);
        Assert::same('2026-11-15 14:08:20', $plan['2|timed']['start'], '自由形は実測の 0.5 倍 = 500 秒');
        Assert::same(0, $plan['2|timed']['measured'], '背泳ぎは実測が足りないので計算式');
        Assert::same('2026-11-15 14:25:00', $plan['3|timed']['start'], '背泳ぎは計算式のまま 1000 秒');

        $none = LiveSchedule::forMeet($sessions, [], '2026-11-15 12:00:00', 8);
        Assert::same('2026-11-15 14:16:40', $none['2|timed']['start']);
        Assert::same(0, $none['1|timed']['measured']);
    },
];
