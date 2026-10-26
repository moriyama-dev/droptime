<?php

declare(strict_types=1);

namespace DropTime\Fetch;

use DropTime\Support\Config;

/**
 * 「最新の記録を取得」の実行状況。管理画面（起動と表示）と bin/fetch-latest.php（実行）が共有する。
 *
 * 取得は数分かかるので、Web のリクエストの中では動かさず、別プロセスとして
 * 裏で走らせる。二重に走らないよう、ロックファイルに flock を掛けている間を
 * 「実行中」とみなす（プロセスが落ちればロックは OS が外すので、状態が固まらない）。
 *
 *   var/fetch/run.lock     実行中の印
 *   var/fetch/last.log     直近の実行のログ
 *   var/fetch/status.json  直近の実行の結果（開始・終了・取り込んだ大会）
 *   var/fetch/state.json   どの大会をどこまで見たか（LatestRecordsFetcher が使う）
 */
final class FetchJob
{
    /** @var resource|null */
    private $lock = null;

    public function __construct(private readonly string $dir)
    {
    }

    public static function fromConfig(): self
    {
        return new self(Config::instance()->string('paths.fetch', APP_ROOT . '/var/fetch'));
    }

    public function statePath(): string
    {
        return $this->dir . '/state.json';
    }

    public function logPath(): string
    {
        return $this->dir . '/last.log';
    }

    /** 実行を始める側（CLI）がロックを取る。取れなければ別の実行が走っている。 */
    public function acquire(): bool
    {
        @mkdir($this->dir, 0775, true);
        $handle = fopen($this->dir . '/run.lock', 'c');

        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }

        $this->lock = $handle;

        return true;
    }

    public function isRunning(): bool
    {
        if ($this->lock !== null) {
            return true;
        }

        $path = $this->dir . '/run.lock';

        if (!is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'c');

        if ($handle === false) {
            return false;
        }

        $free = flock($handle, LOCK_EX | LOCK_NB);

        if ($free) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return !$free;
    }

    /** @param array<string, mixed> $status */
    public function writeStatus(array $status): void
    {
        @mkdir($this->dir, 0775, true);
        $temporary = $this->dir . '/status.json.tmp';
        file_put_contents($temporary, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($temporary, $this->dir . '/status.json');
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        $path = $this->dir . '/status.json';

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    public function logTail(int $lines = 40): array
    {
        if (!is_file($this->logPath())) {
            return [];
        }

        $all = file($this->logPath(), FILE_IGNORE_NEW_LINES) ?: [];

        return array_slice($all, -$lines);
    }

    /**
     * bin/fetch-latest.php を裏で起動する。Web のリクエストはすぐに返る。
     * 起動できた（ように見える）ら true。実際に走ったかは isRunning() と status() で見る。
     */
    public function startInBackground(): bool
    {
        if ($this->isRunning() || !function_exists('exec')) {
            return false;
        }

        @mkdir($this->dir, 0775, true);
        $php = Config::instance()->string('fetch.php_binary', PHP_BINDIR . '/php');
        $script = APP_ROOT . '/bin/fetch-latest.php';

        // Web サーバー経由では HOME が無いことがある。ヘッドレス Chrome の既定の場所が
        // HOME から決まるので、実行ユーザーのホームを渡しておく。
        $home = getenv('HOME') ?: '';

        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }

        $command = sprintf(
            'cd %s && HOME=%s nohup %s %s > %s 2>&1 < /dev/null &',
            escapeshellarg(APP_ROOT),
            escapeshellarg($home !== '' ? $home : '/tmp'),
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($this->logPath())
        );

        exec($command, $output, $code);

        return $code === 0;
    }
}
