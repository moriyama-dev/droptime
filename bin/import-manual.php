<?php

declare(strict_types=1);

/**
 * 手入力データの取り込み。
 *
 *   php bin/import-manual.php var/manual/somefile.json
 *
 * 実書式のパーサー（M4）ができるまでのつなぎ。紙の結果や Meet Mobile の
 * 画面から人間が書き写した JSON を、通常の取り込みと同じ経路
 * （ImportCommitter: 選手の突き合わせ・種目単位の置き換え）で DB に入れる。
 * 同じファイルを何度流しても二重登録にならない。
 *
 * JSON の形は docs/examples/manual-import.example.json を参照。
 * 選手名を含むファイルは var/manual/ に置く（git 管理外・Web 到達不能）。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Domain\Course;
use DropTime\Domain\Stroke;
use DropTime\Domain\SwimTime;
use DropTime\Import\ImportCommitter;
use DropTime\Matching\SwimmerMatcher;
use DropTime\Matching\TeamResolver;
use DropTime\Parser\ParsedEntry;
use DropTime\Parser\ParsedEvent;
use DropTime\Parser\ParsedResultRow;
use DropTime\Parser\ParsedSplit;
use DropTime\Parser\ParsedSwimmer;
use DropTime\Parser\ParseResult;
use DropTime\Support\Config;

$path = $argv[1] ?? '';

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "使い方: php bin/import-manual.php <JSONファイル>\n");
    fwrite(STDERR, "形式は docs/examples/manual-import.example.json を参照。\n");
    exit(1);
}

Config::load(APP_ROOT . '/config/config.php');
date_default_timezone_set(Config::instance()->string('app.timezone', 'America/Toronto'));

$data = json_decode((string) file_get_contents($path), true);

if (!is_array($data)) {
    fwrite(STDERR, "JSON を読めません: {$path}\n" . json_last_error_msg() . "\n");
    exit(1);
}

$db = Database::instance();

/** 表記のタイムをセンチ秒へ。null / "NT" は null。 */
$cs = static function (mixed $raw, string $context): ?int {
    if ($raw === null || $raw === '' || strtoupper((string) $raw) === 'NT') {
        return null;
    }

    $value = SwimTime::parse((string) $raw);

    if ($value === null) {
        throw new RuntimeException("タイムを解釈できません（{$context}）: {$raw}");
    }

    return $value;
};

/** @param array<string, mixed> $row */
$swimmerOf = static function (array $row): ParsedSwimmer {
    return new ParsedSwimmer(
        (string) ($row['last_name'] ?? ''),
        (string) ($row['first_name'] ?? ''),
        isset($row['birth_year']) ? (int) $row['birth_year'] : null,
        isset($row['age']) ? (int) $row['age'] : null,
        isset($row['gender']) ? (string) $row['gender'] : null,
        isset($row['team_code']) ? (string) $row['team_code'] : null,
        isset($row['team_name']) ? (string) $row['team_name'] : null,
    );
};

// ── 選手だけの登録（結果がまだ無くても検索・お気に入りに出せる） ──
$registered = 0;

foreach (is_array($data['swimmers'] ?? null) ? $data['swimmers'] : [] as $row) {
    $swimmer = $swimmerOf($row);

    if ($swimmer->lastName === '' || $swimmer->firstName === '') {
        fwrite(STDERR, "swimmers: last_name / first_name は必須です。\n");
        exit(1);
    }

    $teamId = (new TeamResolver($db))->resolve($swimmer->teamCode, $swimmer->teamName);
    (new SwimmerMatcher($db))->resolve($swimmer, $teamId);
    $registered++;
}

// ── 大会ごとの取り込み ───────────────────────────────────────────
$totals = ['meets' => 0, 'events' => 0, 'entries' => 0, 'results' => 0];

