<?php

declare(strict_types=1);

namespace DropTime\Parser;

/**
 * 解釈できなかった行。握りつぶさず必ずここに積み、確認画面に出す。
 */
final class UnrecognizedLine
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $text,
    ) {
    }

    /** @return array{line: int, text: string} */
    public function toArray(): array
    {
        return ['line' => $this->lineNumber, 'text' => $this->text];
    }
}
