<?php

declare(strict_types=1);

namespace DropTime\Fetch;

use DateTimeImmutable;
use DateTimeZone;
use DropTime\Db\Database;
use DropTime\Import\HyTekBundle;
use DropTime\Import\ImportException;
use DropTime\Import\ImportRepository;
use DropTime\Import\ImportService;
use DropTime\Import\UploadValidator;
use Throwable;

/**
 * 管理画面の「最新の記録を取得」の本体。bin/fetch-latest.php から動く。
 *
 *   1. 速報サイトのフォルダ一覧を読み、全部の大会について目次と「最後に終わった種目」の
 *      更新日時を見て、前回から中身が変わったか（または一度も中身を見ていないか）を調べる
 *   2. 変わった大会の種目ページを全部保存する（var/manual/sources/<大会>）
 *   3. 結果のページだけを残し（大会前の Psych Sheet は捨てる）、
 *      クラブ（fetch.club_code）の選手が 1 人でも載っていれば取り込んで DB に投入する
 *
 * 手でアップロードしたときは確認画面を経て人が投入するが、ここでは
 * 確認画面を通さずに投入する（管理者がボタンで明示的に頼んだ処理のため）。
 * 同じ大会を投入し直しても、ImportCommitter が種目単位で置き換えるので二重にならない。
 * 管理者が確認画面で直した大会情報（会場・主催など）は上書きしない。
 *
 * 古い大会もクラブの選手が出ていない大会も、毎回すべて確認する（後から結果が差し替わることがあるため）。
 * どの大会をどこまで見たかは状態ファイル（JSON）に持ち、種目ページを読み直すのは中身が変わった大会だけ。
 */
final class LatestRecordsFetcher
{
    /** @var array{seeded_at?: string, folders: array<string, array<string, mixed>>} */
    private array $state = ['folders' => []];

    /** @var callable(string): void */
    private $log;

    /**
     * @param callable(string): void $log 進み具合を 1 行ずつ受け取る
     */
    public function __construct(
        private readonly Database $db,
        private readonly PortalClient $client,
        private readonly string $stateFile,
        private readonly string $sourcesDir,
        private readonly string $clubCode,
        callable $log,
        private readonly ?\Closure $afterCommit = null,
    ) {
        $this->log = $log;
    }

