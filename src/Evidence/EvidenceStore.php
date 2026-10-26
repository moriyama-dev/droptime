<?php

declare(strict_types=1);

namespace DropTime\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * 出典の証拠保全。
 *
 * このアプリに載せる記録は、ネット上で誰でも見られた情報だけから作る。
 * それを後から示せるように、取得元のページを「ログインも Cookie も無しで開いた状態」で
 * 保存しておく。1 回の保存（キャプチャ）は 1 ディレクトリで、中身は:
 *
 *   meta.json       取得元 URL・取得日時（UTC）・HTTP 応答・各ファイルの SHA-256
 *   screenshot.png  ヘッドレス Chrome で開いた全画面のスクリーンショット
 *   raw.html        サーバーが返した HTML そのもの（Cookie 無しの curl で取得）
 *   rendered.html   ブラウザで描画した後の DOM
 *   files/          取り込みに使った原本（原本の登録のときだけ）
 *
 * 改ざんされていないことを示すため、保存のたびに ledger.jsonl へ 1 行追記する。
 * 各行は直前の行のハッシュを含む（ハッシュの鎖）。途中の 1 件を書き換えると、
 * それ以降の鎖がすべて合わなくなる。
 *
 * DB は使わない。開発環境で撮ったものをディレクトリごと本番へ運べるようにするため。
 */
final class EvidenceStore
{
    private const ID_PATTERN = '/^\d{8}-\d{6}-[a-z0-9-]{1,60}$/';
    public const USER_AGENT = 'DropTime-evidence/1.0 (+private club archive; no login, no cookies)';

    public function __construct(private readonly string $root)
    {
    }

    public static function fromConfig(): self
    {
        return new self(\DropTime\Support\Config::instance()->string('paths.evidence', APP_ROOT . '/var/evidence'));
    }

