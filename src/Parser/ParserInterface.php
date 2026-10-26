<?php

declare(strict_types=1);

namespace DropTime\Parser;

/**
 * パーサーの契約。
 *
 * 入力は pdftotext -layout の生出力、または HTML から取り出した等幅テキスト。
 * 実装は「1 行ずつ種類を判定する状態機械」として書く。全体を 1 個の巨大な
 * 正規表現で処理しようとしないこと。
 *
 * 解釈できない行は捨てず、ParseResult::addUnrecognized() に必ず積む。
 */
interface ParserInterface
{
    /** フィクスチャや imports.parser に記録する識別子。 */
    public function name(): string;

    /** 人が読む名前。確認画面に出す。 */
    public function label(): string;

    /**
     * このテキストが自分の担当書式である確からしさ。0 なら対象外、100 で確実。
     * 主催者ごとに書式が違うため、複数のパーサーから選ぶのに使う。
     */
    public function confidence(string $text): int;

    public function parse(string $text): ParseResult;
}
