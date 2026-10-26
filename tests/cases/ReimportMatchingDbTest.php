<?php

declare(strict_types=1);

use DropTime\Chart\BarSvg;
use DropTime\Db\Database;
use DropTime\Matching\SwimmerMatcher;
use DropTime\Parser\ParsedSwimmer;
use DropTime\Support\Clock;
use DropTime\Support\Config;
use DropTime\Tests\Assert;

/**
 * 同じ大会を投入し直したとき、同姓同名の選手が新しい選手に分かれないこと。
 * 開発 DB に接続できる環境でだけ中身が走る（MergeServiceDbTest と同じ方式）。
 * テストデータは 'zzfetchtest' で始まる名前と大会名を使い、最後に必ず消す。
 */
$withDb = static function (callable $test): void {
    $configPath = APP_ROOT . '/config/config.php';

    if (!is_file($configPath)) {
        return;
    }

    try {
        Config::load($configPath);
        $db = Database::instance();
        $db->selectValue('SELECT 1');
    } catch (Throwable) {
        return;
    }

    $cleanup = static function () use ($db): void {
        $db->execute("DELETE FROM meets WHERE name = 'zzfetchtest meet'");
        $db->execute("DELETE FROM swimmers WHERE normalized_key LIKE 'zzfetchtest%'");
    };

    $cleanup();

    try {
        $test($db);
    } finally {
        $cleanup();
    }
};

return [
    '同じ大会の投入し直しでは、前回その大会で決まった選手を使う' => static function () use ($withDb): void {
        $withDb(static function (Database $db): void {
            $ann = new ParsedSwimmer('Zzfetchtest', 'Ann');
            $first = (new SwimmerMatcher($db))->resolve($ann, null);

            // 同姓同名の別の子が後から登録された状態。生年もチームも無いので区別できない。
            $now = Clock::utcNow();
            $db->insert(
                'INSERT INTO swimmers (full_name, last_name, first_name, normalized_key, created_at, updated_at)'
                . " VALUES ('Ann Zzfetchtest', 'Zzfetchtest', 'Ann', :key, :t, :t2)",
                ['key' => $ann->normalizedKey(), 't' => $now, 't2' => $now]
            );

            $meetId = $db->insert(
                "INSERT INTO meets (name, start_date, course, created_at, updated_at) VALUES ('zzfetchtest meet', '2026-01-01', 'SCM', :t, :t2)",
                ['t' => $now, 't2' => $now]
            );
            $eventId = $db->insert(
                "INSERT INTO events (meet_id, event_number, round, gender, distance, stroke, course)"
                . " VALUES (:m, '1', 'timed', 'F', 50, 'FR', 'SCM')",
                ['m' => $meetId]
            );
            $db->insert(
                "INSERT INTO results (event_id, swimmer_id, final_time_cs, status) VALUES (:e, :s, 3500, 'OK')",
                ['e' => $eventId, 's' => $first]
            );

            // 前回の判定を知らない照合は、区別できずに新しい選手を作ってしまう。
            $fresh = (new SwimmerMatcher($db))->resolve($ann, null);
            Assert::true($fresh !== $first, '前提: 同姓同名が 2 人いると新しい選手になる');
            $db->execute('DELETE FROM swimmers WHERE id = :id', ['id' => $fresh]);

            $matcher = new SwimmerMatcher($db);
            $matcher->rememberMeet($meetId);

            Assert::same($first, $matcher->resolve($ann, null), '同じ大会・同じ名前・同じチームは前回と同じ子');
        });
    },
    '棒の長さは 0〜1 に収め、縮めた区間は前回の長さまで描く' => static function (): void {
        $svg = BarSvg::render(0.9, 'now', 1.0);

        Assert::true(str_contains($svg, 'class="bar__fill" x="0" y="0" width="270.0"'));
        Assert::true(str_contains($svg, 'class="bar__gain" x="270.0" y="0" width="30.0"'));
        Assert::false(str_contains(BarSvg::render(1.5, 'now', 0.5), 'bar__gain'), '前回のほうが短ければ区間は無い');
        Assert::true(str_contains(BarSvg::render(-1.0), 'width="2.0"'), '0 でも細く見せる');
    },
];
