<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Tests\Assert;

return [
    'Accept-Language の交渉（q 値と対応表）' => static function (): void {
        Assert::same('ja', Lang::negotiate('ja-JP,ja;q=0.9,en;q=0.8'));
        Assert::same('en', Lang::negotiate('en-CA,en;q=0.9'));
        Assert::same('yue', Lang::negotiate('zh-HK,zh;q=0.9,en;q=0.8'), '香港の中国語は広東語へ');
        Assert::same('yue', Lang::negotiate('yue-Hant-HK'));
        Assert::same('yue', Lang::negotiate('zh-CN'), '中国語系はすべて広東語（繁体字）へ寄せる');
        Assert::same('ja', Lang::negotiate('fr-CA;q=0.9,ja;q=0.8'), '対応外の言語は飛ばして次を見る');
        Assert::null(Lang::negotiate('fr,de;q=0.9'));
        Assert::null(Lang::negotiate(''));
        Assert::same('en', Lang::negotiate('ja;q=0.5,en;q=0.9'), 'q 値の高いほうが勝つ');
    },
    '訳文の取得と埋め込み' => static function (): void {
        Lang::init('ja');
        Assert::same('大会', Lang::t('meets.title'));
        Assert::same('第 3 組', Lang::t('event.heat_n', 3));

        Lang::init('en');
        Assert::same('Meets', Lang::t('meets.title'));

        Lang::init('yue');
        Assert::same('比賽', Lang::t('meets.title'));

        Lang::init('ja');
    },
    '未対応コードは既定言語になる' => static function (): void {
        Lang::init('fr');
        Assert::same(Lang::DEFAULT, Lang::current());
        Lang::init('ja');
    },
    '無いキーはキー名がそのまま返る（画面が空にならない）' => static function (): void {
        Lang::init('ja');
        Assert::same('no.such.key', Lang::t('no.such.key'));
    },
    '3 言語の辞書のキーが完全に一致する' => static function (): void {
        $en = require APP_ROOT . '/lang/en.php';
        $ja = require APP_ROOT . '/lang/ja.php';
        $yue = require APP_ROOT . '/lang/yue.php';

        $missing = array_merge(
            array_map(static fn ($k) => "ja に無い: $k", array_keys(array_diff_key($en, $ja))),
            array_map(static fn ($k) => "en に無い: $k", array_keys(array_diff_key($ja, $en))),
            array_map(static fn ($k) => "yue に無い: $k", array_keys(array_diff_key($en, $yue))),
            array_map(static fn ($k) => "en に無い(yue比): $k", array_keys(array_diff_key($yue, $en))),
        );

        Assert::same([], $missing);
    },
    'プレースホルダの数が 3 言語で一致する' => static function (): void {
        $dictionaries = [
            'en' => require APP_ROOT . '/lang/en.php',
            'ja' => require APP_ROOT . '/lang/ja.php',
            'yue' => require APP_ROOT . '/lang/yue.php',
        ];

        $problems = [];

        foreach (array_keys($dictionaries['en']) as $key) {
            $counts = [];

            foreach ($dictionaries as $code => $dict) {
                $counts[$code] = preg_match_all('/%[sd]/', $dict[$key] ?? '');
            }

            if (count(array_unique($counts)) > 1) {
                $problems[] = $key;
            }
        }

        Assert::same([], $problems, 'sprintf プレースホルダ数の不一致');
    },
];
