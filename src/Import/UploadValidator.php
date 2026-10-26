<?php

declare(strict_types=1);

namespace DropTime\Import;

/**
 * アップロードの検証。
 *
 * 拡張子は信用せず、実際のバイト列で判定する。PDF は先頭が %PDF-、
 * HTML は先頭付近に HTML らしいタグがあること。
 */
final class UploadValidator
{
    /** 受け入れる上限。memory_limit を超える読み込みはしないが、念のため頭を押さえる。 */
    private const MAX_BYTES = 128 * 1024 * 1024;

    /** 判定のために読む先頭バイト数。 */
    private const SNIFF_BYTES = 4096;

    /**
     * @param array<string, mixed> $file $_FILES の 1 要素
     */
    public function validate(array $file): UploadedFile
    {
        $error = is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE;

        if ($error !== UPLOAD_ERR_OK) {
            throw new ImportException(self::errorMessage($error));
        }

        $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new ImportException('アップロードされたファイルを読めませんでした。');
        }

        $size = is_int($file['size'] ?? null) ? $file['size'] : 0;

        if ($size <= 0) {
            throw new ImportException('ファイルが空です。');
        }

        if ($size > self::MAX_BYTES) {
            throw new ImportException('ファイルが大きすぎます。');
        }

        $original = is_string($file['name'] ?? null) ? $file['name'] : 'upload';

        return $this->inspect($tmp, basename($original), $size);
    }

    /**
     * 実ファイルの中身から種別を決める。CLI からの再処理でも使えるよう
     * $_FILES とは切り離してある。
     */
    public function inspect(string $path, string $originalName, ?int $size = null): UploadedFile
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new ImportException('ファイルを開けませんでした。');
        }

        $head = (string) fread($handle, self::SNIFF_BYTES);
        fclose($handle);

        $kind = self::sniff($head);

        if ($kind === null) {
            throw new ImportException('PDF でも HTML でもないファイルです。取り込めるのは PDF と HTML だけです。');
        }

        $mime = self::detectMime($path);
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new ImportException('ファイルのハッシュを計算できませんでした。');
        }

        return new UploadedFile(
            $path,
            $originalName,
            $size ?? (int) filesize($path),
            $kind,
            $mime,
            $hash
        );
    }

    /** 先頭バイト列から種別を判定する。拡張子は見ない。 */
    public static function sniff(string $head): ?string
    {
        if (str_starts_with($head, '%PDF-')) {
            return UploadedFile::KIND_PDF;
        }

        // BOM と先頭の空白を落としてから見る。
        $trimmed = ltrim(str_replace("\u{feff}", '', $head));
        $lower = strtolower($trimmed);

        foreach (['<!doctype html', '<html', '<pre', '<?xml', '<body', '<table'] as $needle) {
            if (str_starts_with($lower, $needle)) {
                return UploadedFile::KIND_HTML;
            }
        }

        // 先頭がコメントや meta で始まる保存済み HTML も拾う。
        if (str_starts_with($lower, '<') && str_contains($lower, '<html')) {
            return UploadedFile::KIND_HTML;
        }

        return null;
    }

    private static function detectMime(string $path): string
    {
        if (!function_exists('finfo_open')) {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ファイルが大きすぎます。',
            UPLOAD_ERR_PARTIAL => 'アップロードが途中で終わりました。',
            UPLOAD_ERR_NO_FILE => 'ファイルが選ばれていません。',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'サーバー側でファイルを保存できませんでした。',
            UPLOAD_ERR_EXTENSION => 'アップロードが拡張機能に止められました。',
            default => 'アップロードに失敗しました。',
        };
    }
}