    public function root(): string
    {
        return $this->root;
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * 保存済みのキャプチャ一覧。新しい順。
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $items = [];

        foreach (scandir($this->root) ?: [] as $entry) {
            if (!self::isValidId($entry)) {
                continue;
            }

            $meta = $this->find($entry);

            if ($meta !== null) {
                $items[] = $meta;
            }
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['captured_at'], (string) $a['captured_at']));

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }

        $path = $this->root . '/' . $id . '/meta.json';

        if (!is_file($path)) {
            return null;
        }

        $meta = json_decode((string) file_get_contents($path), true);

        if (!is_array($meta)) {
            return null;
        }

        $meta['id'] = $id;

        return $meta;
    }

    /** キャプチャ内のファイルの絶対パス。存在しなければ null。 */
    public function filePath(string $id, string $name): ?string
    {
        if (!self::isValidId($id) || preg_match('#^(?:[a-z0-9_.-]+|files/[A-Za-z0-9_.-]+)$#', $name) !== 1 || str_contains($name, '..')) {
            return null;
        }

        $path = $this->root . '/' . $id . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * meta.json に記録したハッシュと、いまのファイルの中身が一致するか。
     *
     * @return array{ok: bool, files: array<string, bool>}
     */
    public function verify(string $id): array
    {
        $meta = $this->find($id);

        if ($meta === null) {
            return ['ok' => false, 'files' => []];
        }

        $files = [];

        foreach ((array) ($meta['files'] ?? []) as $name => $info) {
            $path = $this->filePath($id, (string) $name);
            $files[(string) $name] = $path !== null && hash_file('sha256', $path) === ($info['sha256'] ?? '');
        }

        return ['ok' => $files !== [] && !in_array(false, $files, true), 'files' => $files];
    }

    /**
     * 台帳（ハッシュの鎖）を頭から検証する。
     *
     * @return array{ok: bool, entries: int, broken_at: ?int, by_id: array<string, string>}
     *         by_id はキャプチャ ID => その行のハッシュ
     */
    public function verifyLedger(): array
    {
        $path = $this->root . '/ledger.jsonl';
        $byId = [];

        if (!is_file($path)) {
            return ['ok' => true, 'entries' => 0, 'broken_at' => null, 'by_id' => []];
        }

        $previous = str_repeat('0', 64);
        $lineNo = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $lineNo++;
            $entry = json_decode($line, true);

            if (!is_array($entry) || ($entry['prev'] ?? '') !== $previous) {
                return ['ok' => false, 'entries' => $lineNo, 'broken_at' => $lineNo, 'by_id' => $byId];
            }

            $metaHash = $this->metaHash((string) ($entry['id'] ?? ''));

            if ($metaHash !== ($entry['meta_sha256'] ?? null)) {
                return ['ok' => false, 'entries' => $lineNo, 'broken_at' => $lineNo, 'by_id' => $byId];
            }

            $previous = hash('sha256', $line);
            $byId[(string) $entry['id']] = $previous;
        }

        return ['ok' => true, 'entries' => $lineNo, 'broken_at' => null, 'by_id' => $byId];
    }

    /**
     * ページを撮って保存する（CLI 専用）。
     *
     * @param list<int> $meetIds
     * @return array<string, mixed> 保存した meta
     */
    public function captureUrl(string $url, HeadlessBrowser $browser, array $meetIds = [], string $note = ''): array
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new RuntimeException('http(s) の URL を指定してください。');
        }

        // 1) サーバーが返すそのままの応答を、Cookie も認証情報も持たない curl で取る。
        $raw = self::fetchRaw($url);

        // 2) 新しいプロファイルのブラウザで開いて全画面を撮る（こちらも Cookie 無し）。
        $shot = $browser->capture($url, ['width' => 1280, 'height' => 900, 'full_page' => true, 'wait_ms' => 1500]);

        $dir = $this->newDirectory($url);
        file_put_contents($dir . '/raw.html', $raw['body']);
        file_put_contents($dir . '/rendered.html', $shot['html']);
        file_put_contents($dir . '/screenshot.png', $shot['png']);
        self::makeThumbnail($dir);

        $meta = [
            'kind' => 'page',
            'url' => $url,
            'final_url' => $raw['final_url'],
            'title' => $shot['title'],
            'captured_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'http' => [
                'status' => $raw['status'],
                'headers' => $raw['headers'],
                'browser_status' => $shot['status'],
            ],
            // 取得の条件。公開状態だったことの根拠になる。
            'conditions' => [
                'cookies' => false,
                'credentials' => false,
                'login' => false,
                'user_agent' => self::USER_AGENT,
                'browser' => 'Chrome for Testing (headless, fresh profile)',
            ],
            'meet_ids' => array_values(array_map('intval', $meetIds)),
            'note' => $note,
            'files' => $this->hashFiles($dir, ['screenshot.png', 'raw.html', 'rendered.html']),
        ];

        return $this->finish($dir, $meta);
    }

    /**
     * 取り込みに使った原本ファイル一式を登録する（CLI 専用）。
     * 原本はすでに手元にあるので撮影はせず、ファイルを写してハッシュを記録する。
     *
     * @param list<int> $meetIds
     * @return array<string, mixed>
     */
    public function registerOriginals(string $sourceDir, string $sourceUrl, array $meetIds = [], string $note = ''): array
    {
        if (!is_dir($sourceDir)) {
            throw new RuntimeException("ディレクトリがありません: {$sourceDir}");
        }

        $names = [];

        foreach (scandir($sourceDir) ?: [] as $entry) {
            if (preg_match('/^[A-Za-z0-9_.-]+\.html?$/', $entry) === 1 && is_file($sourceDir . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        if ($names === []) {
            throw new RuntimeException("HTML ファイルがありません: {$sourceDir}");
        }

        sort($names);
        $dir = $this->newDirectory($sourceUrl . '-originals');
        mkdir($dir . '/files', 0775);
        $oldest = PHP_INT_MAX;

        foreach ($names as $name) {
            copy($sourceDir . '/' . $name, $dir . '/files/' . $name);
            $oldest = min($oldest, (int) filemtime($sourceDir . '/' . $name));
        }

        $meta = [
            'kind' => 'originals',
            'url' => $sourceUrl,
            'final_url' => $sourceUrl,
            'title' => basename($sourceDir),
            'captured_at' => gmdate('Y-m-d\TH:i:s\Z'),
            // 原本を手元に保存した時刻（ファイルの更新時刻のうち最も古いもの）。
            'saved_at' => gmdate('Y-m-d\TH:i:s\Z', $oldest),
            'http' => null,
            'conditions' => ['cookies' => false, 'credentials' => false, 'login' => false],
            'meet_ids' => array_values(array_map('intval', $meetIds)),
            'note' => $note,
            'files' => $this->hashFiles($dir, array_map(static fn (string $n): string => 'files/' . $n, $names)),
        ];

        return $this->finish($dir, $meta);
    }

    /**
     * 一覧用の小さな画像（thumb.png）を作る。スクリーンショットは 1 枚 100 KB〜1 MB あり、
     * 一覧で 20 枚以上並ぶとスマホの回線では重いため。元の画像から作り直せる
     * 派生物なので、ハッシュの対象（meta.json の files）には入れない。
     */
    public static function makeThumbnail(string $dir): bool
    {
        $source = $dir . '/screenshot.png';

        if (!is_file($source) || !function_exists('imagecreatefrompng')) {
            return false;
        }

        $image = @imagecreatefrompng($source);

        if ($image === false) {
            return false;
        }

        // 左上の 640x480 を 160x120 に縮める（結果ページは左上に表題と上位が来る）。
        $thumb = imagecreatetruecolor(160, 120);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, 160, 120, min(640, imagesx($image)), min(480, imagesy($image)));
        $ok = imagepng($thumb, $dir . '/thumb.png', 9);
        imagedestroy($thumb);
        imagedestroy($image);

        return $ok;
    }

    /** UTC の ISO 文字列を、アプリのタイムゾーン（トロント）の表記にする。 */
    public static function localTime(?string $utc, string $timezone = 'America/Toronto'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        $date = new DateTimeImmutable($utc);

        return $date->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i T');
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string, final_url: string}
     */
    public static function fetchRaw(string $url): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_COOKIEFILE => '',          // Cookie を持たない（受け取っても送り返さない）
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = ['_status_line' => trim($line)]; // リダイレクト後の最終応答だけ残す
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("取得に失敗しました: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return ['status' => $status, 'headers' => $headers, 'body' => (string) $body, 'final_url' => $finalUrl];
    }

    private function newDirectory(string $url): string
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0775, true) && !is_dir($this->root)) {
            throw new RuntimeException("保存先を作れません: {$this->root}");
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?? 'source');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $host . '-' . $path), '-'));
        $slug = substr($slug, 0, 60) ?: 'source';
        $base = gmdate('Ymd-His') . '-' . rtrim($slug, '-');
        $dir = $this->root . '/' . $base;
        $n = 2;

        while (is_dir($dir)) {
            $dir = $this->root . '/' . substr($base, 0, 56) . '-' . $n++;
        }

        mkdir($dir, 0775);

        return $dir;
    }

    /**
     * @param list<string> $names
     * @return array<string, array{sha256: string, bytes: int}>
     */
    private function hashFiles(string $dir, array $names): array
    {
        $files = [];

        foreach ($names as $name) {
            $files[$name] = ['sha256' => (string) hash_file('sha256', $dir . '/' . $name), 'bytes' => (int) filesize($dir . '/' . $name)];
        }

        return $files;
    }

    /**
     * meta.json を書き、台帳に 1 行追記する。
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function finish(string $dir, array $meta): array
    {
        $id = basename($dir);
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        $ledger = $this->root . '/ledger.jsonl';
        $previous = str_repeat('0', 64);

        if (is_file($ledger)) {
            $lines = file($ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            if ($lines !== []) {
                $previous = hash('sha256', (string) end($lines));
            }
        }

        $line = json_encode([
            'id' => $id,
            'url' => $meta['url'],
            'captured_at' => $meta['captured_at'],
            'meta_sha256' => $this->metaHash($id),
            'prev' => $previous,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($ledger, $line . "\n", FILE_APPEND | LOCK_EX);

        $meta['id'] = $id;

        return $meta;
    }

    private function metaHash(string $id): ?string
    {
        if (!self::isValidId($id)) {
            return null;
        }

        $path = $this->root . '/' . $id . '/meta.json';

        return is_file($path) ? (string) hash_file('sha256', $path) : null;
    }
}
