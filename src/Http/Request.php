<?php

declare(strict_types=1);

namespace DropTime\Http;

/**
 * スーパーグローバルへの参照をここだけに閉じ込める。
 */
final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, string> $params ルートのパスパラメータ
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly string $ip,
        public readonly bool $secure,
        public array $params = [],
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $path = '/' . ltrim($path, '/');

        if ($path !== '/' ) {
            $path = rtrim($path, '/');
        }

        /** @var array<string, string> $query */
        $query = array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $_GET);

        return new self(
            $method,
            $path,
            $query,
            $_POST,
            $_FILES,
            self::clientIp(),
            self::isSecure(),
        );
    }

    public function param(string $name, string $default = ''): string
    {
        return $this->params[$name] ?? $default;
    }

    public function intParam(string $name): int
    {
        return (int) ($this->params[$name] ?? '0');
    }

    public function queryString(string $name, string $default = ''): string
    {
        $value = $this->query[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function postString(string $name, string $default = ''): string
    {
        $value = $this->post[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** レート制限のキーに使うため、バイナリ表現で返す。 */
    public function ipBinary(): string
    {
        $packed = @inet_pton($this->ip);

        return $packed === false ? str_repeat("\0", 4) : $packed;
    }

    private static function clientIp(): string
    {
        // 逆プロキシのヘッダーは信用しない。偽装でレート制限を回避されるため。
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }

    private static function isSecure(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }
}
