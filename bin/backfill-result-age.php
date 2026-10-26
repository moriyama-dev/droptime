<?php

declare(strict_types=1);

/**
 * 投入済みの結果に「レース時点の年齢」を埋め戻す。
 *
 *   php bin/backfill-result-age.php <大会ディレクトリ> [...]   下見（何も書かない）
 *   php bin/backfill-result-age.php <大会ディレクトリ> --write  実際に更新する
 *
 * マイグレーション 003 で results.age を足す前に投入した大会のためのもの。
 * 原本（var/manual/sources/<大会>/*.htm）を読み直し、種目番号と選手名で
 * 投入済みの結果行を突き止めて age だけを UPDATE する。
 *
 * 大会・種目・結果の行そのものは作らないし消さないし、それ以外の列も触らない。
 * 選手 ID も変わらないので、お気に入りや統合の記録に影響しない。
 * よって取り込みのやり直し（重複投入の危険がある）は不要。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/bootstrap.php';

use DropTime\Db\Database;
use DropTime\Import\HtmlTextExtractor;
use DropTime\Import\ImportException;
use DropTime\Parser\ParserRegistry;
use DropTime\Support\Config;
use DropTime\Support\Str;

$directories = [];
$write = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--write') {
        $write = true;
    } else {
        $directories[] = $argument;
    }
}

if ($directories === []) {
    fwrite(STDERR, "使い方: php bin/backfill-result-age.php <大会ディレクトリ> [...] [--write]\n");
    exit(1);
}

Config::load(APP_ROOT . '/config/config.php');
date_default_timezone_set(Config::instance()->string('app.timezone', 'America/Toronto'));

$db = Database::instance();
$extractor = new HtmlTextExtractor();
$registry = ParserRegistry::default();

$totals = ['updated' => 0, 'already' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'norows' => 0];

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        fwrite(STDERR, "ディレクトリがありません: {$directory}\n");
        exit(1);
    }

    echo "── {$directory}\n";

    // import-hytek.php と同じ手順で 1 本のテキストに結合する。
    $files = glob(rtrim($directory, '/') . '/*.htm*') ?: [];
    $files = array_values(array_filter(
        $files,
        static fn (string $f): bool => preg_match('/(evtindex|lastevt|index)\./i', basename($f)) !== 1
    ));
    sort($files, SORT_STRING);

    $combined = "<html><body>\n";

    foreach ($files as $file) {
        try {
            $text = $extractor->extract((string) file_get_contents($file));
        } catch (ImportException) {
            continue;
        }

        $combined .= "<pre>\n" . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n</pre>\n";
    }

    $combined .= "</body></html>\n";

    $plain = $extractor->extract($combined);
    $parsed = $registry->select($plain)->parse($plain);
    $meet = $parsed->meet();

    if ($meet === null) {
        fwrite(STDERR, "  大会を読み取れませんでした。飛ばします。\n");
        continue;
    }

    $meetRow = $db->selectOne(
        'SELECT id, name FROM meets WHERE name = :name AND start_date = :start_date',
        ['name' => $meet->name, 'start_date' => $meet->startDate]
    );

    if ($meetRow === null) {
        fwrite(STDERR, sprintf("  未投入の大会です（%s / %s）。飛ばします。\n", $meet->name, $meet->startDate));
        continue;
    }

    $meetId = (int) $meetRow['id'];
    printf("  大会 #%d %s\n", $meetId, $meetRow['name']);

    foreach ($parsed->events() as $event) {
        if ($event->isRelay) {
            continue; // リレーに個人の年齢は無い
        }

        $eventId = $db->selectValue(
            'SELECT id FROM events WHERE meet_id = :meet_id AND event_number = :number AND round = :round',
            ['meet_id' => $meetId, 'number' => $event->number, 'round' => $event->round]
        );

        if ($eventId === null) {
            $totals['norows']++;
            continue;
        }

        foreach ($event->results() as $row) {
            $swimmer = $row->swimmer;

            if ($swimmer === null || $swimmer->age === null) {
                continue;
            }

            // 同じ種目の中で、正規化した氏名が一致する結果行を探す。
            $matches = $db->select(
                'SELECT r.id, r.age FROM results r'
                . ' JOIN swimmers s ON s.id = r.swimmer_id'
                . ' WHERE r.event_id = :event_id AND s.normalized_key = :key',
                ['event_id' => (int) $eventId, 'key' => $swimmer->normalizedKey()]
            );

            if ($matches === []) {
                $totals['unmatched']++;
                continue;
            }

            if (count($matches) > 1) {
                // 同一種目に同姓同名。取り違えるくらいなら何もしない。
                $totals['ambiguous']++;
                fwrite(STDERR, sprintf(
                    "    保留: 種目 %s に「%s」が %d 行あります\n",
                    $event->number,
                    $swimmer->fullName(),
                    count($matches)
                ));
                continue;
            }

            if ($matches[0]['age'] !== null && (int) $matches[0]['age'] === $swimmer->age) {
                $totals['already']++;
                continue;
            }

            if ($write) {
                $db->execute(
                    'UPDATE results SET age = :age WHERE id = :id',
                    ['age' => $swimmer->age, 'id' => (int) $matches[0]['id']]
                );
            }

            $totals['updated']++;
        }
    }
}

echo "\n";
printf(
    "%s: 更新 %d / 既に同じ %d / 結果行なし %d / 種目なし %d / 同姓同名で保留 %d\n",
    $write ? '実行' : '下見（--write で実際に更新）',
    $totals['updated'],
    $totals['already'],
    $totals['unmatched'],
    $totals['norows'],
    $totals['ambiguous']
);

if (!$write && $totals['updated'] > 0) {
    echo "問題なければ --write を付けて再実行してください。\n";
}
