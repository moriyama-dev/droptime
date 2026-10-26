<?php

declare(strict_types=1);

/**
 * 開発用の架空データ投入。UI の確認のためだけに使う。
 *
 *   php bin/seed-demo.php --force
 *
 * 本番（app.env = prod）では動かない。実在の選手・大会のデータではない。
 * 実行すると大会系のテーブルを全部消してから入れ直す。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Support\Clock;
use DropTime\Support\Config;
use DropTime\Support\Str;

$config = Config::load(APP_ROOT . '/config/config.php');

if (!$config->isDev()) {
    fwrite(STDERR, "このスクリプトは開発環境（app.env = dev）専用です。\n");
    exit(1);
}

if (!in_array('--force', $argv, true)) {
    fwrite(STDERR, "大会・選手・お気に入りのデータを全部消して架空データを入れます。\n");
    fwrite(STDERR, "実行するには --force を付けてください。\n");
    exit(1);
}

date_default_timezone_set($config->string('app.timezone', 'America/Toronto'));

$db = Database::instance();
$now = Clock::utcNow();

// ── 既存データを消す（認証系は残す） ─────────────────────────────
foreach ([
    'splits', 'results', 'entries', 'events', 'meet_sessions', 'meets',
    'favorites', 'profiles', 'swimmer_merge_candidates', 'swimmer_merges',
    'swimmers', 'teams', 'imports',
] as $table) {
    $db->execute('DELETE FROM ' . $table);
}

// ── チームと選手（すべて架空） ───────────────────────────────────
$team = static function (string $code, string $name) use ($db, $now): int {
    return $db->insert(
        'INSERT INTO teams (code, name, normalized_key, created_at) VALUES (:c, :n, :k, :t)',
        ['c' => $code, 'n' => $name, 'k' => Str::normalizeName($name), 't' => $now]
    );
};

$ray = $team('RAY', 'Rayfield Aquatics');
$nsc = $team('NSC', 'Northshore Swim Club');

$swimmer = static function (string $last, string $first, int $birth, string $gender, int $teamId) use ($db, $now): int {
    return $db->insert(
        'INSERT INTO swimmers (full_name, last_name, first_name, normalized_key, birth_year, gender, team_id, created_at, updated_at)'
        . ' VALUES (:full, :last, :first, :key, :birth, :gender, :team, :t, :t2)',
        [
            'full' => $first . ' ' . $last,
            'last' => $last,
            'first' => $first,
            'key' => Str::swimmerKey($last, $first),
            'birth' => $birth,
            'gender' => $gender,
            'team' => $teamId,
            't' => $now,
            't2' => $now,
        ]
    );
};

$hana = $swimmer('Aoki', 'Hana', 2015, 'F', $ray);
$kenji = $swimmer('Aoki', 'Kenji', 2013, 'M', $ray);
$mia = $swimmer('Tanaka', 'Mia', 2014, 'F', $ray);
$liam = $swimmer("O'Brien", 'Liam', 2014, 'M', $ray);
$elise = $swimmer('Côté', 'Élise', 2013, 'F', $nsc);
$sofia = $swimmer('Reyes', 'Sofia', 2015, 'F', $nsc);
$noah = $swimmer('Kim', 'Noah', 2013, 'M', $nsc);
$yuki = $swimmer('Sato', 'Yuki', 2014, 'F', $ray);

// ── 大会 ─────────────────────────────────────────────────────────
$meet = static function (string $name, string $start, string $course, ?string $end = null, ?string $venue = null) use ($db, $now): int {
    return $db->insert(
        'INSERT INTO meets (name, host, start_date, end_date, course, venue, created_at, updated_at)'
        . ' VALUES (:n, :h, :s, :e, :c, :v, :t, :t2)',
        ['n' => $name, 'h' => '（架空のデータ）', 's' => $start, 'e' => $end, 'c' => $course, 'v' => $venue, 't' => $now, 't2' => $now]
    );
};

$event = static function (int $meetId, string $number, string $gender, int $distance, string $stroke, string $course, int $order, ?string $scheduled = null, ?string $ageGroup = null) use ($db): int {
    return $db->insert(
        'INSERT INTO events (meet_id, event_number, round, gender, age_group, distance, stroke, course, is_relay, sort_order, scheduled_time)'
        . ' VALUES (:m, :n, :r, :g, :a, :d, :s, :c, 0, :o, :st)',
        ['m' => $meetId, 'n' => $number, 'r' => 'timed', 'g' => $gender, 'a' => $ageGroup, 'd' => $distance, 's' => $stroke, 'c' => $course, 'o' => $order, 'st' => $scheduled]
    );
};

$entry = static function (int $eventId, int $swimmerId, int $teamId, int $heat, int $lane, ?int $seedCs) use ($db): void {
    $db->execute(
        'INSERT INTO entries (event_id, swimmer_id, team_id, heat, lane, seed_time_cs, seed_is_ntime)'
        . ' VALUES (:e, :s, :tm, :h, :l, :seed, :nt)',
        ['e' => $eventId, 's' => $swimmerId, 'tm' => $teamId, 'h' => $heat, 'l' => $lane, 'seed' => $seedCs, 'nt' => $seedCs === null ? 1 : 0]
    );
};

/** @param list<array{0: int, 1: int}> $splitRows [距離, 累積cs] */
$result = static function (int $eventId, int $swimmerId, int $teamId, ?int $timeCs, int $place, array $splitRows = [], string $status = 'OK', ?string $dqReason = null) use ($db): void {
    $resultId = $db->insert(
        'INSERT INTO results (event_id, swimmer_id, team_id, final_time_cs, place, status, dq_reason)'
        . ' VALUES (:e, :s, :tm, :time, :p, :st, :dq)',
        ['e' => $eventId, 's' => $swimmerId, 'tm' => $teamId, 'time' => $timeCs, 'p' => $status === 'OK' ? $place : null, 'st' => $status, 'dq' => $dqReason]
    );

    foreach ($splitRows as [$distance, $cumulative]) {
        $db->execute(
            'INSERT INTO splits (result_id, distance, cumulative_time_cs) VALUES (:r, :d, :c)',
            ['r' => $resultId, 'd' => $distance, 'c' => $cumulative]
        );
    }
};

