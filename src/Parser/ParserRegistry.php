<?php

declare(strict_types=1);

namespace DropTime\Parser;

use DropTime\Parser\HyTek\HyTekResultsParser;
use RuntimeException;

/**
 * 使えるパーサーの一覧と選択。
 *
 * 主催者ごとに書式が違うため、テキストを見て確からしさの高いものを選ぶ。
 * 新しい書式に対応するときは、フィクスチャを足してからここに登録する。
 */
final class ParserRegistry
{
    /** @var list<ParserInterface> */
    private array $parsers;

    /** @param list<ParserInterface> $parsers */
    public function __construct(array $parsers)
    {
        $this->parsers = $parsers;
    }

    public static function default(): self
    {
        return new self([
            // 実書式のパーサー。スタブは常に最後の受け皿。
            new HyTekResultsParser(),
            new NullParser(),
        ]);
    }

    /** 確からしさが最も高いパーサーを返す。 */
    public function select(string $text): ParserInterface
    {
        $best = null;
        $bestScore = 0;

        foreach ($this->parsers as $parser) {
            $score = $parser->confidence($text);

            if ($score > $bestScore) {
                $best = $parser;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            throw new RuntimeException('このテキストを扱えるパーサーがありません。');
        }

        return $best;
    }

    public function byName(string $name): ?ParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->name() === $name) {
                return $parser;
            }
        }

        return null;
    }

    /** @return list<ParserInterface> */
    public function all(): array
    {
        return $this->parsers;
    }
}
