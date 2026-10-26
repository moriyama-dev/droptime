<?php

declare(strict_types=1);

use DropTime\Import\HtmlTextExtractor;
use DropTime\Import\ImportException;
use DropTime\Tests\Assert;

return [
    'pre の中身を取り出す' => static function (): void {
        $html = '<html><body><h1>Meet</h1><pre>Line 1
Line 2</pre></body></html>';

        Assert::same("Line 1\nLine 2", (new HtmlTextExtractor())->extract($html));
    },
    '複数の pre を文書順に連結する' => static function (): void {
        $html = '<html><body><pre>A</pre><p>間</p><pre>B</pre></body></html>';

        Assert::same("A\nB", (new HtmlTextExtractor())->extract($html));
    },
    '実体参照と UTF-8 を正しく戻す' => static function (): void {
        $html = '<html><body><pre>&lt;Élise&gt; &amp; Côté</pre></body></html>';

        Assert::same('<Élise> & Côté', (new HtmlTextExtractor())->extract($html));
    },
    'pre が無ければ明確に断る' => static function (): void {
        Assert::throws(ImportException::class, static function (): void {
            (new HtmlTextExtractor())->extract('<html><body><table><tr><td>x</td></tr></table></body></html>');
        });
    },
    '空の HTML を断る' => static function (): void {
        Assert::throws(ImportException::class, static function (): void {
            (new HtmlTextExtractor())->extract('   ');
        });
    },
];
