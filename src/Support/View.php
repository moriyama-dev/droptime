<?php

declare(strict_types=1);

namespace DropTime\Support;

use DropTime\Http\Router;
use RuntimeException;
use Throwable;

/**
 * テンプレートは素の PHP。値の出力は必ず e() を通す。
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(
        private readonly string $directory,
        private readonly Router $router,
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->capture($template, $data);

        if ($layout === '') {
            return $content;
        }

        return $this->capture($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * レイアウトなしで部品テンプレートを差し込む。画面どうしで共有する見出しなどに使う。
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }

    /** HTML エスケープ。テンプレートからはこれ以外の出力方法を使わない。 */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $string = is_scalar($value) ? (string) $value : '';

        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, string|int> $params */
    public function url(string $name, array $params = []): string
    {
        $base = Config::instance()->string('app.base_path', '');

        return $base . $this->router->url($name, $params);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function capture(string $template, array $data): string
    {
        $path = $this->directory . '/' . $template . '.php';

        if (!is_file($path)) {
            throw new RuntimeException('テンプレートが見つかりません: ' . $template);
        }

        $view = $this;
        $variables = array_merge($this->shared, $data);

        ob_start();

        try {
            (static function (string $__path, array $__vars, View $view): void {
                extract($__vars, EXTR_SKIP);
                require $__path;
            })($path, $variables, $view);
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
