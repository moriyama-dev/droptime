<?php

declare(strict_types=1);

namespace DropTime\Import;

/**
 * 検証を通ったアップロードファイル。
 */
final class UploadedFile
{
    public const KIND_PDF = 'pdf';
    public const KIND_HTML = 'html';

    public function __construct(
        public readonly string $temporaryPath,
        public readonly string $originalName,
        public readonly int $size,
        public readonly string $kind,
        public readonly string $mime,
        public readonly string $sha256,
    ) {
    }

    public function extension(): string
    {
        return $this->kind === self::KIND_PDF ? 'pdf' : 'html';
    }
}
