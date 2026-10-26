<?php

declare(strict_types=1);

namespace DropTime\Parser;

/**
 * パーサーの戻り値。
 *
 * 認識できた構造だけでなく、認識できなかった行と警告も必ず持つ。
 * 静かに落とすのが一番危険なので、未認識行は確認画面に全件出す。
 */
final class ParseResult
{
    private ?ParsedMeet $meet = null;

    /** @var list<ParsedEvent> */
    private array $events = [];

    /** @var list<UnrecognizedLine> */
    private array $unrecognized = [];

    /** @var list<string> */
    private array $warnings = [];

    /** 保持を打ち切った未認識行の数。件数だけは正しく数える。 */
    private int $unrecognizedOverflow = 0;

    /**
     * 行番号 => その行をどう解釈したか。確認画面でパース前後を並べるのに使う。
     *
     * @var array<int, string>
     */
    private array $lineKinds = [];

    /**
     * 未認識行を保持する上限。memory_limit が 128M しかないため、
     * 巨大なファイルを丸ごと配列に載せない。
     */
    private const MAX_UNRECOGNIZED = 2000;

    /** 行の解釈を記録する範囲。確認画面が使うのは先頭だけなので十分。 */
    private const MAX_NOTED_LINE = 200;

    public function __construct(public readonly string $parserName)
    {
    }

    public function setMeet(ParsedMeet $meet): void
    {
        $this->meet = $meet;
    }

    public function meet(): ?ParsedMeet
    {
        return $this->meet;
    }

    public function addEvent(ParsedEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<ParsedEvent> */
    public function events(): array
    {
        return $this->events;
    }

    public function addUnrecognized(int $lineNumber, string $text): void
    {
        if (count($this->unrecognized) >= self::MAX_UNRECOGNIZED) {
            $this->unrecognizedOverflow++;

            return;
        }

        $this->unrecognized[] = new UnrecognizedLine($lineNumber, $text);
    }

    /**
     * その行をどの種類として解釈したかを記録する。
     * 確認画面の「パース前後の対比」に使うので、先頭のほうだけあればよい。
     */
    public function noteLine(int $lineNumber, string $kind): void
    {
        if ($lineNumber > self::MAX_NOTED_LINE) {
            return;
        }

        $this->lineKinds[$lineNumber] = $kind;
    }

    /** @return array<int, string> */
    public function lineKinds(): array
    {
        return $this->lineKinds;
    }

    /** 表示を打ち切った行数。0 なら全件を保持している。 */
    public function unrecognizedOverflow(): int
    {
        return $this->unrecognizedOverflow;
    }

    /** @return list<UnrecognizedLine> */
    public function unrecognized(): array
    {
        return $this->unrecognized;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * 確認画面に出す件数。
     *
     * @return array{events: int, entries: int, results: int, splits: int, swimmers: int, unrecognized: int, warnings: int}
     */
    public function counts(): array
    {
        $entries = 0;
        $results = 0;
        $splits = 0;
        $swimmerKeys = [];

        foreach ($this->events as $event) {
            $entries += count($event->entries());
            $results += count($event->results());
            $splits += $event->splitCount();

            foreach ($event->entries() as $entry) {
                if ($entry->swimmer !== null) {
                    $swimmerKeys[$entry->swimmer->normalizedKey()] = true;
                }
            }

            foreach ($event->results() as $result) {
                if ($result->swimmer !== null) {
                    $swimmerKeys[$result->swimmer->normalizedKey()] = true;
                }
            }
        }

        return [
            'events' => count($this->events),
            'entries' => $entries,
            'results' => $results,
            'splits' => $splits,
            'swimmers' => count($swimmerKeys),
            'unrecognized' => count($this->unrecognized) + $this->unrecognizedOverflow,
            'warnings' => count($this->warnings),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->events === [] && $this->meet === null;
    }

    /**
     * フィクスチャの期待値と突き合わせるための配列表現。
     * キーの順序を固定しているので JSON にしたときも安定する。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'parser' => $this->parserName,
            'meet' => $this->meet?->toArray(),
            'counts' => $this->counts(),
            'events' => array_map(static fn (ParsedEvent $e): array => $e->toArray(), $this->events),
            'unrecognized' => array_map(static fn (UnrecognizedLine $l): array => $l->toArray(), $this->unrecognized),
            'unrecognized_overflow' => $this->unrecognizedOverflow,
            'warnings' => $this->warnings,
        ];
    }
}
