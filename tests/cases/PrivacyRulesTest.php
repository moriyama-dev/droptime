<?php

declare(strict_types=1);

use DropTime\Support\PrivacyRules;
use DropTime\Tests\Assert;

return [
    '語のハッシュは区切りや大文字小文字の違いを吸収して本文に当たる' => static function (): void {
        $salt = 'test-salt';
        $hashes = [PrivacyRules::termHash($salt, 'Doe, Jane') => true, PrivacyRules::termHash($salt, 'Example Swim Club') => true];

        Assert::same([2], PrivacyRules::findHashedTerms($salt, $hashes, "ok\nresult: DOE  JANE 38.74"));
        Assert::same([1], PrivacyRules::findHashedTerms($salt, $hashes, 'Team: example-swim-club'));
        Assert::same([], PrivacyRules::findHashedTerms($salt, $hashes, 'Doe Janet / Jane Doe-X'), '語の一部だけでは当たらない');
        Assert::same([], PrivacyRules::findHashedTerms('other-salt', $hashes, 'Doe Jane'), '塩が違えば当たらない');
    },
    '機密情報の型を見つける' => static function (): void {
        Assert::same(['秘密鍵'], PrivacyRules::findSecrets("-----BEGIN OPENSSH PRIVATE KEY-----\nabc")); // privacy-check: allow（見本）
        Assert::same(['GitHub のトークン'], PrivacyRules::findSecrets('token=ghp_' . str_repeat('a', 36)));
        Assert::same(['パスワードのハッシュ'], PrivacyRules::findSecrets("'hash' => '\$2y\$10\$" . str_repeat('x', 53) . "'"));
        Assert::same(['Tailscale の IP'], PrivacyRules::findSecrets('http://100.101.2.3/')); // privacy-check: allow（見本）
        Assert::same(['空でないパスワードの設定'], PrivacyRules::findSecrets("'password' => 'hunter22',")); // privacy-check: allow（見本）
        Assert::same(['個人のメールアドレス'], PrivacyRules::findSecrets('mail me: someone@gmail.com')); // privacy-check: allow（見本）
    },
    '公開してよいものは通す' => static function (): void {
        Assert::same([], PrivacyRules::findSecrets("'password' => '',\nCo-Authored-By: X <noreply@anthropic.com>\njane@example.com"));
        Assert::same([], PrivacyRules::findSecrets('1+moriyama-dev@users.noreply.github.com 100.20.3.4'));
        Assert::same([], PrivacyRules::findSecrets("\$2y\$10\$" . str_repeat('x', 53) . ' // privacy-check: allow'));
        Assert::true(PrivacyRules::isPublicEmail('7315004+moriyama-dev@users.noreply.github.com'));
        Assert::true(PrivacyRules::isPublicEmail('noreply@github.com'), 'GitHub の Web 画面のコミット');
        Assert::false(PrivacyRules::isPublicEmail('someone@gmail.com')); // privacy-check: allow（見本）
    },
    '公開してはいけないパス' => static function (): void {
        Assert::true(PrivacyRules::isForbiddenPath('config/config.php'));
        Assert::true(PrivacyRules::isForbiddenPath('var/privacy/extra.txt'));
        Assert::true(PrivacyRules::isForbiddenPath('deploy/.env'));
        Assert::true(PrivacyRules::isForbiddenPath('keys/server.pem'));
        Assert::false(PrivacyRules::isForbiddenPath('var/logs/.gitignore'));
        Assert::false(PrivacyRules::isForbiddenPath('config/config.example.php'));
    },
];
