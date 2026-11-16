<?php

declare(strict_types=1);

use DropTime\Http\TestClock;
use DropTime\Push\TestNotice;
use DropTime\Support\Lang;
use DropTime\Tests\Assert;

return [
    'テスト通知は、本物と取り違えないよう題名の頭に印が付き、見本の名前だけを使う' => static function (): void {
        Lang::init('ja');

        foreach (TestNotice::KINDS as $kind) {
            $data = json_decode(TestNotice::payload($kind, '/app'), true);

            Assert::true(str_starts_with((string) $data['title'], '【テスト】'), $kind);
            Assert::true((string) $data['body'] !== '', $kind);
            Assert::same('/app/', $data['url']);
        }

        $race = json_decode(TestNotice::payload('race30'), true);
        Assert::true(str_contains((string) $race['title'], '見本の選手'));
        Assert::true(str_contains((string) $race['title'], '30'));
    },
    '続けて送っても前の通知に上書きされないよう、tag は毎回変わる' => static function (): void {
        Lang::init('en');
        $a = json_decode(TestNotice::payload('test'), true);
        $b = json_decode(TestNotice::payload('test'), true);

        Assert::true($a['tag'] !== $b['tag']);
        Assert::true(str_starts_with((string) $a['title'], '[TEST]'));
    },
    '知らない種類は、試しの通知として作る' => static function (): void {
        Lang::init('ja');
        $data = json_decode(TestNotice::payload('unknown'), true);

        Assert::true(str_contains((string) $data['body'], '通知は届いています'));
    },
    '中継サーバーの会社は送り先の URL から分かる' => static function (): void {
        Assert::same('Apple', TestNotice::relayName('https://web.push.apple.com/abc'));
        Assert::same('Google', TestNotice::relayName('https://fcm.googleapis.com/fcm/send/abc'));
        Assert::same('Mozilla', TestNotice::relayName('https://updates.push.services.mozilla.com/wpush/v2/abc'));
    },
    'テスト時刻は、日付と時刻の形だけを受け付ける' => static function (): void {
        Assert::same('2026-11-15 09:00:00', TestClock::parse('2026-11-15T09:00'));
        Assert::same('2026-11-15 09:00:30', TestClock::parse('2026-11-15 09:00:30'));
        Assert::same(null, TestClock::parse(''));
        Assert::same(null, TestClock::parse('tomorrow'));
        Assert::same(null, TestClock::parse('2026-11-15'));
    },
];
