<?php

declare(strict_types=1);

namespace DropTime\Parser;

/**
 * スプリット。累積タイムで持ち、区間タイムは表示時に引き算で出す。
 */
final class ParsedSplit
{
    public function __construct(
        public readonly int $distance,
        public readonly int $cumulativeTimeCs,
    ) {
    }

    /** @return array{distance: int, cumulative_time_cs: int} */
    public function toArray(): array
    {
        return [
            'distance' => $this->distance,
            'cumulative_time_cs' => $this->cumulativeTimeCs,
        ];
    }
}
