<?php

declare(strict_types=1);

namespace DropTime\Tests;

use RuntimeException;

final class AssertionFailed extends RuntimeException
{
}

/**
 * 依存を増やさないための最小限のアサーション。
 */
final class Assert
{
    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                '%s期待値 %s に対して実際は %s',
                $message === '' ? '' : $message . ': ',
                self::describe($expected),
                self::describe($actual)
            ));
        }
    }

    public static function true(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new AssertionFailed($message === '' ? '真であるべき条件が偽でした' : $message);
        }
    }

    public static function false(bool $condition, string $message = ''): void
    {
        self::true(!$condition, $message);
    }

    public static function null(mixed $value, string $message = ''): void
    {
        self::same(null, $value, $message);
    }

    public static function throws(string $expectedClass, callable $callback, string $message = ''): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!$e instanceof $expectedClass) {
                throw new AssertionFailed(sprintf('%s ではなく %s が投げられました', $expectedClass, $e::class));
            }

            return;
        }

        throw new AssertionFailed($message === '' ? $expectedClass . ' が投げられませんでした' : $message);
    }

    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . $value . "'";
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
