<?php

declare(strict_types=1);

use DropTime\Support\Config;
use DropTime\Support\IssueReport;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** フッターの「問題を報告」と「GitHub」。設定に feedback.repo が無ければ何も出さない。 */

/** @var string $currentPath */
/** @var string $userAgent */
$report = IssueReport::fromConfig(Config::instance());

if ($report === null) {
    return;
}

$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
$issueUrl = $report->newIssueUrl($currentPath, $userAgent, Lang::current(), new DateTimeImmutable());
?>
<a class="footer-nav__link" href="<?= $e($issueUrl) ?>" target="_blank" rel="noopener" title="<?= $t('feedback.report_hint') ?>">
    <svg class="footer-nav__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 8h-2.8a6 6 0 0 0-1.8-2L17 4.4 15.6 3l-2.2 2.2a6 6 0 0 0-2.8 0L8.4 3 7 4.4 8.6 6c-.7.5-1.3 1.2-1.8 2H4v2h2.1l-.1 1v1H4v2h2v1l.1 1H4v2h2.8a6 6 0 0 0 10.4 0H20v-2h-2.1l.1-1v-1h2v-2h-2v-1l-.1-1H20V8zm-6 8h-4v-2h4v2zm0-4h-4v-2h4v2z"/></svg>
    <span><?= $t('feedback.report') ?></span>
</a>
<?php if ($report->showSourceLink()) : ?>
    <a class="footer-nav__link" href="<?= $e($report->sourceUrl()) ?>" target="_blank" rel="noopener" title="<?= $t('feedback.source_hint') ?>">
        <svg class="footer-nav__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7c-2.8.6-3.4-1.3-3.4-1.3-.4-1.2-1.1-1.5-1.1-1.5-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.6 2.4 1.1 2.9.8.1-.7.4-1.1.6-1.3-2.2-.3-4.6-1.1-4.6-5 0-1.1.4-2 1-2.7-.1-.3-.4-1.3.1-2.7 0 0 .8-.3 2.8 1a9.6 9.6 0 0 1 5 0c1.9-1.3 2.8-1 2.8-1 .5 1.4.2 2.4.1 2.7.6.7 1 1.6 1 2.7 0 3.9-2.4 4.7-4.6 5 .4.3.7.9.7 1.9V21c0 .3.2.6.7.5A10 10 0 0 0 12 2z"/></svg>
        <span>GitHub</span>
    </a>
<?php endif; ?>
