<?php

declare(strict_types=1);

namespace DropTime\Domain;

use DropTime\Support\Lang;

/**
 * コース。同じ 100 自由形でもコースが違えば別種目として扱う。
 */
enum Course: string
{
    case SCM = 'SCM';
    case LCM = 'LCM';
    case SCY = 'SCY';

    public function label(): string
    {
        return Lang::t('course.' . $this->value);
    }

    public static function tryFromLabel(string $raw): ?self
    {
        $normalized = strtoupper(trim($raw));

        return match ($normalized) {
            'SCM', 'S', '25M' => self::SCM,
            'LCM', 'L', '50M' => self::LCM,
            'SCY', 'Y', '25Y' => self::SCY,
            default => null,
        };
    }
}
