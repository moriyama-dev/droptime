<?php

declare(strict_types=1);

namespace DropTime\Fetch;

use RuntimeException;

/**
 * 速報サイトへの HTTP。誰でも見られる公開ページを、ログインも Cookie も無しで読むだけ。
 *
 * 相手は小さな速報置き場なので、1 リクエストごとに間を空ける（既定 0.3 秒）。
 */
final class PortalClient
{
    private float $lastRequestAt = 0.0;
    private int $requests = 0;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $delayMs = 300,
    ) {
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/';
    }

    public function folderUrl(string $folder): string
    {
        return $this->baseUrl() . $folder . '/';
    }

    public function requests(): int
    {
        return $this->requests;
    }

    /** @return array{status: int, body: string, last_modified: ?string} */
    public function get(string $url): array
    {
        return $this->request($url, false);
    }

    /** 本文を取らずに更新日時だけ見る（大会中に変わったかの目印）。 */
    public function lastModified(string $url): ?string
    {
        return $this->request($url, true)['last_modified'];
    }

    /** @return array{status: int, body: string, last_modified: ?string} */
    private function request(string $url, bool $headOnly): array
    {
        $wait = $this->lastRequestAt + $this->delayMs / 1000 - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        $lastModified = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => $headOnly,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT => 'DropTime/1.0 (private swim-club parents app; reads public meet results)',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$lastModified): int {
                if (stripos($line, 'Last-Modified:') === 0) {
                    $lastModified = trim(substr($line, 14));
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $this->lastRequestAt = microtime(true);
        $this->requests++;

        if ($body === false) {
            throw new RuntimeException("取得に失敗しました: {$url}（{$error}）");
        }

        return ['status' => $status, 'body' => (string) $body, 'last_modified' => $lastModified];
    }
}
