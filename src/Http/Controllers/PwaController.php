<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Http\Controller;
use DropTime\Http\Request;
use DropTime\Http\Response;

/**
 * PWA のマニフェスト。アプリ名は設定から読むため、静的ファイルではなく
 * ここで生成する。ログイン前でも読めるが、含まれるのはアプリ名だけ。
 */
final class PwaController extends Controller
{
    public function manifest(Request $request): Response
    {
        $config = $this->context->config;
        $base = $config->string('app.base_path', '');
        $name = $config->string('app.name', 'DropTime');

        $manifest = [
            'name' => $name,
            'short_name' => $name,
            'start_url' => ($base ?: '') . '/',
            'scope' => ($base ?: '') . '/',
            'display' => 'standalone',
            'background_color' => '#0d1b24',
            'theme_color' => $config->string('ui.brand.primary', '#0b4f6c'),
            'icons' => [
                ['src' => $base . '/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $base . '/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ];

        $body = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return (new Response($body, 200, ['Content-Type' => 'application/manifest+json; charset=UTF-8']));
    }
}
