<?php

declare(strict_types=1);

namespace DropTime\Support;

/**
 * 文字列の正規化。
 *
 * 選手の同一人物判定は DB の照合順序に一切依存させない。ここで作った文字列を
 * utf8mb4_bin の normalized_key に保存し、完全一致だけで突き合わせる。
 * そのため、この関数の出力は環境が変わっても同じでなければならない。
 * intl 拡張は共有ホスティングにあるとは限らないので使わず、明示的な表で置換する。
 */
final class Str
{
    /**
     * アクセント付き文字 → ASCII の対応表。
     * iconv の //TRANSLIT はロケール依存で結果が変わるため使わない。
     *
     * @var array<string, string>
     */
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
        'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e',
        'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i',
        'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j', 'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n', 'ŋ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ŏ' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u',
        'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w',
        'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'þ' => 'th',
        // 全角・特殊な空白と約物。PDF から来るテキストに混ざる。
        "\u{00a0}" => ' ', "\u{2007}" => ' ', "\u{202f}" => ' ',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{2013}" => '-', "\u{2014}" => '-',
    ];

    /**
     * 氏名の正規化。小文字化 → アクセント除去 → 英数字以外を除去 → 空白圧縮。
     * "O'Brien-Smith, Élise" と "obrien smith elise" は同じ結果になる。
     */
    public static function normalizeName(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');
        $ascii = strtr($lower, self::ACCENTS);

        // アポストロフィやハイフンは区切りではなく単に落とす（O'Brien → obrien）。
        $ascii = str_replace(["'", '’', '`'], '', $ascii);

        $ascii = preg_replace('/[^a-z0-9]+/u', ' ', $ascii) ?? '';

        return trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
    }

    /**
     * 突き合わせキー。姓と名の順序ゆれを吸収するため、姓・名を分けて渡す。
     */
    public static function swimmerKey(string $lastName, string $firstName): string
    {
        $last = self::normalizeName($lastName);
        $first = self::normalizeName($firstName);

        // ミドルネームは最初のトークンだけを採用する。大会によって有無が変わるため。
        $firstTokens = explode(' ', $first);
        $first = $firstTokens[0] ?? '';

        return trim($last . ' ' . $first);
    }

    public static function collapseSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
