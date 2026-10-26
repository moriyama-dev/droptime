<?php

declare(strict_types=1);

use DropTime\Support\IssueReport;
use DropTime\Tests\Assert;

return [
    '報告の URL に版・画面・ブラウザ・トロント時刻が入る' => static function (): void {
        $report = new IssueReport('moriyama-dev/droptime', true, 'abc1234');
        $url = $report->newIssueUrl('/scores/12', 'Mozilla/5.0 Test', 'ja', new DateTimeImmutable('2026-10-26T15:30:00Z'));

        Assert::true(str_starts_with($url, 'https://github.com/moriyama-dev/droptime/issues/new?body='));
        $body = rawurldecode(substr($url, strpos($url, '=') + 1));
        Assert::true(str_contains($body, "Version: abc1234\n"));
        Assert::true(str_contains($body, "Page: /scores/12\n"));
        Assert::true(str_contains($body, "Browser: Mozilla/5.0 Test\n"));
        Assert::true(str_contains($body, "Language: ja\n"));
        Assert::true(str_contains($body, 'Time: 2026-10-26 11:30 EDT'), 'UTC ではなくトロント時刻');
    },
    'private のリポジトリは GitHub ボタンを出さない' => static function (): void {
        Assert::false((new IssueReport('moriyama-dev/droptime', true, 'dev'))->showSourceLink());
        Assert::true((new IssueReport('moriyama-dev/tsugite', false, 'dev'))->showSourceLink());
    },
];
