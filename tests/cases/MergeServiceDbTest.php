<?php

declare(strict_types=1);

use DropTime\Db\Database;
use DropTime\Matching\MergeService;
use DropTime\Support\Clock;
use DropTime\Support\Config;
use DropTime\Tests\Assert;

/**
 * MergeService の統合テスト。開発 DB に接続できる環境でだけ中身が走る。
 * config/config.php が無い、または DB に繋がらない場合は何もせず成功する
 * （CI や本番相当の環境で落とさないため）。
 *
 * テストデータは 'zzmergetest' で始まる normalized_key を使い、最後に必ず消す。
 */
$withDb = static function (callable $test): void {
    $configPath = APP_ROOT . '/config/config.php';

    if (!is_file($configPath)) {
        return; // 設定が無い環境ではスキップ相当
    }

    try {
        Config::load($configPath);
        $db = Database::instance();
        $db->selectValue('SELECT 1');
    } catch (Throwable) {
        return; // DB が無い環境ではスキップ相当
    }

    $cleanup = static function () use ($db): void {
        $rows = $db->select("SELECT id FROM swimmers WHERE normalized_key LIKE 'zzmergetest%'");

        foreach ($rows as $row) {
            $db->execute('DELETE FROM swimmers WHERE id = :id', ['id' => (int) $row['id']]);
        }

        $db->execute("DELETE FROM profiles WHERE key_hash = :h", ['h' => str_repeat('f', 64)]);
        $db->execute("DELETE FROM swimmer_merges WHERE removed_json LIKE '%zzmergetest%'");
    };

    $cleanup();

    try {
        $test($db);
    } finally {
        $cleanup();
    }
};

$makeSwimmer = static function (Database $db, string $first, ?int $birth): int {
    $now = Clock::utcNow();

    return $db->insert(
        'INSERT INTO swimmers (full_name, last_name, first_name, normalized_key, birth_year, gender, team_id, created_at, updated_at)'
        . " VALUES (:full, 'Zzmergetest', :first, :key, :birth, NULL, NULL, :t, :t2)",
        [
            'full' => $first . ' Zzmergetest',
            'first' => $first,
            'key' => 'zzmergetest ' . strtolower($first),
            'birth' => $birth,
            't' => $now,
            't2' => $now,
        ]
    );
};

return [
    '統合で記録とお気に入りが移り、取り消しで完全に戻る' => static function () use ($withDb, $makeSwimmer): void {
        $withDb(static function (Database $db) use ($makeSwimmer): void {
            $keep = $makeSwimmer($db, 'Keep', null);
            $remove = $makeSwimmer($db, 'Remove', 2014);

            // remove 側に結果とお気に入りを付ける。
            $meetId = $db->insert(
                "INSERT INTO meets (name, start_date, course, created_at, updated_at)"
                . " VALUES ('zzmergetest meet', '2026-01-01', 'SCM', :t, :t2)",
                ['t' => Clock::utcNow(), 't2' => Clock::utcNow()]
            );
            $eventId = $db->insert(
                "INSERT INTO events (meet_id, event_number, round, gender, distance, stroke, course, sort_order)"
                . " VALUES (:m, '1', 'timed', 'F', 50, 'FR', 'SCM', 1)",
                ['m' => $meetId]
            );
            $resultId = $db->insert(
                'INSERT INTO results (event_id, swimmer_id, final_time_cs, place) VALUES (:e, :s, 4500, 1)',
                ['e' => $eventId, 's' => $remove]
            );
            $profileId = $db->insert(
                'INSERT INTO profiles (key_hash, created_at, last_seen_at) VALUES (:h, :t, :t2)',
                ['h' => str_repeat('f', 64), 't' => Clock::utcNow(), 't2' => Clock::utcNow()]
            );
            $db->execute(
                'INSERT INTO favorites (profile_id, swimmer_id, created_at) VALUES (:p, :s, :t)',
                ['p' => $profileId, 's' => $remove, 't' => Clock::utcNow()]
            );

            $service = new MergeService($db);
            $mergeId = $service->merge($keep, $remove);

            // remove は消え、結果とお気に入りは keep に移り、生年も補われる。
            Assert::null($db->selectValue('SELECT id FROM swimmers WHERE id = :id', ['id' => $remove]));
            Assert::same($keep, (int) $db->selectValue('SELECT swimmer_id FROM results WHERE id = :id', ['id' => $resultId]));
            Assert::same($keep, (int) $db->selectValue('SELECT swimmer_id FROM favorites WHERE profile_id = :p', ['p' => $profileId]));
            Assert::same(2014, (int) $db->selectValue('SELECT birth_year FROM swimmers WHERE id = :id', ['id' => $keep]));

            $service->undo($mergeId);

            // remove が同じ ID で復元され、結果もお気に入りも戻り、keep の生年も戻る。
            Assert::same('Remove Zzmergetest', $db->selectValue('SELECT full_name FROM swimmers WHERE id = :id', ['id' => $remove]));
            Assert::same($remove, (int) $db->selectValue('SELECT swimmer_id FROM results WHERE id = :id', ['id' => $resultId]));
            Assert::same($remove, (int) $db->selectValue('SELECT swimmer_id FROM favorites WHERE profile_id = :p', ['p' => $profileId]));
            Assert::null($db->selectValue('SELECT birth_year FROM swimmers WHERE id = :id', ['id' => $keep]));

            // 候補に積み直されている。
            Assert::true($db->selectValue(
                'SELECT 1 FROM swimmer_merge_candidates WHERE swimmer_id = :a AND candidate_id = :b',
                ['a' => $remove, 'b' => $keep]
            ) !== null);

            // 二重取り消しは拒否される。
            Assert::throws(DropTime\Import\ImportException::class, static fn () => $service->undo($mergeId));

            // 片付け（meet は CASCADE で events/results ごと消える）。
            $db->execute('DELETE FROM meets WHERE id = :id', ['id' => $meetId]);
        });
    },
];
