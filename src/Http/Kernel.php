<?php

declare(strict_types=1);

namespace DropTime\Http;

use DropTime\Db\Database;
use DropTime\Db\DbSessionHandler;
use DropTime\Repository\FavoriteRepository;
use DropTime\Support\Clock;
use DropTime\Support\Config;
use DropTime\Support\Lang;
use DropTime\Support\Logger;
use DropTime\Support\View;
use ErrorException;
use Throwable;

/**
 * 唯一のエントリポイント。
 *
 * 認証チェックと CSRF 検証はここでしか行わない。コントローラ側には書かない。
 * 書き忘れを防ぐため、ルート定義でアクセス区分の指定を必須にしている。
 */
final class Kernel
{
    private ?Config $config = null;

    private string $styleNonce = '';

    public function run(): void
    {
        $this->styleNonce = base64_encode(random_bytes(16));

        try {
            $this->boot();
            $response = $this->handle();
        } catch (Throwable $e) {
            $response = $this->handleFailure($e);
        }

        $response->send();
    }

    private function boot(): void
    {
        $configPath = APP_ROOT . '/config/config.php';

        if (!is_file($configPath)) {
            $this->sendSetupNotice();

            exit;
        }

        $this->config = Config::load($configPath);

        date_default_timezone_set($this->config->string('app.timezone', 'America/Toronto'));

        ini_set('display_errors', $this->config->isDev() ? '1' : '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        $this->sendSecurityHeaders();
        $this->startSession();
    }

    private function handle(): Response
    {
        $config = $this->config;

        if ($config === null) {
            throw new HttpException(500, '設定が読み込まれていません。');
        }

        $request = Request::fromGlobals($config->string('app.base_path', ''));

        // 表示言語。?lang= での明示的な切り替えは保存してリダイレクトで戻す。
        $langResponse = $this->resolveLanguage($config, $request);

        if ($langResponse !== null) {
            return $langResponse;
        }

        /** @var list<array{0: string, 1: string, 2: array{0: string, 1: string}, 3: Access, 4: string}> $definitions */
        $definitions = require APP_ROOT . '/src/Http/routes.php';
        $router = Router::fromDefinitions($definitions);

        $matched = $router->match($request->method, $request->path);

        if ($matched === null) {
            throw HttpException::notFound($request->path);
        }

        [$route, $params] = $matched;
        $request->params = $params;

        // 認証チェック。ここを通らずにコントローラへ到達する経路は存在しない。
        if (!Auth::allows($route->access)) {
            // まずクラブの合言葉。通っていれば管理画面用の 2 段目へ送る。
            if (!Auth::isParent()) {
                return $this->redirectToLogin($config, $router, $request);
            }

            return Response::redirect($config->string('app.base_path', '') . $router->url('admin.login'));
        }

        // 状態を変える操作はすべて CSRF トークンを検証する。除外リストは作らない。
        if ($request->method === 'POST' && !Csrf::verify($request)) {
            throw HttpException::badRequest('CSRF トークンが一致しません。');
        }

        // お気に入りの入れ物を、端末の目印からセッションへ戻す（ログアウト後・期限切れ後でも残るように）。
        if (Auth::isParent()) {
            ProfileSession::restore(new FavoriteRepository(Database::instance()));
        }

        $view = new View(APP_ROOT . '/templates', $router);
        $view->share('appName', $config->string('app.name', 'DropTime'));
        $view->share('homeUrl', $config->string('app.home_url', 'https://droptime.takumi.ca/'));
        $view->share('brand', $config->get('ui.brand', []));
        $view->share('styleNonce', $this->styleNonce);
        $view->share('isAdmin', Auth::isAdmin());
        $view->share('basePath', $config->string('app.base_path', ''));
        $view->share('currentPath', $request->path);
        $view->share('currentQuery', array_diff_key($request->query, ['lang' => true]));
        $view->share('userAgent', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        $context = new Context($config, Database::instance(), $router, $view);

        $controllerClass = $route->controller;
        $action = $route->action;

        /** @var Controller $controller */
        $controller = new $controllerClass($context);

        /** @var Response $response */
        $response = $controller->$action($request);

        return $response;
    }

    /**
     * 表示言語を決める。優先順は 明示的な選択 → Cookie/セッション →
     * Accept-Language → 既定（英語）。?lang= が来たときだけリダイレクトを返す。
     */
    private function resolveLanguage(Config $config, Request $request): ?Response
    {
        $choice = $request->queryString('lang');

        if (in_array($choice, Lang::SUPPORTED, true)) {
            $_SESSION['ui.lang'] = $choice;

            // セッションが切れても（ログイン前でも）選択が残るよう Cookie にも置く。
            setcookie('dt_lang', $choice, [
                'expires' => Clock::timestamp() + 365 * 86400,
                'path' => ($config->string('app.base_path', '') ?: '') . '/',
                'secure' => $config->bool('security.cookie_secure', true),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $query = array_diff_key($request->query, ['lang' => true]);
            $target = $config->string('app.base_path', '') . $request->path
                . ($query !== [] ? '?' . http_build_query($query) : '');

            return Response::redirect($target);
        }

        $stored = $_SESSION['ui.lang'] ?? ($_COOKIE['dt_lang'] ?? null);

        if (is_string($stored) && in_array($stored, Lang::SUPPORTED, true)) {
            Lang::init($stored);

            return null;
        }

        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        Lang::init(Lang::negotiate(is_string($header) ? $header : '') ?? Lang::DEFAULT);

        return null;
    }

    private function redirectToLogin(Config $config, Router $router, Request $request): Response
    {
        $target = $config->string('app.base_path', '') . $router->url('login');

        // ログイン後に戻る先。オープンリダイレクトを避けるため自サイト内のパスだけを渡す。
        if ($request->method === 'GET' && $request->path !== '/') {
            $target .= '?next=' . rawurlencode($request->path);
        }

        return Response::redirect($target);
    }

    private function startSession(): void
    {
        $config = $this->config;

        if ($config === null) {
            return;
        }

        $lifetime = $config->int('auth.session_days', 90) * 86400;

        // セッションは MySQL に保存する。ファイルセッションでは 90 日保持できない。
        $handler = new DbSessionHandler(Database::instance(), $lifetime);
        session_set_save_handler($handler, true);

        session_name('dt_session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.sid_length', '64');

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => ($config->string('app.base_path', '') ?: '') . '/',
            'secure' => $config->bool('security.cookie_secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        DeviceCookie::configure(
            ($config->string('app.base_path', '') ?: '') . '/',
            $config->bool('security.cookie_secure', true),
        );

        session_start();

        // 有効期限を毎回延長し、90 日の窓をスライドさせる。
        if (session_id() !== '') {
            setcookie(session_name() ?: 'dt_session', session_id(), [
                'expires' => Clock::timestamp() + $lifetime,
                'path' => ($config->string('app.base_path', '') ?: '') . '/',
                'secure' => $config->bool('security.cookie_secure', true),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    private function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // 外部サービスへの通信を技術的に不可能にする。うっかり CDN を書いても
        // ブラウザ側で遮断される。子供の氏名を含む画面なので必須。
        $csp = "default-src 'none'; "
            . "base-uri 'none'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'; "
            . "img-src 'self' data:; "
            . "font-src 'self'; "
            . "script-src 'self'; "
            . "connect-src 'self'; "
            . "manifest-src 'self'; "
            . "style-src 'self' 'nonce-" . $this->styleNonce . "'";

        header('Content-Security-Policy: ' . $csp);
        header('X-Robots-Tag: noindex, nofollow, noarchive, noimageindex');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Opener-Policy: same-origin');
        header_remove('X-Powered-By');
    }

    private function handleFailure(Throwable $e): Response
    {
        $status = $e instanceof HttpException ? $e->status() : 500;

        if ($status >= 500) {
            $this->logSafely($e);
        }

        $isDev = $this->config !== null && $this->config->isDev();

        $body = $this->renderErrorPage($status, $isDev ? $e : null);

        return Response::html($body, $status);
    }

    private function logSafely(Throwable $e): void
    {
        try {
            Logger::error('未処理の例外', $e);
        } catch (Throwable) {
            // ログにすら書けない状況では何もしない。画面には詳細を出さない。
        }
    }

    private function renderErrorPage(int $status, ?Throwable $e): string
    {
        $template = $status === 404 ? 'errors/404' : 'errors/500';
        $appName = $this->config?->string('app.name', 'DropTime') ?? 'DropTime';

        try {
            /** @var list<array{0: string, 1: string, 2: array{0: string, 1: string}, 3: Access, 4: string}> $definitions */
            $definitions = require APP_ROOT . '/src/Http/routes.php';
            $view = new View(APP_ROOT . '/templates', Router::fromDefinitions($definitions));
            $view->share('appName', $appName);
            $view->share('homeUrl', $this->config?->string('app.home_url', 'https://droptime.takumi.ca/') ?? 'https://droptime.takumi.ca/');
            $view->share('brand', $this->config?->get('ui.brand', []) ?? []);
            $view->share('styleNonce', $this->styleNonce);
            $view->share('basePath', $this->config?->string('app.base_path', '') ?? '');
            $view->share('currentPath', '');
            $view->share('currentQuery', []);

            return $view->render($template, [
                'status' => $status,
                'detail' => $e?->getMessage(),
                'trace' => $e?->getTraceAsString(),
            ]);
        } catch (Throwable) {
            return '<!doctype html><meta charset="utf-8"><title>' . $status . '</title><p>'
                . $status . '</p>';
        }
    }

    private function sendSetupNotice(): void
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow');
        }

        echo '<!doctype html><meta charset="utf-8"><title>Setup</title>'
            . '<h1>セットアップが未完了です</h1>'
            . '<p>config/config.example.php を config/config.php にコピーし、'
            . 'DB 接続情報と合言葉のハッシュを設定してください。</p>';
    }
}
