<?php

declare(strict_types=1);

namespace DropTime\Import;

use DropTime\Db\Database;
use DropTime\Parser\ParserRegistry;
use DropTime\Parser\ParseResult;
use DropTime\Support\Logger;
use Throwable;

/**
 * 取り込みフローの束ね役。
 *
 *   アップロード → 保存 → テキスト化 → パース → （人間の承認）→ DB 投入
 *
 * パース結果の全体は DB に持たず、確認画面用の要約だけを summary_json に置く。
 * 投入時は保存済みテキストを同じパーサーでもう一度パースする。パーサーは
 * 決定的なので同じ結果になり、巨大な中間データを保存せずに済む。
 */
final class ImportService
{
    /** 確認画面の「パース前後の対比」に出す行数。 */
    private const PREVIEW_LINES = 20;

    /** summary_json に保存する未認識行の上限。全体の件数は別途持つ。 */
    private const SUMMARY_UNRECOGNIZED = 200;

    public function __construct(
        private readonly Database $db,
        private readonly ImportStore $store,
        private readonly ImportRepository $repository,
        private readonly ParserRegistry $parsers,
    ) {
    }

    public static function create(Database $db): self
    {
        return new self($db, ImportStore::fromConfig(), new ImportRepository($db), ParserRegistry::default());
    }

    /**
     * アップロードを受け取り、テキスト化とパースまで進めて import の ID を返す。
     * 途中で失敗しても例外は投げず、状態を failed にして画面に理由を出す。
     */
    public function receive(UploadedFile $file): int
    {
        $rawRelative = $this->store->storeUpload($file);
        $id = $this->repository->create($file, $rawRelative);

        try {
            $text = $this->extract($file->kind, $rawRelative);
            $this->repository->markExtracted($id, $this->store->textPathFor($rawRelative));

            $this->parseAndSummarize($id, $text, $file->sha256);
        } catch (ImportException $e) {
            $this->repository->markFailed($id, $e->getMessage());
        } catch (Throwable $e) {
            Logger::error('取り込みに失敗', $e);
            $this->repository->markFailed($id, '内部エラーで取り込みに失敗しました。ログを確認してください。');
        }

        return $id;
    }

    /**
     * 保存済みのテキストをもう一度パースする。パーサーを直したあとに使う。
     */
    public function reparse(int $id): void
    {
        $row = $this->requireRow($id);

        if ($row['status'] === 'committed') {
            throw new ImportException('投入済みの取り込みは再パースできません。');
        }

        try {
            $text = $this->textOf($row);
            $this->parseAndSummarize($id, $text, (string) $row['sha256']);
        } catch (ImportException $e) {
            $this->repository->markFailed($id, $e->getMessage());
        }
    }

    /**
     * 承認された取り込みを DB に投入する。
     *
     * @param array{name: string, start_date: string, course: string,
     *               end_date?: ?string, host?: ?string, venue?: ?string} $meet
     * @return array{meet_id: int, events: int, entries: int, results: int}
     */
    public function commit(int $id, array $meet): array
    {
        $row = $this->requireRow($id);

        if ($row['status'] !== 'parsed') {
            throw new ImportException('この取り込みは投入できる状態ではありません（状態: ' . $row['status'] . '）。');
        }

        $result = $this->parseText($this->textOf($row), (string) ($row['parser'] ?? ''));

        if ($result->events() === []) {
            throw new ImportException('認識できた種目が 0 件のため投入できません。パーサーの対応が必要です。');
        }

        // 出典 URL が記録されていればそれを、無ければ保存ファイル名を出典にする。
        $summary = ImportRepository::summaryOf($row);
        $source = is_string($summary['source_url'] ?? null) && $summary['source_url'] !== ''
            ? $summary['source_url']
            : (string) $row['raw_path'];

        $committer = ImportCommitter::create($this->db);
        $stats = $committer->commit($result, $meet, $source);

        $this->repository->markCommitted($id, $stats['meet_id']);

        return $stats;
    }

    public function discard(int $id): void
    {
        $row = $this->requireRow($id);

        if ($row['status'] === 'committed') {
            throw new ImportException('投入済みの取り込みは捨てられません。');
        }

        $this->repository->markDiscarded($id);
    }

    /** @return array<string, mixed> */
    private function requireRow(int $id): array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            throw new ImportException('取り込みが見つかりません。');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function textOf(array $row): string
    {
        $textPath = $row['text_path'] ?? null;

        if (!is_string($textPath) || $textPath === '') {
            throw new ImportException('テキスト化が済んでいないため処理できません。');
        }

        return $this->store->readText($textPath);
    }

    private function extract(string $kind, string $rawRelative): string
    {
        $rawAbsolute = $this->store->absolute($rawRelative);
        $textRelative = $this->store->textPathFor($rawRelative);

        if ($kind === UploadedFile::KIND_PDF) {
            return PdfTextExtractor::fromConfig()->extract($rawAbsolute, $this->store->absolute($textRelative));
        }

        $html = file_get_contents($rawAbsolute);

        if ($html === false) {
            throw new ImportException('保存したファイルを読めませんでした。');
        }

        $text = (new HtmlTextExtractor())->extract($html);
        $this->store->writeText($textRelative, $text);

        return $text;
    }

    private function parseAndSummarize(int $id, string $text, string $sha256): void
    {
        $result = $this->parseText($text, null);
        $summary = $this->summarize($result, $text);

        $duplicate = $this->repository->findDuplicate($sha256, $id);

        if ($duplicate !== null) {
            $summary['duplicate_of'] = (int) $duplicate['id'];
        }

        $this->repository->markParsed($id, $result->parserName, $summary);
    }

    /**
     * パーサー名の指定があればそれを、無ければ確からしさで選んでパースする。
     */
    private function parseText(string $text, ?string $parserName): ParseResult
    {
        $parser = null;

        if ($parserName !== null && $parserName !== '') {
            $parser = $this->parsers->byName($parserName);
        }

        $parser ??= $this->parsers->select($text);

        return $parser->parse($text);
    }

    /**
     * 確認画面に必要な要約だけを組み立てる。
     *
     * @return array<string, mixed>
     */
    private function summarize(ParseResult $result, string $text): array
    {
        $parser = $this->parsers->byName($result->parserName);
        $unrecognized = $result->unrecognized();

        return [
            'parser_label' => $parser?->label() ?? $result->parserName,
            'counts' => $result->counts(),
            'warnings' => $result->warnings(),
            'meet' => $result->meet()?->toArray(),
            'preview' => self::preview($text, $result->lineKinds()),
            'unrecognized' => array_map(
                static fn ($line): array => $line->toArray(),
                array_slice($unrecognized, 0, self::SUMMARY_UNRECOGNIZED)
            ),
            'unrecognized_total' => count($unrecognized) + $result->unrecognizedOverflow(),
        ];
    }

    /**
     * 先頭 20 行（空行を除く）の「パース前後の対比」。
     *
     * @param array<int, string> $lineKinds
     * @return list<array{line: int, text: string, kind: string}>
     */
    private static function preview(string $text, array $lineKinds): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $preview = [];

        foreach (explode("\n", $normalized) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $number = $index + 1;
            $preview[] = [
                'line' => $number,
                'text' => mb_substr(rtrim($line), 0, 160, 'UTF-8'),
                'kind' => $lineKinds[$number] ?? '未認識',
            ];

            if (count($preview) >= self::PREVIEW_LINES) {
                break;
            }
        }

        return $preview;
    }
}
