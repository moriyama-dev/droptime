<?php

declare(strict_types=1);

namespace DropTime\Parser;

/**
 * 結果 1 件。
 *
 * 失格・棄権は時間を null にし、status で区別する。0 秒として保存しない。
 * status は results.status と同じ語彙（OK / DQ / NS / DFS / SCR）。
 */
final class ParsedResultRow
{
    /** @param list<ParsedSplit> $splits */
    public function __construct(
        public readonly ?ParsedSwimmer $swimmer,
        public readonly ?int $finalTimeCs = null,
        public readonly ?int $place = null,
        public readonly string $status = 'OK',
        public readonly ?string $dqReason = null,
        public readonly ?float $points = null,
        public readonly ?int $heat = null,
        public readonly ?int $lane = null,
        public readonly ?string $teamCode = null,
        public readonly ?string $relayLetter = null,
        public readonly array $splits = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'place' => $this->place,
            'final_time_cs' => $this->finalTimeCs,
            'status' => $this->status,
            'dq_reason' => $this->dqReason,
            'points' => $this->points,
            'heat' => $this->heat,
            'lane' => $this->lane,
            'team_code' => $this->teamCode,
            'relay_letter' => $this->relayLetter,
            'swimmer' => $this->swimmer?->toArray(),
            'splits' => array_map(static fn (ParsedSplit $s): array => $s->toArray(), $this->splits),
        ];
    }
}
