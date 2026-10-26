<?php

declare(strict_types=1);

namespace DropTime\Support;

/**
 * UI の多言語化。対応は英語・日本語・広東語（香港式の繁体字表記）。
 *
 * 言語の決まり方（Kernel が解決する）:
 *   1. 画面右上での明示的な選択（セッション + Cookie に保存）
 *   2. ブラウザの Accept-Language
 *   3. 既定は英語（クラブがカナダのため）
 *
 * 辞書は lang/<code>.php が返す連想配列。キーが無ければ英語 → キー名の順で
 * 落ちるので、画面が空文字で壊れることはない。訳の追加時は 3 ファイルすべてに
 * 同じキーを入れること（キーのずれはテストが検出する）。
 */
final class Lang
{
    public const SUPPORTED = ['en', 'ja', 'yue'];
    public const DEFAULT = 'en';

    private static string $current = self::DEFAULT;

    /** @var array<string, array<string, string>> 読み込んだ辞書のキャッシュ */
    private static array $loaded = [];

    public static function init(string $code): void
    {
        self::$current = in_array($code, self::SUPPORTED, true) ? $code : self::DEFAULT;
    }

    public static function current(): string
    {
        return self::$current;
    }

    /** <html lang="..."> に入れる BCP 47 タグ。広東語は繁体字・香港。 */
    public static function htmlLang(): string
    {
        return match (self::$current) {
            'ja' => 'ja',
            'yue' => 'yue-Hant-HK',
            default => 'en',
        };
    }

    /**
     * 訳文を返す。追加の引数は sprintf 形式で埋め込む。
     */
    public static function t(string $key, mixed ...$args): string
    {
        $template = self::dictionary(self::$current)[$key]
            ?? self::dictionary(self::DEFAULT)[$key]
            ?? $key;

        if ($args === []) {
            return $template;
        }

        return vsprintf($template, $args);
    }

    /**
     * Accept-Language ヘッダーから対応言語を選ぶ。q 値の高い順に見て、
     * 最初に対応言語へ写せたものを返す。写せなければ null。
     */
    public static function negotiate(string $header): ?string
    {
        $candidates = [];

        foreach (explode(',', $header) as $index => $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));

            if ($tag === '') {
                continue;
            }

            $q = 1.0;

            foreach (array_slice($pieces, 1) as $param) {
                if (preg_match('/^\s*q=([0-9.]+)/', $param, $m) === 1) {
                    $q = (float) $m[1];
                }
            }

            // 同じ q なら先に書かれたほうを優先する。
            $candidates[] = ['tag' => $tag, 'q' => $q, 'order' => $index];
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['q'], $a['order']] <=> [$a['q'], $b['order']]);

        foreach ($candidates as $candidate) {
            $mapped = self::mapTag($candidate['tag']);

            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    /**
     * 言語タグを対応言語へ写す。中国語系は広東語（繁体字）に寄せる。
     * クラブの中国語話者コミュニティが広東語圏のため。
     */
    private static function mapTag(string $tag): ?string
    {
        $primary = explode('-', $tag)[0];

        return match (true) {
            $primary === 'ja' => 'ja',
            $primary === 'yue', $primary === 'zh' => 'yue',
            $primary === 'en' => 'en',
            default => null,
        };
    }

    /** @return array<string, string> */
    private static function dictionary(string $code): array
    {
        if (!isset(self::$loaded[$code])) {
            $path = APP_ROOT . '/lang/' . $code . '.php';

            /** @var array<string, string> $dictionary */
            $dictionary = is_file($path) ? require $path : [];
            self::$loaded[$code] = $dictionary;
        }

        return self::$loaded[$code];
    }
}
