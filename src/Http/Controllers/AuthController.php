<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Http\Auth;
use DropTime\Http\Controller;
use DropTime\Http\HttpException;
use DropTime\Http\LoginThrottle;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Support\Lang;

/**
 * ログイン。クラブ共通の合言葉ひとつだけで、ユーザー名は無い。
 * 管理画面だけは別の合言葉をもう一段要求する（最初の欄に管理用の合言葉を入れた場合は、そのまま管理画面へ通す）。
 */
final class AuthController extends Controller
{
    public function form(Request $request): Response
    {
        if (Auth::isParent()) {
            return $this->redirectToRoute('home');
        }

        return $this->view('auth/login', [
            'error' => null,
            'next' => self::safeNext($request->queryString('next')),
        ]);
    }

    public function submit(Request $request): Response
    {
        $throttle = $this->throttle();
        $ip = $request->ipBinary();
        $next = self::safeNext($request->postString('next'));

        $locked = $throttle->lockedSeconds($ip, LoginThrottle::SCOPE_PARENT);

        if ($locked > 0) {
            return $this->view('auth/login', [
                'error' => $this->lockMessage($locked),
                'next' => $next,
            ], 429);
        }

        $config = $this->context->config;
        $passphrase = $request->postString('passphrase');

        // 管理用の合言葉をここに入れた人は、2 段目を飛ばして管理画面へ通す。
        $adminHash = $config->string('auth.admin_password_hash');
        $isAdmin = $adminHash !== '' && Auth::verifyPassphrase($adminHash, $passphrase);

        if (!$isAdmin && !Auth::verifyPassphrase($config->string('auth.password_hash'), $passphrase)) {
            $throttle->recordFailure($ip, LoginThrottle::SCOPE_PARENT);

            return $this->view('auth/login', [
                'error' => Lang::t('auth.wrong'),
                'next' => $next,
            ], 401);
        }

        $throttle->recordSuccess($ip, LoginThrottle::SCOPE_PARENT);

        // 固定化攻撃を避けるため、権限を与える前に ID を作り直す。
        session_regenerate_id(true);
        Auth::grantParent();

        if ($isAdmin) {
            Auth::grantAdmin();

            return $this->redirectToRoute('admin.home');
        }

        if ($next !== '') {
            return Response::redirect($this->context->config->string('app.base_path', '') . $next);
        }

        return $this->redirectToRoute('home');
    }

    /**
     * 管理画面用の 2 段目。クラブの合言葉を通過した人だけがここに来る。
     */
    public function adminForm(Request $request): Response
    {
        $this->ensureAdminEnabled();

        if (Auth::isAdmin()) {
            return $this->redirectToRoute('admin.home');
        }

        return $this->view('auth/admin-login', ['error' => null]);
    }

    public function adminSubmit(Request $request): Response
    {
        $this->ensureAdminEnabled();

        $throttle = $this->throttle();
        $ip = $request->ipBinary();

        $locked = $throttle->lockedSeconds($ip, LoginThrottle::SCOPE_ADMIN);

        if ($locked > 0) {
            return $this->view('auth/admin-login', ['error' => $this->lockMessage($locked)], 429);
        }

        $expected = $this->context->config->string('auth.admin_password_hash');

        if (!Auth::verifyPassphrase($expected, $request->postString('passphrase'))) {
            $throttle->recordFailure($ip, LoginThrottle::SCOPE_ADMIN);

            return $this->view('auth/admin-login', ['error' => Lang::t('auth.wrong')], 401);
        }

        $throttle->recordSuccess($ip, LoginThrottle::SCOPE_ADMIN);

        session_regenerate_id(true);
        Auth::grantAdmin();

        return $this->redirectToRoute('admin.home');
    }

    public function logout(Request $request): Response
    {
        Auth::forget();

        $_SESSION = [];

        // セッション Cookie も明示的に消す。
        $params = session_get_cookie_params();
        setcookie(session_name() ?: 'dt_session', '', [
            'expires' => 1,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_destroy();

        return $this->redirectToRoute('login');
    }

    /**
     * ログイン後の戻り先。オープンリダイレクトを防ぐため、
     * 自サイト内の絶対パスだけを通す。
     */
    public static function safeNext(string $next): string
    {
        if ($next === '' || !str_starts_with($next, '/')) {
            return '';
        }

        // '//example.com' と '/\example.com' はどちらも外部への遷移になる。
        if (str_starts_with($next, '//') || str_starts_with($next, '/\\')) {
            return '';
        }

        // 制御文字が混ざるものは弾く。
        if (preg_match('/[\x00-\x1f\x7f]/', $next) === 1) {
            return '';
        }

        return $next;
    }

    private function ensureAdminEnabled(): void
    {
        if ($this->context->config->string('auth.admin_password_hash') === '') {
            // 合言葉が未設定なら、管理画面は存在しないものとして扱う。
            throw HttpException::notFound('管理画面は無効です。');
        }
    }

    private function throttle(): LoginThrottle
    {
        $config = $this->context->config;

        return new LoginThrottle(
            $this->context->db,
            $config->int('auth.lockout.max_attempts', 10),
            $config->int('auth.lockout.window_minutes', 15),
            $config->int('auth.lockout.lock_minutes', 15),
        );
    }

    private function lockMessage(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return Lang::t('auth.locked', max(1, $minutes));
    }
}
