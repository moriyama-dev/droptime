<?php

declare(strict_types=1);

namespace DropTime\Parser;

use DropTime\Domain\Course;
use DropTime\Domain\Stroke;

/**
 * 種目 1 件と、その配下のエントリー・結果。
 */
final class ParsedEvent
{
    /** @var list<ParsedEntry> */
    private array $entries = [];

    /** @var list<ParsedResultRow> */
    private array $results = [];

    public function __construct(
        public readonly string $number,
        public readonly string $round,
        public readonly string $gender,
        public readonly int $distance,
        public readonly Stroke $stroke,
        public readonly Course $course,
        public readonly ?string $ageGroup = null,
        public readonly bool $isRelay = false,
        public readonly ?string $scheduledTime = null,
        public readonly int $sortOrder = 0,
    ) {
    }

    public function addEntry(ParsedEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function addResult(ParsedResultRow $result): void
    {
        $this->results[] = $result;
    }

    /** @return list<ParsedEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<ParsedResultRow> */
    public function results(): array
    {
        return $this->results;
    }

    public function splitCount(): int
    {
        $count = 0;

        foreach ($this->results as $result) {
            $count += count($result->splits);
        }

        return $count;
    }

    public function title(): string
    {
        $gender = match ($this->gender) {
            'M' => '男子',
            'F' => '女子',
            default => '混合',
        };

        return sprintf(
            '%s 種目 %s %s%s %dm %s',
            $gender,
            $this->number,
            $this->ageGroup !== null ? $this->ageGroup . ' ' : '',
            $this->isRelay ? 'リレー' : '',
            $this->distance,
            $this->stroke->label()
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'round' => $this->round,
            'gender' => $this->gender,
            'age_group' => $this->ageGroup,
            'distance' => $this->distance,
            'stroke' => $this->stroke->value,
            'course' => $this->course->value,
            'is_relay' => $this->isRelay,
            'scheduled_time' => $this->scheduledTime,
            'sort_order' => $this->sortOrder,
            'entries' => array_map(static fn (ParsedEntry $e): array => $e->toArray(), $this->entries),
            'results' => array_map(static fn (ParsedResultRow $r): array => $r->toArray(), $this->results),
        ];
    }
}
