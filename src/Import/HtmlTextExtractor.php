<?php

declare(strict_types=1);

namespace DropTime\Import;

use DOMDocument;
use DOMElement;

/**
 * ヒートシートの HTML からのテキスト抽出。
 *
 * Hy-Tek 系のヒートシート HTML は等幅テキストを <pre> に流し込んだだけの
 * 構造なので、<pre> の中身を文書順に連結したものがパーサーの入力になる。
 */
final class HtmlTextExtractor
{
    public function extract(string $html): string
    {
        if (trim($html) === '') {
            throw new ImportException('HTML が空です。');
        }

        $document = new DOMDocument();

        // 壊れた HTML でも警告を画面に出さず、読める範囲で読む。
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(self::withEncodingHint($html), LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $blocks = [];

        foreach ($document->getElementsByTagName('pre') as $pre) {
            if (!$pre instanceof DOMElement) {
                continue;
            }

            $blocks[] = $pre->textContent;
        }

        if ($blocks === []) {
            throw new ImportException(
                'この HTML には <pre> ブロックがありません。ヒートシートの HTML は等幅テキストを'
                . ' <pre> に入れた形式を想定しています。PDF での取り込みを試してください。'
            );
        }

        $text = implode("\n", $blocks);

        if (trim($text) === '') {
            throw new ImportException('HTML の <pre> ブロックが空でした。');
        }

        return $text;
    }

    /**
     * DOMDocument::loadHTML は宣言が無いと ISO-8859-1 として解釈するため、
     * UTF-8 の宣言を先頭に足す。すでに meta charset があってもこの宣言が勝つ。
     */
    private static function withEncodingHint(string $html): string
    {
        return '<?xml encoding="UTF-8">' . $html;
    }
}
