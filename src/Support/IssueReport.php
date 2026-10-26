<?php

declare(strict_types=1);

namespace DropTime\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 「問題を報告」のリンク先（GitHub の新しい Issue。本文に版・画面・ブラウザを書き込んでおく）。
 *
 * 報告する人が自分で調べなくてよいように、直すのに要る情報を先に埋めておく。
 * 選手の名前などの中身は入れない。画面はパスだけで、クエリは付けない。
 * リポジトリが private のときは「GitHub」ボタン（ソースへのリンク）は出さない。
 */
final class IssueReport
{
    public function __construct(
        private readonly string $repo,
        private readonly bool $private,
        private readonly string $version,
    ) {
    }

    public static function fromConfig(Config $config): ?self
    {
        $repo = $config->string('feedback.repo', '');

        if ($repo === '') {
            return null;
        }

        return new self($repo, (bool) $config->get('feedback.private', true), self::readVersion());
    }

    /** デプロイのときに書かれる REVISION（git のコミット）。無ければ dev。 */
    public static function readVersion(): string
    {
        $file = APP_ROOT . '/REVISION';
        $value = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $value !== '' ? $value : 'dev';
    }

    public function showSourceLink(): bool
    {
        return !$this->private;
    }

    public function sourceUrl(): string
    {
        return 'https://github.com/' . $this->repo;
    }

    public function newIssueUrl(string $path, string $userAgent, string $lang, DateTimeImmutable $now): string
    {
        $local = $now->setTimezone(new DateTimeZone('America/Toronto'));

        $body = "Version: {$this->version}\n";
        $body .= "Page: {$path}\n";
        $body .= 'Browser: ' . ($userAgent !== '' ? $userAgent : 'unknown') . "\n";
        $body .= "Language: {$lang}\n";
        $body .= 'Time: ' . $local->format('Y-m-d H:i T') . "\n\n";

        return $this->sourceUrl() . '/issues/new?body=' . rawurlencode($body);
    }
}
