<?php

declare(strict_types=1);

use DropTime\Import\UploadedFile;
use DropTime\Import\UploadValidator;
use DropTime\Tests\Assert;

return [
    'PDF は先頭のマジックバイトで判定する' => static function (): void {
        Assert::same(UploadedFile::KIND_PDF, UploadValidator::sniff('%PDF-1.7 ...'));
    },
    '拡張子ではなく中身を見る（PDF を装った HTML）' => static function (): void {
        Assert::same(UploadedFile::KIND_HTML, UploadValidator::sniff('<!DOCTYPE html><html>'));
    },
    'BOM 付き HTML も受け付ける' => static function (): void {
        Assert::same(UploadedFile::KIND_HTML, UploadValidator::sniff("\u{feff}  <html lang=\"en\">"));
    },
    'pre から始まる断片も HTML と見なす' => static function (): void {
        Assert::same(UploadedFile::KIND_HTML, UploadValidator::sniff('<pre>Event 1</pre>'));
    },
    'コメントで始まる保存済み HTML も拾う' => static function (): void {
        Assert::same(UploadedFile::KIND_HTML, UploadValidator::sniff('<!-- saved --><html><body>'));
    },
    'どちらでもないものは拒否する' => static function (): void {
        Assert::null(UploadValidator::sniff('PK\x03\x04 zip archive'));
        Assert::null(UploadValidator::sniff(''));
        Assert::null(UploadValidator::sniff('Event 1 Girls 50 Free'));
    },
];
