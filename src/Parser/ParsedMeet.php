<?php

declare(strict_types=1);

namespace DropTime\Parser;

use DropTime\Domain\Course;

/**
 * 大会そのものの情報。日付は現地の暦日を表す ISO 8601 の文字列。
 */
final class ParsedMeet
{
    public function __construct(
        public readonly string $name,
        public readonly string $startDate,
        public readonly Course $course,
        public readonly ?string $endDate = null,
        public readonly ?string $host = null,
        public readonly ?string $venue = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'host' => $this->host,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'course' => $this->course->value,
            'venue' => $this->venue,
        ];
    }
}
