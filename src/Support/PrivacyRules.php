<?php

declare(strict_types=1);

namespace DropTime\Support;

/**
 * 公開リポジトリに出してはいけないものの判定（bin/privacy-check.php と bin/privacy-ci.php が共有する）。
 *
 * 1. 語（個人名・クラブ名・家族の情報）: 呼び出し側が集めて渡す。ここに語そのものは書かない。
 * 2. 機密情報の型: 秘密鍵・トークン・パスワードのハッシュ・Tailscale の IP・個人のメールアドレスなど。
 *
 * GitHub Actions 側（privacy-ci）は語の平文を持たない。語を単語に区切って並べ直したものを、
 * 秘密の塩で SHA-256 にした値（termHash）だけを持ち、本文側の 1〜MAX_WORDS 語の並びと突き合わせる。
 */
final class PrivacyRules
{
    /** 語の照合に使う単語の並びの最大長。これより長い語は CI 側では照合できない。 */
    public const MAX_WORDS = 5;

    /** 個人の名前を含んでも公開してよい語（GitHub のアカウント名など）。 */
    public const ALLOWED_TERMS = ['moriyama-dev'];

    /** 公開してよいメールアドレス（GitHub の非公開用アドレス・コミットの共著者表記・見本）。 */
    private const ALLOWED_EMAIL = '/(@users\.noreply\.github\.com|^noreply@anthropic\.com|@example\.(com|org|net))$/i';

    /** 公開してはいけないパス。var/ は .htaccess と .gitignore だけ許す。 */
    public static function isForbiddenPath(string $path): bool
    {
        if (preg_match('#^var/([^/]+/)?(\.htaccess|\.gitignore)$#', $path) === 1) {
            return false;
        }

        return $path === 'config/config.php'
            || $path === 'REVISION'
            || str_starts_with($path, 'var/')
            || str_starts_with($path, '.claude/')
            || preg_match('/\.(sql\.gz|json\.gz|bundle)$/', $path) === 1
            || preg_match('/(^|\/)(\.env(\..*)?|id_(rsa|ed25519|ecdsa)(\.pub)?|.*\.(pem|key|p12|pfx))$/', $path) === 1;
    }

    /**
     * 機密情報の型。名前 => 正規表現。
     *
     * @return array<string, string>
     */
    public static function secretPatterns(): array
    {
        return [
            '秘密鍵' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
            'GitHub のトークン' => '/\b(gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})\b/',
            'API キー' => '/\b(sk-(ant-)?[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_-]{35}|xox[abprs]-[A-Za-z0-9-]{10,})\b/',
            'パスワードのハッシュ' => '/\$(2[aby]|argon2i|argon2id)\$[^\s\'"]{20,}/',
            'Tailscale の IP' => '/\b100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.\d{1,3}\.\d{1,3}\b/',
            '空でないパスワードの設定' => '/[\'"](password|passwd|secret|api_key|token)[\'"]\s*=>\s*[\'"][^\'"]{4,}[\'"]/i',
        ];
    }

    /**
     * 機密情報の型に当たったもの。行の中に「privacy-check: allow」とあれば、その行は見ない
     * （ログインの時間をそろえるためのダミーのハッシュなど、公開してよいと確かめたもの）。
     *
     * @return list<string> 当たった型の名前
     */
    public static function findSecrets(string $text): array
    {
        $lines = array_filter(
            explode("\n", $text),
            static fn (string $line): bool => !str_contains($line, 'privacy-check: allow')
        );
        $text = implode("\n", $lines);
        $found = [];

        foreach (self::secretPatterns() as $name => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $name;
            }
        }

        if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', $text, $m) > 0) {
            foreach ($m[0] as $email) {
                if (preg_match(self::ALLOWED_EMAIL, $email) !== 1) {
                    $found[] = '個人のメールアドレス';
                    break;
                }
            }
        }

        return $found;
    }

    /** コミットの作成者に使ってよいメールアドレスか。 */
    public static function isPublicEmail(string $email): bool
    {
        return preg_match(self::ALLOWED_EMAIL, trim($email)) === 1;
    }

    /**
     * 語・本文を単語の並びにそろえる（小文字・文字と数字以外は区切り）。
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $text = mb_strtolower($text);

        foreach (self::ALLOWED_TERMS as $allowed) {
            $text = str_replace($allowed, ' ', $text);
        }

        return preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** 語を CI 用のハッシュにする。語は単語の並びにそろえてから塩とまとめる。 */
    public static function termHash(string $salt, string $term): ?string
    {
        $words = self::words($term);

        if ($words === [] || count($words) > self::MAX_WORDS) {
            return null;
        }

        return substr(hash('sha256', $salt . "\n" . implode(' ', $words)), 0, 24);
    }

    /**
     * 本文の中の 1〜MAX_WORDS 語の並びのうち、ハッシュの一覧に当たったものの行番号。
     *
     * @param array<string, true> $hashes
     * @return list<int> 1 始まりの行番号
     */
    public static function findHashedTerms(string $salt, array $hashes, string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $index => $line) {
            $words = self::words($line);
            $count = count($words);

            for ($i = 0; $i < $count; $i++) {
                $phrase = '';

                for ($n = 0; $n < self::MAX_WORDS && $i + $n < $count; $n++) {
                    $phrase = $n === 0 ? $words[$i] : $phrase . ' ' . $words[$i + $n];

                    if (isset($hashes[substr(hash('sha256', $salt . "\n" . $phrase), 0, 24)])) {
                        $lines[] = $index + 1;
                        continue 3;
                    }
                }
            }
        }

        return $lines;
    }
}