    /**
     * @param string|null $onlyFolder 1 つの大会フォルダだけを（状態に関係なく）処理する
     * checked は確認した大会の数で、unchanged（前回から変化なし）はその内数。
     *
     * @return array{checked: int, unchanged: int, imported: list<array<string, mixed>>,
     *               skipped: list<array<string, string>>, failed: list<array<string, string>>, requests: int}
     */
    public function run(?string $onlyFolder = null): array
    {
        $this->loadState();
        $summary = ['checked' => 0, 'unchanged' => 0, 'imported' => [], 'skipped' => [], 'failed' => [], 'requests' => 0];

        $root = $this->client->get($this->client->baseUrl());

        if ($root['status'] !== 200) {
            throw new \RuntimeException("速報サイトの一覧を読めませんでした（HTTP {$root['status']}）。");
        }

        $folders = PortalPages::folders($root['body']);
        ($this->log)(sprintf('速報サイトの大会フォルダ: %d 件', count($folders)));

        if (!isset($this->state['seeded_at'])) {
            $this->seed($folders);
        }

        usort($folders, static fn (array $a, array $b): int => strcmp($a['modified'], $b['modified']));

        foreach ($folders as $folder) {
            $name = $folder['folder'];

            if ($onlyFolder !== null && $name !== $onlyFolder) {
                continue;
            }

            $entry = $this->state['folders'][$name] ?? null;
            $summary['checked']++;

            try {
                $result = $this->process($folder, $entry, $onlyFolder !== null);
            } catch (Throwable $e) {
                $result = ['status' => 'failed', 'note' => $e->getMessage()];
            }

            $this->state['folders'][$name] = array_merge($entry ?? [], $result['state'] ?? [], [
                'modified' => $folder['modified'],
                // 中身が変わっていなければ、前回の判定（取り込み済み・対象外など）をそのまま残す。
                'status' => $result['status'] === 'unchanged' ? ($entry['status'] ?? 'unchanged') : $result['status'],
                'note' => $result['note'] ?? '',
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->saveState();

            $label = rawurldecode($name);

            match ($result['status']) {
                'imported' => $summary['imported'][] = ['folder' => $label] + $result['import'],
                'failed' => $summary['failed'][] = ['folder' => $label, 'note' => (string) ($result['note'] ?? '')],
                'unchanged' => $summary['unchanged']++,
                default => $summary['skipped'][] = ['folder' => $label, 'status' => $result['status'], 'note' => (string) ($result['note'] ?? '')],
            };

            ($this->log)(sprintf('  → %s: %s %s', $label, $result['status'], $result['note'] ?? ''));
        }

        $summary['requests'] = $this->client->requests();

        return $summary;
    }

    /**
     * 初回だけ: 取り込み済みの大会を状態に記録する（大会 ID の対応づけのため）。
     * 中身の確認は、取り込み済みの大会も含めて run() が全大会に対して行う。
     *
     * @param list<array{folder: string, modified: string}> $folders
     */
    private function seed(array $folders): void
    {
        $imported = [];

        foreach ($this->db->select("SELECT id, source_file FROM meets WHERE source_file LIKE 'http%'") as $meet) {
            $imported[rtrim((string) $meet['source_file'], '/') . '/'] = (int) $meet['id'];
        }

        foreach ($folders as $folder) {
            $url = $this->client->folderUrl($folder['folder']);

            if (isset($imported[$url])) {
                $this->state['folders'][$folder['folder']] = [
                    'modified' => $folder['modified'], 'status' => 'imported', 'meet_id' => $imported[$url],
                    'note' => '取り込み済み（初回の基準）', 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ];
            }
        }

        $this->state['seeded_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->saveState();
    }

    /**
     * @param array{folder: string, modified: string} $folder
     * @param array<string, mixed>|null              $entry
     * @return array{status: string, note?: string, state?: array<string, mixed>, import?: array<string, mixed>}
     */
    private function process(array $folder, ?array $entry, bool $force): array
    {
        $name = $folder['folder'];
        $url = $this->client->folderUrl($name);
        ($this->log)('確認中: ' . rawurldecode($name));

        $index = $this->client->get($url . 'evtindex.htm');

        if ($index['status'] !== 200) {
            return ['status' => 'not_hytek', 'note' => "種目の目次がありません（HTTP {$index['status']}）"];
        }

        $meta = PortalPages::eventIndex($index['body']);
        $lastEvent = $meta['last_event'] !== null ? $this->client->lastModified($url . $meta['last_event']) : null;
        $signature = implode('|', [$folder['modified'], $index['last_modified'] ?? '', $lastEvent ?? '']);
        $state = ['signature' => $signature, 'meet_name' => $meta['name'], 'meet_start' => $meta['start'], 'meet_end' => $meta['end']];

        if (!$force && $entry !== null && ($entry['signature'] ?? null) === $signature) {
            return ['status' => 'unchanged', 'note' => '前回から変化なし', 'state' => $state];
        }

        if ($meta['pages'] === []) {
            return ['status' => 'not_results', 'note' => '種目ページがまだありません', 'state' => $state];
        }

        if ($meta['start'] !== null && $meta['start'] > $this->today()->format('Y-m-d')) {
            return ['status' => 'not_results', 'note' => "大会前（{$meta['start']} 開始）。結果が出たら取り込みます", 'state' => $state];
        }

        // ── 種目ページを全部保存する ─────────────────────────────
        $work = rtrim($this->sourcesDir, '/') . '/.incoming-' . $name;
        self::removeDir($work);
        @mkdir($work, 0775, true);
        $resultsFiles = [];
        $clubFound = false;

        foreach ($meta['pages'] as $page) {
            $response = $this->client->get($url . $page);

            if ($response['status'] !== 200) {
                continue;
            }

            file_put_contents($work . '/' . $page, $response['body']);

            if (PortalPages::isResultsPage($response['body'])) {
                $resultsFiles[] = $work . '/' . $page;
                $clubFound = $clubFound || PortalPages::mentionsClub($response['body'], $this->clubCode);
            }
        }

        // 目次も原本として残す（どの種目があったかの証拠になる）。
        file_put_contents($work . '/evtindex.htm', $index['body']);
        ($this->log)(sprintf('  %d ページ中、結果のページ %d', count($meta['pages']), count($resultsFiles)));

        if ($resultsFiles === []) {
            self::removeDir($work);

            return ['status' => 'not_results', 'note' => '結果のページがまだありません（Psych Sheet のみ）', 'state' => $state];
        }

        if (!$clubFound) {
            self::removeDir($work);

            return ['status' => 'no_club', 'note' => "{$this->clubCode} の選手が出ていない大会", 'state' => $state];
        }

        // 原本の置き場を入れ替える（前回の保存があれば新しいものに差し替え）。
        $final = rtrim($this->sourcesDir, '/') . '/' . $name;
        self::removeDir($final);
        rename($work, $final);
        $resultsFiles = array_map(static fn (string $f): string => $final . '/' . basename($f), $resultsFiles);

        $import = $this->importAndCommit($resultsFiles, $name, $url);

        if ($this->afterCommit !== null) {
            ($this->afterCommit)($import['meet_id'], $url, $final);
        }

        return ['status' => 'imported', 'note' => $import['meet_name'], 'state' => $state + ['meet_id' => $import['meet_id']], 'import' => $import];
    }

    /**
     * @param list<string> $files 結果のページだけ
     * @return array{meet_id: int, meet_name: string, import_id: int, is_new: bool, events: int, results: int, pages: int}
     */
    private function importAndCommit(array $files, string $folder, string $sourceUrl): array
    {
        $bundle = HyTekBundle::combine($files);
        $temporary = tempnam(sys_get_temp_dir(), 'hytek');

        if ($temporary === false || file_put_contents($temporary, $bundle['html']) === false) {
            throw new ImportException('一時ファイルを作れませんでした。');
        }

        try {
            $uploaded = (new UploadValidator())->inspect($temporary, rawurldecode($folder) . '.html');
            $service = ImportService::create($this->db);
            $id = $service->receive($uploaded);
        } finally {
            @unlink($temporary);
        }

        $repository = new ImportRepository($this->db);
        $repository->mergeSummary($id, ['source_url' => $sourceUrl, 'fetched_automatically' => true]);
        $row = $repository->find($id);
        $summary = $row === null ? [] : ImportRepository::summaryOf($row);

        if (($row['status'] ?? '') !== 'parsed') {
            throw new ImportException('パースに失敗しました: ' . ($summary['error'] ?? '不明') . "（取り込み #{$id}）");
        }

        if ((int) ($summary['counts']['results'] ?? 0) === 0) {
            $service->discard($id);

            throw new ImportException("結果を 1 件も読み取れませんでした（取り込み #{$id} は破棄）");
        }

        $meet = is_array($summary['meet'] ?? null) ? $summary['meet'] : [];
        $meet = array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $meet);

        // 既に投入済みの大会なら、管理者が確認画面で直した値（会場・主催など）を残す。
        $existing = $this->db->selectOne(
            'SELECT id, name, start_date, end_date, course, host, venue FROM meets WHERE name = :name AND start_date = :start',
            ['name' => (string) ($meet['name'] ?? ''), 'start' => (string) ($meet['start_date'] ?? '')]
        );

        if ($existing !== null) {
            $meet = [
                'name' => (string) $existing['name'],
                'start_date' => (string) $existing['start_date'],
                'end_date' => $existing['end_date'] === null ? null : (string) $existing['end_date'],
                'course' => (string) $existing['course'],
                'host' => $existing['host'] === null ? null : (string) $existing['host'],
                'venue' => $existing['venue'] === null ? null : (string) $existing['venue'],
            ];
        }

        $stats = $service->commit($id, $meet + ['name' => '', 'start_date' => '', 'course' => '']);

        return [
            'meet_id' => $stats['meet_id'],
            'meet_name' => (string) $meet['name'],
            'import_id' => $id,
            'is_new' => $existing === null,
            'events' => $stats['events'],
            'results' => $stats['results'],
            'pages' => $bundle['pages'],
        ];
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/Toronto'));
    }

    private function loadState(): void
    {
        if (!is_file($this->stateFile)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($this->stateFile), true);

        if (is_array($decoded) && is_array($decoded['folders'] ?? null)) {
            $this->state = $decoded;
        }
    }

    private function saveState(): void
    {
        @mkdir(dirname($this->stateFile), 0775, true);
        $temporary = $this->stateFile . '.tmp';
        file_put_contents($temporary, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($temporary, $this->stateFile);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? self::removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
