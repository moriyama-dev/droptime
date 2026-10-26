<?php

declare(strict_types=1);

use DropTime\Parser\NullParser;
use DropTime\Parser\ParserRegistry;
use DropTime\Tests\Assert;

return [
    '全行を未認識として積む' => static function (): void {
        $result = (new NullParser())->parse("Line A\n\nLine B\r\nLine C");

        Assert::same(3, count($result->unrecognized()), '空行は数えない');
        Assert::same(1, $result->unrecognized()[0]->lineNumber);
        Assert::same('Line A', $result->unrecognized()[0]->text);
        Assert::same(1, count($result->warnings()), '未対応の旨の警告が入る');
        Assert::true($result->isEmpty());
    },
    '件数の要約が一致する' => static function (): void {
        $counts = (new NullParser())->parse("a\nb")->counts();

        Assert::same(0, $counts['events']);
        Assert::same(2, $counts['unrecognized']);
    },
    'レジストリは他に候補が無いときスタブを選ぶ' => static function (): void {
        $parser = ParserRegistry::default()->select('何かのテキスト');

        Assert::same('null', $parser->name());
    },
    '名前でパーサーを引ける' => static function (): void {
        $registry = ParserRegistry::default();

        Assert::same('null', $registry->byName('null')?->name());
        Assert::null($registry->byName('存在しない'));
    },
];
