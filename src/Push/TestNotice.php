<?php

declare(strict_types=1);

namespace DropTime\Push;

use DropTime\Support\Config;
use DropTime\Support\Lang;

/**
 * 管理者のテスト用の通知。本物の知らせと同じ形の文面を、見本の内容で作って送る。
 *
 * 本物と取り違えないよう、題名の頭に「【テスト】」を付け、選手名・種目は見本にする。
 * 「数秒後・数分後に送る」は、アプリを閉じる・画面を消す時間を作って、
 * 閉じた状態でも届くかを確かめるためのもの（bin/push-test.php が待ってから送る）。
 */
final class TestNotice
{
    /** 種類。画面の選択肢の並びもこの順。 */
    public const KINDS = ['test', 'day', 'race30', 'race10'];

    /** 何秒後に送るか（画面の選択肢）。 */
    public const DELAYS = [0, 10, 30, 60, 300];

    /**
     * 通知の中身（JSON）。Lang::init 済みの言語で作る。
     */
    public static function payload(string $kind, string $basePath = ''): string
    {
        $name = Lang::t('tools.sample_swimmer');
        $prefix = Lang::t('tools.push_prefix');

        [$title, $body] = match ($kind) {
            'day' => [
                Lang::t('push.day_title', $name),
                Lang::t('push.day_range', '9:15', '11:40', 3) . "\n" . Lang::t('tools.sample_meet'),
            ],
            'race30' => [
                Lang::t('push.race_title', $name, Lang::t('home.cd_minutes', 30)),
                Lang::t('tools.sample_event') . "\n" . Lang::t('home.cd_at', '9:45'),
            ],
            'race10' => [
                Lang::t('push.race_title', $name, Lang::t('home.cd_minutes', 10)),
                Lang::t('tools.sample_event') . "\n" . Lang::t('home.cd_at', '9:45'),
            ],
            default => [Lang::t('push.test_title'), Lang::t('push.test_body')],
        };

        return (string) json_encode([
            'title' => $prefix . ' ' . $title,
            'body' => $body,
            'url' => $basePath . '/',
            // 続けて試しても前の通知に上書きされないよう、毎回別の tag にする。
            'tag' => 'tools-' . $kind . '-' . bin2hex(random_bytes(3)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 1 件送る。
     *
     * @param array<string, mixed> $subscription push_subscriptions の行
     * @return int 中継サーバーの HTTP ステータス（0 は通信できなかった）
     */
    public static function send(array $subscription, string $kind): int
    {
        $config = Config::instance();
        $pem = $config->string('push.vapid_private', '');

        if ($pem === '') {
            return 0;
        }

        // 文面は受け取る端末の言語で作り、画面の言語は元に戻す。
        $screenLang = Lang::current();
        Lang::init((string) $subscription['lang']);
        $payload = self::payload(in_array($kind, self::KINDS, true) ? $kind : 'test', $config->string('app.base_path', ''));
        Lang::init($screenLang);

        return (new WebPush($pem, $config->string('push.subject', 'https://droptime.takumi.ca/')))->send(
            ['endpoint' => (string) $subscription['endpoint'], 'p256dh' => (string) $subscription['p256dh'], 'auth' => (string) $subscription['auth']],
            $payload,
            300
        );
    }

    /**
     * 待ってから送る処理（bin/push-test.php）を裏で起動する。Web のリクエストはすぐに返る。
     */
    public static function sendLater(int $subscriptionId, string $kind, int $delaySeconds): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $php = Config::instance()->string('fetch.php_binary', PHP_BINDIR . '/php');

        // Web サーバーから受け継いだ接続（3 番以降の入出力）を閉じてから起動する。
        // 開いたままだと、待っている間じゅう Web サーバーがこのリクエストの終わりを待ってしまう。
        $inner = sprintf(
            'for fd in /proc/$$/fd/*; do n=${fd##*/}; if [ "$n" -gt 2 ] 2>/dev/null; then eval "exec $n>&-" 2>/dev/null; fi; done;'
            . ' cd %s && exec nohup %s %s --subscription %d --kind %s --delay %d',
            escapeshellarg(APP_ROOT),
            escapeshellarg($php),
            escapeshellarg(APP_ROOT . '/bin/push-test.php'),
            $subscriptionId,
            escapeshellarg(in_array($kind, self::KINDS, true) ? $kind : 'test'),
            max(0, min(900, $delaySeconds))
        );
        $command = 'bash -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 < /dev/null &';

        exec($command, $output, $code);

        return $code === 0;
    }

    /** 送信の記録（var/logs/push.log）に 1 行足す。選手名は書かない。 */
    public static function log(string $line): void
    {
        @mkdir(APP_ROOT . '/var/logs', 0775, true);
        $zone = new \DateTimeZone(Config::instance()->string('app.timezone', 'America/Toronto'));
        $stamp = (new \DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s T');
        file_put_contents(APP_ROOT . '/var/logs/push.log', $stamp . '  ' . $line . "\n", FILE_APPEND);
    }

    /** 中継サーバーの会社（送り先の URL から）。Apple = iPhone・iPad・Mac の Safari、Google = Chrome・Android。 */
    public static function relayName(string $endpoint): string
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        return match (true) {
            str_ends_with($host, 'push.apple.com') => 'Apple',
            str_ends_with($host, 'googleapis.com') => 'Google',
            str_ends_with($host, 'mozilla.com'), str_ends_with($host, 'mozaws.net') => 'Mozilla',
            str_ends_with($host, 'windows.com') => 'Microsoft',
            default => $host,
        };
    }
}
