<?php

declare(strict_types=1);

namespace DropTime\Push;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Web Push の送信（依存なし・openssl と curl だけ）。
 *
 *   - 暗号化: RFC 8291（Message Encryption for Web Push）+ RFC 8188 の aes128gcm
 *   - 送り主の証明: RFC 8292（VAPID）の ES256 の JWT
 *
 * 通知の中身（選手名・種目・時刻）はブラウザの会社（Google・Apple・Mozilla）の
 * 中継サーバーを通るが、端末の鍵で暗号化してあるので中継サーバーには読めない。
 */
final class WebPush
{
    /** P-256 の公開鍵（非圧縮 65 バイト）を SubjectPublicKeyInfo の DER にするときの前置き。 */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private OpenSSLAsymmetricKey $vapidKey;

    private string $vapidPublic;

    /**
     * @param string $vapidPrivatePem VAPID の秘密鍵（PEM）
     * @param string $subject 連絡先（https の URL か mailto:）
     */
    public function __construct(string $vapidPrivatePem, private readonly string $subject)
    {
        $key = openssl_pkey_get_private($vapidPrivatePem);

        if ($key === false) {
            throw new RuntimeException('VAPID の秘密鍵を読めません。');
        }

        $this->vapidKey = $key;
        $this->vapidPublic = self::rawPublicKey($key);
    }

    /** ブラウザの pushManager.subscribe に渡す公開鍵（base64url）。 */
    public function publicKey(): string
    {
        return self::b64u($this->vapidPublic);
    }

    /**
     * 新しい VAPID の鍵を作る。
     *
     * @return array{private_pem: string, public: string}
     */
    public static function generateVapidKeys(): array
    {
        $key = self::newKey();
        openssl_pkey_export($key, $pem);

        return ['private_pem' => (string) $pem, 'public' => self::b64u(self::rawPublicKey($key))];
    }

    /**
     * 1 件送る。
     *
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     * @return int 中継サーバーの HTTP ステータス（0 は通信できなかった）。404・410 は購読が無くなった印。
     */
    public function send(array $subscription, string $payload, int $ttl = 600, string $urgency = 'high'): int
    {
        $body = self::encrypt($payload, self::b64uDecode($subscription['p256dh']), self::b64uDecode($subscription['auth']));
        $jwt = $this->vapidJwt($subscription['endpoint'], time() + 12 * 3600);

        $ch = curl_init($subscription['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: vapid t=' . $jwt . ', k=' . $this->publicKey(),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: ' . max(0, $ttl),
                'Urgency: ' . $urgency,
            ],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status;
    }

    /** VAPID の JWT（ES256）。aud は送り先の origin。 */
    public function vapidJwt(string $endpoint, int $expires): string
    {
        $parts = parse_url($endpoint);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('送り先の URL が不正です。');
        }

        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $header = self::b64u((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64u((string) json_encode(['aud' => $audience, 'exp' => $expires, 'sub' => $this->subject], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $claims;

        if (!openssl_sign($input, $der, $this->vapidKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID の署名に失敗しました。');
        }

        return $input . '.' . self::b64u(self::derToRaw((string) $der));
    }

    /**
     * RFC 8291 の暗号化。戻り値はそのまま POST の本文になる（ヘッダー + 暗号文 1 レコード）。
     *
     * @param string $uaPublic 端末の公開鍵（非圧縮 65 バイト）
     * @param string $authSecret 端末の auth（16 バイト）
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, ?OpenSSLAsymmetricKey $asKey = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new RuntimeException('購読の鍵の形が不正です。');
        }

        $asKey ??= self::newKey();
        $asPublic = self::rawPublicKey($asKey);
        $salt ??= random_bytes(16);

        $secret = openssl_pkey_derive(self::publicKeyFromRaw($uaPublic), $asKey, 32);

        if ($secret === false) {
            throw new RuntimeException('鍵の共有（ECDH）に失敗しました。');
        }

        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // 1 レコードだけなので、区切りは 0x02（最後のレコード）。
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($cipher === false) {
            throw new RuntimeException('暗号化に失敗しました。');
        }

        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** 新しい P-256 の鍵。 */
    public static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new RuntimeException('鍵を作れません。');
        }

        return $key;
    }

    /** 鍵の公開部分を非圧縮 65 バイト（0x04 || X || Y）で。 */
    public static function rawPublicKey(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('P-256 の鍵ではありません。');
        }

        return "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function publicKeyFromRaw(string $raw): OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode((string) hex2bin(self::P256_SPKI_PREFIX) . $raw), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new RuntimeException('公開鍵を読めません。');
        }

        return $key;
    }

    /** ECDSA の DER 署名を JWT 用の R || S（各 32 バイト）にする。 */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $length);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $out;
    }

    /** R || S を DER に戻す（テストで署名を確かめるため）。 */
    public static function rawToDer(string $raw): string
    {
        $ints = '';

        foreach ([substr($raw, 0, 32), substr($raw, 32, 32)] as $part) {
            $part = ltrim($part, "\0");

            if ($part === '' || ord($part[0]) & 0x80) {
                $part = "\0" . $part;
            }

            $ints .= "\x02" . chr(strlen($part)) . $part;
        }

        return "\x30" . chr(strlen($ints)) . $ints;
    }

    public static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        if ($decoded === false) {
            throw new RuntimeException('base64url を読めません。');
        }

        return $decoded;
    }
}
