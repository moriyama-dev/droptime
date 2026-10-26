<?php

declare(strict_types=1);

namespace DropTime\Import;

use DropTime\Support\Config;

/**
 * PDF からのテキスト抽出。pdftotext -layout に委ねる。
 *
 * Hy-Tek 系の出力は等幅フォントの固定幅レイアウトなので、-layout で
 * 桁位置を保ったまま起こすことがパーサーの前提になる。
 * 外部コマンドの引数は必ず escapeshellarg() を通す。
 */
final class PdfTextExtractor
{
    public function __construct(private readonly string $binary)
    {
    }

    public static function fromConfig(): self
    {
        return new self(Config::instance()->string('tools.pdftotext', '/usr/bin/pdftotext'));
    }

    /**
     * PDF をテキストに起こして返す。
     *
     * 出力はいったんファイルに書かせる。stdout 経由だとロケールや
     * シェルの都合が挟まるため、-enc UTF-8 で書かせたものを読むほうが確実。
     */
    public function extract(string $pdfPath, string $textPath): string
    {
        if (!is_file($this->binary) || !is_executable($this->binary)) {
            throw new ImportException('pdftotext が見つかりません: ' . $this->binary);
        }

        if (!is_file($pdfPath)) {
            throw new ImportException('PDF ファイルが見つかりません。');
        }

        $command = sprintf(
            '%s -layout -enc UTF-8 %s %s 2>&1',
            escapeshellarg($this->binary),
            escapeshellarg($pdfPath),
            escapeshellarg($textPath)
        );

        $output = shell_exec($command);
        $message = is_string($output) ? trim($output) : '';

        if (!is_file($textPath)) {
            throw new ImportException(
                'PDF のテキスト化に失敗しました。' . ($message !== '' ? ' pdftotext: ' . $message : '')
            );
        }

        $text = file_get_contents($textPath);

        if ($text === false) {
            throw new ImportException('テキスト化した結果を読めませんでした。');
        }

        if (trim($text) === '') {
            // 画像だけの PDF（スキャン）だとここに来る。空のまま先へ進めない。
            throw new ImportException(
                'PDF からテキストを取り出せませんでした。画像だけの（スキャンされた）PDF の可能性があります。'
            );
        }

        return $text;
    }
}
