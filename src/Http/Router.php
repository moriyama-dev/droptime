<?php

declare(strict_types=1);

namespace DropTime\Http;

use InvalidArgumentException;

/**
 * ルーティングは静的な表だけで完結させる。動的な登録は行わない。
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /**
     * ルート定義の配列から組み立てる。
     * 1 件は [メソッド, パス, [コントローラ, アクション], アクセス区分, 名前]。
     *
     * @param list<array{0: string, 1: string, 2: array{0: string, 1: string}, 3: Access, 4: string}> $definitions
     */
    public static function fromDefinitions(array $definitions): self
    {
        $router = new self();

        foreach ($definitions as $definition) {
            if (count($definition) !== 5) {
                throw new InvalidArgumentException(
                    'ルート定義には メソッド・パス・ハンドラ・アクセス区分・名前 の 5 要素が必要です。'
                );
            }

            [$method, $path, $handler, $access, $name] = $definition;

            $router->add(new Route(
                strtoupper($method),
                $path,
                $handler[0],
                $handler[1],
                $access,
                $name
            ));
        }

        return $router;
    }

    public function add(Route $route): void
    {
        if (isset($this->named[$route->name])) {
            throw new InvalidArgumentException('ルート名が重複しています: ' . $route->name);
        }

        $this->routes[] = $route;
        $this->named[$route->name] = $route;
    }

    /**
     * パスに一致するルートを返す。
     * パスは一致するがメソッドが違う場合は 405 を投げる。
     *
     * @return array{0: Route, 1: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route->pattern(), $path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            if ($route->method !== $method) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return [$route, $params];
        }

        if ($pathMatched) {
            throw new HttpException(405, 'メソッドが許可されていません: ' . $method . ' ' . $path);
        }

        return null;
    }

    /** @param array<string, string|int> $params */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new InvalidArgumentException('未定義のルート名です: ' . $name);
        }

        return $this->named[$name]->url($params);
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
