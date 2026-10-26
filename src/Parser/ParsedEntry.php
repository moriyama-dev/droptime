<?php

declare(strict_types=1);

namespace DropTime\Parser;

/**
 * ヒートシートの 1 行に相当するエントリー。
 * リレーは swimmer が null で teamCode / relayLetter が入る。
 */
final class ParsedEntry
{
    public function __construct(
        public readonly ?ParsedSwimmer $swimmer,
        public readonly ?int $heat = null,
        public readonly ?int $lane = null,
        public readonly ?int $seedTimeCs = null,
        public readonly bool $seedIsNTime = false,
        public readonly ?string $teamCode = null,
        public readonly ?string $relayLetter = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'heat' => $this->heat,
            'lane' => $this->lane,
            'seed_time_cs' => $this->seedTimeCs,
            'seed_is_ntime' => $this->seedIsNTime,
            'team_code' => $this->teamCode,
            'relay_letter' => $this->relayLetter,
            'swimmer' => $this->swimmer?->toArray(),
        ];
    }
}
