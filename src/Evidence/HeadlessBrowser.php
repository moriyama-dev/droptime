<?php

declare(strict_types=1);

namespace DropTime\Evidence;

use RuntimeException;

/**
 * ヘッドレス Chrome を DevTools プロトコル（CDP）で操作する最小限のクライアント。
 *
 * 依存ライブラリを持たない方針なので、WebSocket も自前で話す。
 * 用途は 2 つだけ:
 *   - 出典ページの証拠保全（ログインなしで開いた状態を全画面で撮る）
 *   - 開発中の画面確認（Cookie を渡してログイン後の画面を撮る）
 *
 * 本番の共有ホスティングでは Chrome が動かないので、CLI からのみ使う。
 */
final class HeadlessBrowser
{
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $socket = null;
    private string $profileDir;
    private int $nextId = 1;
    /** @var list<array<string,mixed>> 受信済みで未処理のイベント */
    private array $events = [];

    public function __construct(private readonly string $chromePath)
    {
        if (!is_file($chromePath) || !is_executable($chromePath)) {
            throw new RuntimeException("Chrome が見つかりません: {$chromePath}");
        }
        $this->profileDir = sys_get_temp_dir() . '/droptime-chrome-' . bin2hex(random_bytes(6));
    }

    public static function defaultPath(): string
    {
        $env = getenv('DROPTIME_CHROME');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return (getenv('HOME') ?: '/tmp') . '/.local/share/headless-chrome/chrome';
    }

    /**
     * ページを開いて撮影する。
     *
     * @param array{width?:int,height?:int,full_page?:bool,cookies?:list<array{name:string,value:string}>,wait_ms?:int,lang?:string} $options
     * @return array{png:string,html:string,status:int|null,headers:array<string,string>,final_url:string,title:string}
     */
    public function capture(string $url, array $options = []): array
    {
        $width = $options['width'] ?? 1280;
        $height = $options['height'] ?? 900;
        $this->start();

        $target = $this->call('Target.createTarget', ['url' => 'about:blank']);
        $attached = $this->call('Target.attachToTarget', ['targetId' => $target['targetId'], 'flatten' => true]);
        $session = (string) $attached['sessionId'];

        $this->call('Page.enable', [], $session);
        $this->call('Network.enable', [], $session);
        $this->call('Emulation.setDeviceMetricsOverride', [
            'width' => $width, 'height' => $height, 'deviceScaleFactor' => 1, 'mobile' => $width < 600,
        ], $session);
        if (isset($options['lang'])) {
            $this->call('Network.setExtraHTTPHeaders', ['headers' => ['Accept-Language' => $options['lang']]], $session);
        }
        foreach ($options['cookies'] ?? [] as $cookie) {
            $this->call('Network.setCookie', ['name' => $cookie['name'], 'value' => $cookie['value'], 'url' => $url], $session);
        }

        $this->events = [];
        $this->call('Page.navigate', ['url' => $url], $session);
        $this->waitForEvent('Page.loadEventFired', $session, 30.0);
        usleep(($options['wait_ms'] ?? 800) * 1000);

        // 最初に返ってきた Document のレスポンス（リダイレクト後の最終応答）を記録する。
        $status = null;
        $headers = [];
        foreach ($this->events as $event) {
            if (($event['method'] ?? '') === 'Network.responseReceived'
                && ($event['params']['type'] ?? '') === 'Document') {
                $status = (int) $event['params']['response']['status'];
                $headers = array_map('strval', (array) $event['params']['response']['headers']);
            }
        }

        $eval = fn (string $expr): mixed => $this->call('Runtime.evaluate', ['expression' => $expr, 'returnByValue' => true], $session)['result']['value'] ?? null;

        // 開発用（bin/dev-screenshot.php --js）: 撮る前に画面を操作する。証拠の保存では使わない。
        if (isset($options['script'])) {
            $eval((string) $options['script']);
            usleep(($options['wait_ms'] ?? 800) * 1000);
        }

        $html = (string) $eval('document.documentElement.outerHTML');
        $title = (string) $eval('document.title');
        $finalUrl = (string) $eval('location.href');

        $params = ['format' => 'png'];
        if ($options['full_page'] ?? true) {
            $metrics = $this->call('Page.getLayoutMetrics', [], $session);
            $contentHeight = (int) ceil($metrics['cssContentSize']['height'] ?? $height);
            // 極端に長いページは途中で切る（PNG が数十 MB になるのを防ぐ）。
            $contentHeight = max($height, min($contentHeight, 16000));
            $params['clip'] = ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $contentHeight, 'scale' => 1];
            $params['captureBeyondViewport'] = true;
        }
        $shot = $this->call('Page.captureScreenshot', $params, $session);
        $png = base64_decode((string) $shot['data'], true);
        if ($png === false) {
            throw new RuntimeException('スクリーンショットの復号に失敗しました。');
        }

        $this->call('Target.closeTarget', ['targetId' => $target['targetId']]);

