<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Http\Controller;
use DropTime\Http\ProfileSession;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Push\PushRepository;
use DropTime\Push\WebPush;
use DropTime\Repository\FavoriteRepository;
use DropTime\Support\Lang;
use Throwable;

/**
 * 携帯への通知（Web Push）の購読の登録・解除・試し送り。public/assets/app.js から呼ぶ。
 * 購読はこの端末のお気に入りの入れ物に結び付ける（お気に入りが変われば通知の対象も変わる）。
 */
final class PushController extends Controller
{
    public function subscribe(Request $request): Response
    {
        $endpoint = $request->postString('endpoint');
        $p256dh = $request->postString('p256dh');
        $auth = $request->postString('auth');

        if (!$this->validEndpoint($endpoint) || !$this->validKey($p256dh, 65) || !$this->validKey($auth, 16)) {
            return $this->json(['ok' => false, 'error' => 'invalid'], 400);
        }

        $profileId = ProfileSession::ensure(new FavoriteRepository($this->context->db));
        (new PushRepository($this->context->db))->save($profileId, $endpoint, $p256dh, $auth, Lang::current());

        return $this->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): Response
    {
        $endpoint = $request->postString('endpoint');

        if ($endpoint !== '') {
            $pushes = new PushRepository($this->context->db);
            $row = $pushes->findByEndpoint($endpoint);

            // 他の入れ物の購読は消させない。
            if ($row !== null && (int) $row['profile_id'] === ProfileSession::currentId()) {
                $pushes->delete((int) $row['id']);
            }
        }

        return $this->json(['ok' => true]);
    }

    /** この端末に試しの通知を 1 件送る。 */
    public function test(Request $request): Response
    {
        $row = (new PushRepository($this->context->db))->findByEndpoint($request->postString('endpoint'));

        if ($row === null || (int) $row['profile_id'] !== ProfileSession::currentId()) {
            return $this->json(['ok' => false, 'error' => 'not_subscribed'], 404);
        }

        $pem = $this->context->config->string('push.vapid_private', '');

        if ($pem === '') {
            return $this->json(['ok' => false, 'error' => 'not_configured'], 503);
        }

        try {
            $push = new WebPush($pem, $this->context->config->string('push.subject', 'https://droptime.takumi.ca/'));
            $status = $push->send(
                ['endpoint' => (string) $row['endpoint'], 'p256dh' => (string) $row['p256dh'], 'auth' => (string) $row['auth']],
                (string) json_encode([
                    'title' => Lang::t('push.test_title'),
                    'body' => Lang::t('push.test_body'),
                    'url' => $this->context->config->string('app.base_path', '') . '/',
                    'tag' => 'test',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                120
            );
        } catch (Throwable) {
            return $this->json(['ok' => false, 'error' => 'send_failed'], 502);
        }

        return $this->json(['ok' => $status >= 200 && $status < 300, 'status' => $status]);
    }

    /** ブラウザの会社の中継サーバーだけを許す（任意の URL へ送らせないため）。 */
    private function validEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > 1024 || !str_starts_with($endpoint, 'https://')) {
            return false;
        }

        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        foreach (['.googleapis.com', '.push.apple.com', '.mozilla.com', '.mozaws.net', '.notify.windows.com'] as $suffix) {
            if (str_ends_with('.' . $host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function validKey(string $value, int $bytes): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1) {
            return false;
        }

        try {
            return strlen(WebPush::b64uDecode(rtrim($value, '='))) === $bytes;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): Response
    {
        return new Response((string) json_encode($data), $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
