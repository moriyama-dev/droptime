<?php

declare(strict_types=1);

namespace DropTime\Import;

use DropTime\Support\Clock;
use DropTime\Support\Config;

/**
 * アップロードされた元ファイルの置き場。
 *
 * 元ファイルはパーサーを直したときに再処理するため保存し続ける。
 * 保存先（var/uploads）はドキュメントルートの外で、Web から読めない。
 * DB には uploads ディレクトリからの相対パスだけを記録する。
 */
final class ImportStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public static function fromConfig(): self
    {
        return new self(Config::instance()->string('paths.uploads', APP_ROOT . '/var/uploads'));
    }

    /**
     * 元ファイルを保存し、uploads からの相対パスを返す。
     *
     * ファイル名は「日時 + 内容ハッシュの先頭」。元のファイル名は DB に持ち、
     * パスには使わない（ディレクトリトラバーサルと文字化けの温床になるため）。
     */
    public function storeUpload(UploadedFile $file): string
    {
        $relative = sprintf(
            '%s_%s.%s',
            Clock::now()->format('Ymd-His'),
            substr($file->sha256, 0, 12),
            $file->extension()
        );

        $destination = $this->absolute($relative);

        if (!is_dir(dirname($destination))) {
            throw new ImportException('保存先ディレクトリがありません: ' . dirname($destination));
        }

        // Web のアップロードなら move_uploaded_file、CLI からの再処理なら copy。
        $moved = is_uploaded_file($file->temporaryPath)
            ? move_uploaded_file($file->temporaryPath, $destination)
            : copy($file->temporaryPath, $destination);

        if (!$moved) {
            throw new ImportException('ファイルを保存できませんでした。');
        }

        return $relative;
    }

    /** テキスト化した結果の保存先。元ファイルの隣に .txt で置く。 */
    public function textPathFor(string $rawRelative): string
    {
        return $rawRelative . '.txt';
    }

    public function absolute(string $relative): string
    {
        // DB 由来の値でも、区切り以外の要素は basename 相当に限定して結合する。
        $parts = [];

        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new ImportException('不正なパスです。');
            }

            $parts[] = $part;
        }

        return $this->directory . '/' . implode('/', $parts);
    }

    public function writeText(string $textRelative, string $text): void
    {
        if (file_put_contents($this->absolute($textRelative), $text) === false) {
            throw new ImportException('テキストを保存できませんでした。');
        }
    }

    public function readText(string $textRelative): string
    {
        $content = file_get_contents($this->absolute($textRelative));

        if ($content === false) {
            throw new ImportException('保存済みのテキストを読めませんでした。');
        }

        return $content;
    }
}