        return [
            'png' => $png,
            'html' => $html,
            'status' => $status,
            'headers' => $headers,
            'final_url' => $finalUrl,
            'title' => $title,
        ];
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        if ($this->process !== null) {
            @proc_terminate($this->process);
            @proc_close($this->process);
            $this->process = null;
        }
        if (is_dir($this->profileDir)) {
            exec('rm -rf ' . escapeshellarg($this->profileDir));
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function start(): void
    {
        if ($this->socket !== null) {
            return;
        }
        $cmd = [
            $this->chromePath,
            '--remote-debugging-port=0',
            '--user-data-dir=' . $this->profileDir,
            '--no-first-run',
            '--disable-extensions',
            'about:blank',
        ];
        $pipes = [];
        $this->process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Chrome を起動できませんでした。');
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + 20;
        $wsUrl = null;
        $buffer = '';
        while ($wsUrl === null && microtime(true) < $deadline) {
            $line = fgets($pipes[2]);
            if ($line === false) {
                usleep(50_000);
                continue;
            }
            $buffer .= $line;
            if (preg_match('#DevTools listening on (ws://\S+)#', $line, $m)) {
                $wsUrl = $m[1];
            }
        }
        if ($wsUrl === null) {
            throw new RuntimeException("Chrome の DevTools に接続できませんでした。\n" . $buffer);
        }
        // 以降の stderr は読まないが、パイプが詰まって Chrome が止まらないよう非ブロックにしておく。
        stream_set_blocking($pipes[2], false);

        $parts = parse_url($wsUrl);
        $socket = stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $errno, $errstr, 10);
        if ($socket === false) {
            throw new RuntimeException("DevTools への接続に失敗: {$errstr}");
        }
        $key = base64_encode(random_bytes(16));
        fwrite($socket, "GET {$parts['path']} HTTP/1.1\r\nHost: {$parts['host']}:{$parts['port']}\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($socket, 1);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('WebSocket のハンドシェイクに失敗しました。');
            }
            $response .= $chunk;
        }
        if (!str_starts_with($response, 'HTTP/1.1 101')) {
            throw new RuntimeException('WebSocket のハンドシェイクが拒否されました。');
        }
        stream_set_timeout($socket, 60);
        $this->socket = $socket;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function call(string $method, array $params = [], ?string $session = null): array
    {
        $id = $this->nextId++;
        $message = ['id' => $id, 'method' => $method, 'params' => (object) $params];
        if ($session !== null) {
            $message['sessionId'] = $session;
        }
        $this->send(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        while (true) {
            $data = json_decode($this->receive(), true, 512, JSON_THROW_ON_ERROR);
            if (($data['id'] ?? null) === $id) {
                if (isset($data['error'])) {
                    throw new RuntimeException("{$method}: " . ($data['error']['message'] ?? 'error'));
                }
                return (array) ($data['result'] ?? []);
            }
            if (isset($data['method'])) {
                $this->events[] = $data;
            }
        }
    }

    private function waitForEvent(string $method, string $session, float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        foreach ($this->events as $event) {
            if (($event['method'] ?? '') === $method && ($event['sessionId'] ?? '') === $session) {
                return;
            }
        }
        while (microtime(true) < $deadline) {
            $data = json_decode($this->receive(), true, 512, JSON_THROW_ON_ERROR);
            if (isset($data['method'])) {
                $this->events[] = $data;
                if ($data['method'] === $method && ($data['sessionId'] ?? '') === $session) {
                    return;
                }
            }
        }
        throw new RuntimeException("{$method} を待つ間にタイムアウトしました。");
    }

    private function send(string $payload): void
    {
        $length = strlen($payload);
        $header = chr(0x81);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $length);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        $this->write($header . $mask . $masked);
    }

    private function receive(): string
    {
        $message = '';
        do {
            $head = $this->read(2);
            $fin = (ord($head[0]) & 0x80) !== 0;
            $opcode = ord($head[0]) & 0x0f;
            $length = ord($head[1]) & 0x7f;
            if ($length === 126) {
                $length = unpack('n', $this->read(2))[1];
            } elseif ($length === 127) {
                $length = unpack('J', $this->read(8))[1];
            }
            $payload = $length > 0 ? $this->read($length) : '';
            if ($opcode === 0x8) {
                throw new RuntimeException('DevTools の接続が閉じられました。');
            }
            if ($opcode === 0x9) { // ping には pong を返す
                $this->write(chr(0x8a) . chr(0x80) . "\0\0\0\0");
                continue;
            }
            $message .= $payload;
        } while (!$fin || $message === '');
        return $message;
    }

    private function read(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException($meta['timed_out'] ? 'DevTools の応答がタイムアウトしました。' : 'DevTools の接続が切れました。');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function write(string $data): void
    {
        $written = 0;
        while ($written < strlen($data)) {
            $n = fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('DevTools への送信に失敗しました。');
            }
            $written += $n;
        }
    }
}
