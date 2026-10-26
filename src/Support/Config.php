<?php

declare(strict_types=1);

namespace DropTime\Support;

use RuntimeException;

/**
 * 設定は config/config.php が返す配列そのもの。
 * .env を自前で解析するより、PHP の配列をそのまま読むほうが確実で速い。
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    private static ?self $instance = null;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException('設定ファイルが見つかりません: ' . $path);
        }

        /** @var mixed $values */
        $values = require $path;

        if (!is_array($values)) {
            throw new RuntimeException('設定ファイルは配列を return する必要があります: ' . $path);
        }

        self::$instance = new self($values);

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('設定が読み込まれていません。');
        }

        return self::$instance;
    }

    /**
     * ドット区切りで値を取り出す。例: get('db.host')
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    public function isDev(): bool
    {
        return $this->string('app.env', 'prod') === 'dev';
    }
}