$cs = static function (string $formatted): int {
    $value = DropTime\Domain\SwimTime::parse($formatted);

    if ($value === null) {
        throw new RuntimeException('タイムを解釈できません: ' . $formatted);
    }

    return $value;
};

// ── 大会 1: 2025-10 の短水路（結果あり） ─────────────────────────
$m1 = $meet('Rayfield Fall Splash 2025', '2025-10-19', 'SCM', null, 'Rayfield Community Pool');

$e = $event($m1, '3', 'F', 50, 'FR', 'SCM', 3, null, '10 & Under');
$result($e, $hana, $ray, $cs('45.12'), 2);
$result($e, $sofia, $nsc, $cs('44.02'), 1);

$e = $event($m1, '5', 'M', 50, 'FR', 'SCM', 5, null, '11-12');
$result($e, $kenji, $ray, $cs('38.50'), 1);
$result($e, $noah, $nsc, $cs('39.90'), 2);

$e = $event($m1, '9', 'F', 100, 'FR', 'SCM', 9, null, '10 & Under');
$result($e, $hana, $ray, $cs('1:41.55'), 3, [[50, $cs('48.90')], [100, $cs('1:41.55')]]);
$result($e, $sofia, $nsc, $cs('1:39.80'), 2, [[50, $cs('47.60')], [100, $cs('1:39.80')]]);
$result($e, $mia, $ray, $cs('1:38.95'), 1, [[50, $cs('47.10')], [100, $cs('1:38.95')]]);

$e = $event($m1, '12', 'M', 100, 'BR', 'SCM', 12, null, '11-12');
$result($e, $kenji, $ray, $cs('1:55.02'), 2, [[50, $cs('54.80')], [100, $cs('1:55.02')]]);
$result($e, $liam, $ray, $cs('1:52.40'), 1, [[50, $cs('53.20')], [100, $cs('1:52.40')]]);

$e = $event($m1, '15', 'F', 50, 'BK', 'SCM', 15, null, '11-12');
$result($e, $elise, $nsc, $cs('42.31'), 1);
$result($e, $yuki, $ray, $cs('44.75'), 2);

// ── 大会 2: 2026-02 の短水路（結果あり・ベスト更新が出る） ───────
$m2 = $meet('Northshore Winter Invitational 2026', '2026-02-08', 'SCM', null, 'Northshore Aquatic Centre');

$e = $event($m2, '2', 'F', 50, 'FR', 'SCM', 2, null, '10 & Under');
$result($e, $hana, $ray, $cs('43.80'), 1);
$result($e, $sofia, $nsc, $cs('44.10'), 2);

