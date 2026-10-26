<?php

declare(strict_types=1);

namespace DropTime\Http;

abstract class Controller
{
    public function __construct(protected readonly Context $context)
    {
    }

    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->context->view->render($template, $data), $status);
    }

    /** @param array<string, string|int> $params */
    protected function redirectToRoute(string $name, array $params = []): Response
    {
        $base = $this->context->config->string('app.base_path', '');

        return Response::redirect($base . $this->context->router->url($name, $params));
    }
}
