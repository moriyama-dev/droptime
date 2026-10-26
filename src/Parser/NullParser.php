<?php

declare(strict_types=1);

namespace DropTime\Parser;

/**
 * 何もしないスタブ。
 *
 * 実データ（実物の PDF / ヒートシートの HTML）を受け取るまで、書式を推測した
 * 正規表現は一切書かない。この実装は全行を「未認識」として積むだけで、
 * 取り込みフローの前後（アップロード、テキスト化、確認画面、DB 投入）を
 * 実データ抜きで通せるようにするためにある。
 *
 * 実書式のパーサーが入ったら、このパーサーは最後の受け皿として残す。
 */
final class NullParser implements ParserInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function label(): string
    {
        return '未対応の書式（スタブ）';
    }

    public function confidence(string $text): int
    {
        // 常に最下位。他に候補が無いときだけ選ばれる。
        return 1;
    }

    public function parse(string $text): ParseResult
    {
        $result = new ParseResult($this->name());
        $result->addWarning(
            'この書式に対応するパーサーがまだありません。全行を未認識として記録しました。'
            . '取り込んだ元ファイルは保存してあるので、パーサーを実装したあとで再処理できます。'
        );

        $lineNumber = 0;

        foreach (self::lines($text) as $line) {
            $lineNumber++;

            if (trim($line) === '') {
                continue;
            }

            $result->addUnrecognized($lineNumber, $line);
        }

        return $result;
    }

    /**
     * 改行コードの違いを吸収して 1 行ずつ返す。
     *
     * @return iterable<string>
     */
    private static function lines(string $text): iterable
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        foreach (explode("\n", $normalized) as $line) {
            yield rtrim($line, "\n");
        }
    }
}