$e = $event($m2, '4', 'M', 50, 'FR', 'SCM', 4, null, '11-12');
$result($e, $kenji, $ray, $cs('39.10'), 2);
$result($e, $noah, $nsc, $cs('38.88'), 1);

$e = $event($m2, '8', 'F', 100, 'FR', 'SCM', 8, null, '10 & Under');
$result($e, $hana, $ray, $cs('1:38.20'), 1, [[50, $cs('47.10')], [100, $cs('1:38.20')]]);
$result($e, $mia, $ray, $cs('1:38.60'), 2, [[50, $cs('46.90')], [100, $cs('1:38.60')]]);

$e = $event($m2, '11', 'M', 100, 'BR', 'SCM', 11, null, '11-12');
$result($e, $kenji, $ray, $cs('1:49.30'), 1, [[50, $cs('52.10')], [100, $cs('1:49.30')]]);
$result($e, $liam, $ray, null, 0, [], 'DQ', 'キックが交互（平泳ぎ）');

$e = $event($m2, '14', 'F', 200, 'IM', 'SCM', 14, null, '11-12');
$result($e, $elise, $nsc, $cs('3:12.45'), 1, [[50, $cs('41.20')], [100, $cs('1:31.80')], [150, $cs('2:28.90')], [200, $cs('3:12.45')]]);
$result($e, $yuki, $ray, $cs('3:18.02'), 2);

// ── 大会 3: 2026-05 の長水路（コース違いで別系列になる） ─────────
$m3 = $meet('Spring Long Course Classic 2026', '2026-05-24', 'LCM', null, 'City Olympic Pool');

$e = $event($m3, '1', 'F', 50, 'FR', 'LCM', 1);
$result($e, $hana, $ray, $cs('46.90'), 2);
$result($e, $sofia, $nsc, $cs('45.55'), 1);

$e = $event($m3, '6', 'M', 50, 'FR', 'LCM', 6);
$result($e, $kenji, $ray, $cs('40.21'), 1);

$e = $event($m3, '10', 'F', 100, 'FR', 'LCM', 10);
$result($e, $hana, $ray, $cs('1:45.10'), 1, [[50, $cs('50.20')], [100, $cs('1:45.10')]]);

// ── 大会 4: 直近の週末（エントリーのみ・「次に泳ぐレース」用） ───
$saturday = (new DateTimeImmutable('next saturday'))->format('Y-m-d');
$sunday = (new DateTimeImmutable('next sunday'))->format('Y-m-d');
$m4 = $meet('Rayfield Summer Championships 2026', $saturday, 'SCM', $sunday, 'Rayfield Community Pool');

$e = $event($m4, '1', 'F', 100, 'IM', 'SCM', 1, '9:00', '11-12');
$entry($e, $elise, $nsc, 1, 3, $cs('1:28.00'));
$entry($e, $yuki, $ray, 2, 4, $cs('1:31.50'));

$e = $event($m4, '2', 'M', 100, 'IM', 'SCM', 2, null, '11-12');
$entry($e, $noah, $nsc, 1, 4, $cs('1:26.20'));
$entry($e, $liam, $ray, 1, 5, $cs('1:29.00'));

$e = $event($m4, '3', 'F', 50, 'FR', 'SCM', 3, null, '10 & Under');
$entry($e, $hana, $ray, 2, 4, $cs('43.80'));
$entry($e, $sofia, $nsc, 2, 5, $cs('44.02'));

$e = $event($m4, '4', 'M', 50, 'FR', 'SCM', 4, null, '11-12');
$entry($e, $kenji, $ray, 3, 4, $cs('38.88'));
$entry($e, $noah, $nsc, 3, 5, $cs('38.50'));

$e = $event($m4, '7', 'F', 100, 'FR', 'SCM', 7, '10:30', '10 & Under');
$entry($e, $hana, $ray, 1, 3, $cs('1:38.20'));
$entry($e, $mia, $ray, 1, 4, $cs('1:38.60'));

$e = $event($m4, '9', 'M', 100, 'BR', 'SCM', 9, null, '11-12');
$entry($e, $kenji, $ray, 2, 4, $cs('1:49.30'));
$entry($e, $liam, $ray, 2, 5, null); // NT

echo "架空データを投入しました。\n";
echo "大会 4 件（うち 1 件は今週末・エントリーのみ）、選手 8 人。\n";
echo "お気に入りの動作確認には Hana Aoki / Kenji Aoki を登録するのが分かりやすいです。\n";
