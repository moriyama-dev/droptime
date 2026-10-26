<?php

declare(strict_types=1);

use DropTime\Support\Str;
use DropTime\Tests\Assert;

return [
    'アクセントを除去して小文字にする' => static function (): void {
        Assert::same('elise tremblay', Str::normalizeName('Élise Tremblay'));
    },
    'アポストロフィを落とす' => static function (): void {
        Assert::same('obrien', Str::normalizeName("O'Brien"));
    },
    'ハイフンは空白にする' => static function (): void {
        Assert::same('marie claire', Str::normalizeName('Marie-Claire'));
    },
    '連続する空白を圧縮する' => static function (): void {
        Assert::same('anna lee', Str::normalizeName("  Anna   \t Lee  "));
    },
    'ドイツ語のエスツェットを展開する' => static function (): void {
        Assert::same('grossmann', Str::normalizeName('Großmann'));
    },
    'ノーブレークスペースも空白として扱う' => static function (): void {
        Assert::same('jean luc', Str::normalizeName("Jean\u{00a0}Luc"));
    },
    '突き合わせキーは姓 名の順' => static function (): void {
        Assert::same('tremblay elise', Str::swimmerKey('Tremblay', 'Élise'));
    },
    'ミドルネームの有無で突き合わせキーが変わらない' => static function (): void {
        Assert::same(
            Str::swimmerKey('Tremblay', 'Elise'),
            Str::swimmerKey('Tremblay', 'Elise Marie')
        );
    },
    '表記ゆれが同じキーに落ちる' => static function (): void {
        Assert::same(
            Str::swimmerKey("O'Brien", 'Sean'),
            Str::swimmerKey('OBRIEN', 'SEAN')
        );
    },
];
