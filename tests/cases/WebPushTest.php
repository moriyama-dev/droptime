<?php

declare(strict_types=1);

use DropTime\Push\WebPush;
use DropTime\Tests\Assert;

/**
 * 端末（ブラウザ）の側に立って復号し、RFC 8291 どおりに暗号化できているかを確かめる。
 */
function webPushDecryptAsBrowser(string $body, OpenSSLAsymmetricKey $uaKey, string $authSecret): string
{
    $uaPublic = WebPush::rawPublicKey($uaKey);
    $salt = substr($body, 0, 16);
    $rs = unpack('N', substr($body, 16, 4))[1];
    $idLength = ord($body[20]);
    $asPublic = substr($body, 21, $idLength);
    $record = substr($body, 21 + $idLength);

    Assert::same(4096, $rs);
    Assert::same(65, $idLength);

    $secret = openssl_pkey_derive(WebPush::publicKeyFromRaw($asPublic), $uaKey, 32);
    $ikm = hash_hkdf('sha256', (string) $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $plain = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($record, -16));
    Assert::true($plain !== false, '復号できる');
    Assert::same("\x02", substr((string) $plain, -1), '最後のレコードの区切り');

    return substr((string) $plain, 0, -1);
}

return [
    '端末の鍵で復号すると元の本文に戻る' => static function (): void {
        $uaKey = WebPush::newKey();
        $auth = random_bytes(16);
        $payload = (string) json_encode(['title' => 'Doe, Jane', 'body' => 'あと約 30 分'], JSON_UNESCAPED_UNICODE);

        $body = WebPush::encrypt($payload, WebPush::rawPublicKey($uaKey), $auth);

        Assert::same($payload, webPushDecryptAsBrowser($body, $uaKey, $auth));
    },
    'auth が違えば復号できない' => static function (): void {
        $uaKey = WebPush::newKey();
        $body = WebPush::encrypt('x', WebPush::rawPublicKey($uaKey), random_bytes(16));

        $uaPublic = WebPush::rawPublicKey($uaKey);
        $asPublic = substr($body, 21, 65);
        $secret = openssl_pkey_derive(WebPush::publicKeyFromRaw($asPublic), $uaKey, 32);
        $ikm = hash_hkdf('sha256', (string) $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, random_bytes(16));
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", substr($body, 0, 16));
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", substr($body, 0, 16));
        $record = substr($body, 86);

        Assert::false(openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($record, -16)));
    },
    'VAPID の JWT は公開鍵で検証できる' => static function (): void {
        $keys = WebPush::generateVapidKeys();
        $push = new WebPush($keys['private_pem'], 'https://example.com/');

        Assert::same($keys['public'], $push->publicKey());
        Assert::same(65, strlen(WebPush::b64uDecode($push->publicKey())));

        $jwt = $push->vapidJwt('https://fcm.googleapis.com/fcm/send/abc', 1900000000);
        [$header, $claims, $signature] = explode('.', $jwt);
        $raw = WebPush::b64uDecode($signature);

        Assert::same(64, strlen($raw));
        Assert::same(1, openssl_verify($header . '.' . $claims, WebPush::rawToDer($raw), WebPush::publicKeyFromRaw(WebPush::b64uDecode($keys['public'])), OPENSSL_ALGO_SHA256));

        $decoded = json_decode(WebPush::b64uDecode($claims), true);
        Assert::same('https://fcm.googleapis.com', $decoded['aud']);
        Assert::same('https://example.com/', $decoded['sub']);
    },
];
