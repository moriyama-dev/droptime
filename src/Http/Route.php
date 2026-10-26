<?php

declare(strict_types=1);

namespace DropTime\Http;

final class Route
{
    /**
     * @param class-string $controller
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $controller,
        public readonly string $action,
        public readonly Access $access,
        public readonly string $name,
    ) {
    }

    /**
     * '/meets/{meet}/events/{event}' のようなパスを正規表現に変換する。
     * パラメータは '/' を含まない 1 区切りぶんに限定する。
     */
    public function pattern(): string
    {
        $quoted = preg_quote($this->path, '#');
        $pattern = preg_replace('#\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}#', '(?P<$1>[^/]+)', $quoted);

        return '#^' . $pattern . '$#u';
    }

    /** @param array<string, string|int> $params */
    public function url(array $params = []): string
    {
        $path = $this->path;

        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
        }

        return $path;
    }
}
