<?php

declare(strict_types=1);

namespace DropTime\Parser;

use DropTime\Support\Str;

/**
 * パース結果の中の選手。DB の swimmers に落とす前の状態。
 * 生年が書かれず年齢だけのことがあるため、両方を持てるようにしてある。
 */
final class ParsedSwimmer
{
    public function __construct(
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly ?int $birthYear = null,
        public readonly ?int $age = null,
        public readonly ?string $gender = null,
        public readonly ?string $teamCode = null,
        public readonly ?string $teamName = null,
    ) {
    }

    public function fullName(): string
    {
        return Str::collapseSpaces($this->firstName . ' ' . $this->lastName);
    }

    /** 突き合わせキー。照合順序に依存しない比較のために使う。 */
    public function normalizedKey(): string
    {
        return Str::swimmerKey($this->lastName, $this->firstName);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'last_name' => $this->lastName,
            'first_name' => $this->firstName,
            'birth_year' => $this->birthYear,
            'age' => $this->age,
            'gender' => $this->gender,
            'team_code' => $this->teamCode,
            'team_name' => $this->teamName,
            'normalized_key' => $this->normalizedKey(),
        ];
    }
}
