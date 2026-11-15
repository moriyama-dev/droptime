<?php

declare(strict_types=1);

use DropTime\Push\RaceNotices;
use DropTime\Repository\RacePlanRepository;
use DropTime\Support\Lang;
use DropTime\Tests\Assert;

/**
 * plan() の戻り値の形を手で作る。選手 7（Doe, Jane）が今日 3 種目。
 *
 * @param array<int, array{at: ?string, status?: string}> $races 種目 ID => 今日の開始時刻と状態
 * @return list<array<string, mixed>>
 */
function noticeGroups(array $races): array
{
    $entries = [];

    foreach ($races as $eventId => $race) {
        $entries[] = [
            'swimmer_id' => 7, 'swimmer_name' => 'Doe, Jane', 'event_id' => $eventId, 'event_number' => (string) $eventId,
            'round' => 'timed', 'gender' => 'F', 'age_group' => '10 & Under', 'distance' => 50, 'stroke' => 'FREE',
            'course' => 'LCM', 'is_relay' => 0, 'heat' => 3, 'lane' => 4,
            'schedule' => ['time' => '9:00', 'estimated' => true],
            'live' => $race['at'] === null ? null : ['status' => $race['status'] ?? 'upcoming', 'start' => $race['at']],
            'today_at' => $race['at'],
        ];
    }

    return [['meet' => ['id' => 1, 'name' => 'Example Invitational', 'venue' => null], 'entries' => $entries, 'is_today' => true, 'latest' => null]];
}

return [
    '今日の出番は最初と最後の種目の時刻（終わった種目も含める）' => static function (): void {
        $windows = RacePlanRepository::todayWindows(noticeGroups([
            12 => ['at' => '2026-11-15 13:40:00'],
            3 => ['at' => '2026-11-15 09:05:00', 'status' => 'done'],
            30 => ['at' => '2026-11-15 15:20:00'],
            40 => ['at' => null],
        ]));

        Assert::same(1, count($windows));
        Assert::same('9:05', $windows[0]['first']['clock']);
        Assert::same(3, (int) $windows[0]['first']['event_id']);
        Assert::same('15:20', $windows[0]['last']['clock']);
        Assert::same(3, $windows[0]['count'], '時刻の分からない種目は数えない');
        Assert::same(1, $windows[0]['done']);
    },
    '朝 7 時前で最初の種目まで 2 時間以上あれば、今日の出番はまだ送らない' => static function (): void {
        Lang::init('ja');
        $groups = noticeGroups([1 => ['at' => '2026-11-15 10:00:00']]);

        Assert::same([], RaceNotices::due($groups, '2026-11-15 06:30:00', [30, 10]));

        $notices = RaceNotices::due($groups, '2026-11-15 07:00:00', [30, 10]);
        Assert::same(1, count($notices));
        Assert::same('day|2026-11-15|7|1', $notices[0]['keys'][0]);
        Assert::true(str_contains($notices[0]['body'], '10:00'));
    },
    '30 分を切ると 30 分の知らせ、10 分を切ると 10 分の知らせ' => static function (): void {
        Lang::init('ja');
        $groups = noticeGroups([5 => ['at' => '2026-11-15 10:00:00']]);

        $race = static fn (array $notices): array => array_values(array_filter($notices, static fn (array $n): bool => str_starts_with($n['keys'][0], 'race|')));

        Assert::same([], $race(RaceNotices::due($groups, '2026-11-15 09:25:00', [30, 10])));

        $at30 = $race(RaceNotices::due($groups, '2026-11-15 09:31:00', [30, 10]));
        Assert::same(['race|2026-11-15|7|5|30'], $at30[0]['keys']);
        Assert::same(['race|2026-11-15|7|5|10'], $at30[0]['skip_if'], '10 分の知らせを送り済みなら 30 分は送らない');
        Assert::true(str_contains($at30[0]['title'], 'あと約 29 分'));

        $at10 = $race(RaceNotices::due($groups, '2026-11-15 09:52:00', [30, 10]));
        Assert::same(['race|2026-11-15|7|5|10', 'race|2026-11-15|7|5|30'], $at10[0]['keys'], '遅れて 10 分を切ったら 30 分の分も送り済みにする');
    },
    '泳いでいる最中・泳ぎ終わった種目は知らせない' => static function (): void {
        Lang::init('en');
        $groups = noticeGroups([5 => ['at' => '2026-11-15 10:00:00', 'status' => 'running'], 6 => ['at' => '2026-11-15 09:00:00', 'status' => 'done']]);
        $notices = RaceNotices::due($groups, '2026-11-15 10:01:00', [30, 10]);

        Assert::same(1, count($notices), '今日の出番だけ（最後の種目がまだ終わっていない）');
        Assert::true(str_starts_with($notices[0]['keys'][0], 'day|'));
    },
];
