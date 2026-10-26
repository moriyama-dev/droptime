<?php

declare(strict_types=1);

namespace DropTime\Http;

use RuntimeException;

/**
 * 画面に返す HTTP エラー。詳細メッセージは本番では表示せずログにだけ残す。
 */
final class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        string $message = '',
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    public static function tooManyRequests(string $message = ''): self
    {
        return new self(429, $message);
    }
}