foreach (is_array($data['meets'] ?? null) ? $data['meets'] : [] as $meetRow) {
    $course = Course::tryFrom(strtoupper((string) ($meetRow['course'] ?? '')));
    $name = (string) ($meetRow['name'] ?? '');
    $startDate = (string) ($meetRow['start_date'] ?? '');

    if ($course === null || $name === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1) {
        fwrite(STDERR, "meets: name / start_date (YYYY-MM-DD) / course (SCM|LCM|SCY) は必須です: {$name}\n");
        exit(1);
    }

    $result = new ParseResult('manual');

    foreach (is_array($meetRow['events'] ?? null) ? $meetRow['events'] : [] as $index => $eventRow) {
        $stroke = Stroke::tryFrom(strtoupper((string) ($eventRow['stroke'] ?? '')));
        $eventCourse = Course::tryFrom(strtoupper((string) ($eventRow['course'] ?? $course->value))) ?? $course;

        if ($stroke === null || !isset($eventRow['distance'], $eventRow['number'], $eventRow['gender'])) {
            fwrite(STDERR, "events: number / gender / distance / stroke (FR|BK|BR|FL|IM|MED) は必須です。\n");
            exit(1);
        }

        $event = new ParsedEvent(
            (string) $eventRow['number'],
            (string) ($eventRow['round'] ?? 'timed'),
            (string) $eventRow['gender'],
            (int) $eventRow['distance'],
            $stroke,
            $eventCourse,
            isset($eventRow['age_group']) ? (string) $eventRow['age_group'] : null,
            (bool) ($eventRow['is_relay'] ?? false),
            isset($eventRow['scheduled_time']) ? (string) $eventRow['scheduled_time'] : null,
            (int) ($eventRow['sort_order'] ?? $index + 1),
        );

        foreach (is_array($eventRow['entries'] ?? null) ? $eventRow['entries'] : [] as $entryRow) {
            $seed = $cs($entryRow['seed'] ?? null, $name . ' entry seed');
            $event->addEntry(new ParsedEntry(
                isset($entryRow['last_name']) ? $swimmerOf($entryRow) : null,
                isset($entryRow['heat']) ? (int) $entryRow['heat'] : null,
                isset($entryRow['lane']) ? (int) $entryRow['lane'] : null,
                $seed,
                $seed === null && ($entryRow['seed'] ?? null) !== null,
                isset($entryRow['team_code']) ? (string) $entryRow['team_code'] : null,
                isset($entryRow['relay_letter']) ? (string) $entryRow['relay_letter'] : null,
            ));
        }

        foreach (is_array($eventRow['results'] ?? null) ? $eventRow['results'] : [] as $resultRow) {
            $splits = [];

            foreach (is_array($resultRow['splits'] ?? null) ? $resultRow['splits'] : [] as $distance => $cumulative) {
                $value = $cs($cumulative, $name . ' split');

                if ($value !== null) {
                    $splits[] = new ParsedSplit((int) $distance, $value);
                }
            }

            $status = strtoupper((string) ($resultRow['status'] ?? 'OK'));

            $event->addResult(new ParsedResultRow(
                isset($resultRow['last_name']) ? $swimmerOf($resultRow) : null,
                $status === 'OK' ? $cs($resultRow['time'] ?? null, $name . ' ' . $event->number) : null,
                isset($resultRow['place']) ? (int) $resultRow['place'] : null,
                $status,
                isset($resultRow['dq_reason']) ? (string) $resultRow['dq_reason'] : null,
                isset($resultRow['points']) ? (float) $resultRow['points'] : null,
                isset($resultRow['heat']) ? (int) $resultRow['heat'] : null,
                isset($resultRow['lane']) ? (int) $resultRow['lane'] : null,
                isset($resultRow['team_code']) ? (string) $resultRow['team_code'] : null,
                isset($resultRow['relay_letter']) ? (string) $resultRow['relay_letter'] : null,
                $splits,
            ));
        }

        $result->addEvent($event);
    }

    if ($result->events() === []) {
        continue; // 種目が空の大会はスキップ（書きかけの JSON を許容）
    }

    $stats = ImportCommitter::create($db)->commit($result, [
        'name' => $name,
        'start_date' => $startDate,
        'end_date' => isset($meetRow['end_date']) ? (string) $meetRow['end_date'] : null,
        'course' => $course->value,
        'host' => isset($meetRow['host']) ? (string) $meetRow['host'] : null,
        'venue' => isset($meetRow['venue']) ? (string) $meetRow['venue'] : null,
    ], 'manual:' . basename($path));

    $totals['meets']++;
    $totals['events'] += $stats['events'];
    $totals['entries'] += $stats['entries'];
    $totals['results'] += $stats['results'];
}

printf(
    "取り込みました: 選手登録 %d 件 / 大会 %d 件（種目 %d・エントリー %d・結果 %d）\n",
    $registered,
    $totals['meets'],
    $totals['events'],
    $totals['entries'],
    $totals['results']
);

$pending = $db->selectValue("SELECT COUNT(*) FROM swimmer_merge_candidates WHERE status = 'pending'");

if ((int) $pending > 0) {
    printf("同一人物か判定が必要な候補が %d 件あります。管理画面の「選手の統合」で判定してください。\n", (int) $pending);
}
