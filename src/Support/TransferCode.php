<?php

declare(strict_types=1);

namespace DropTime\Support;

/**
 * お気に入りを端末間で引き継ぐためのコード。
 *
 * コードそのものは保存せず、SHA-256 だけを profiles.key_hash に置く。
 * コードは乱数由来なのでソルトは不要。読み間違えやすい文字
 * （I/L/O/U と 1/0 の混同）を避けた Crockford Base32 の部分集合を使う。
 */
final class TransferCode
{
    /** Crockford Base32。I/L/O/U を含まず、読み違いは normalize で吸収する。 */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** 32^10 ≒ 1.1 × 10^15 通り。総当たりにはレート制限も併用する。 */
    private const LENGTH = 10;

    /** 新しいコードを作る。表示は XXXXX-XXXXX 形式。 */
    public static function generate(): string
    {
        $chars = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 5) . '-' . substr($chars, 5);
    }

    /**
     * 入力ゆれを正規化する。小文字・空白・ハイフンを吸収し、
     * コードに使っていない紛らわしい文字（O/I/L/U）は見間違えの相手へ写す。
     */
    public static function normalize(string $input): string
    {
        $upper = strtoupper(trim($input));
        $upper = (string) preg_replace('/[\s\-_]+/', '', $upper);

        return strtr($upper, ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);
    }

    /** 保存用ハッシュ。表示形式のゆれに左右されないよう正規化してから取る。 */
    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    /** 表示用に XXXXX-XXXXX へ整える。 */
    public static function pretty(string $code): string
    {
        $normalized = self::normalize($code);

        if (strlen($normalized) !== self::LENGTH) {
            return $code;
        }

        return substr($normalized, 0, 5) . '-' . substr($normalized, 5);
    }
}
