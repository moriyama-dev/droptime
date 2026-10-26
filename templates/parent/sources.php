<?php

declare(strict_types=1);

use DropTime\Evidence\EvidenceStore;
use DropTime\Support\Config;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var list<array{meet: array<string, mixed>, captures: list<array<string, mixed>>}> $groups */
/** @var list<array<string, mixed>> $other */
/** @var array<string, mixed> $ledger */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
$tz = Config::instance()->string('app.timezone', 'America/Toronto');

$row = static function (array $capture) use ($e, $t, $view, $tz): string {
    $isOriginals = ($capture['kind'] ?? '') === 'originals';
    $status = $capture['http']['status'] ?? null;
    $thumb = $isOriginals ? '' : '<img class="source-row__thumb" loading="lazy" alt="" src="'
        . $e($view->url('sources.file', ['capture' => (string) $capture['id']]) . '?name=thumb.png') . '">';
    $badge = $isOriginals
        ? '<span class="badge">' . $t('src.originals_badge', count((array) $capture['files'])) . '</span>'
        : '<span class="badge badge--ok">HTTP ' . $e($status) . ' · ' . $t('src.no_login') . '</span>';

    return '<li><a class="source-row" href="' . $view->url('sources.show', ['capture' => (string) $capture['id']]) . '">'
        . $thumb
        . '<span class="source-row__body">'
        . '<span class="source-row__url">' . $e(preg_replace('#^https?://#', '', (string) $capture['url'])) . '</span>'
        . '<span class="source-row__meta">' . $badge . ' <span class="time">' . $e(EvidenceStore::localTime((string) $capture['captured_at'], $tz)) . '</span></span>'
        . '</span></a></li>';
};
?>
<h1 class="page-title"><?= $t('src.title') ?></h1>

<p class="lead"><?= $t('src.lead') ?></p>

<div class="verify-box<?= $ledger['ok'] ? ' verify-box--ok' : ' verify-box--ng' ?>">
    <strong><?= $ledger['ok'] ? $t('src.ledger_ok', (int) $ledger['entries']) : $t('src.ledger_ng', (int) $ledger['broken_at']) ?></strong>
    <span><?= $t('src.ledger_body') ?></span>
</div>

<?php foreach ($groups as $group) : ?>
    <section class="section">
        <div class="section-head">
            <h2 class="section__title"><?= $e($group['meet']['name']) ?></h2>
            <span class="section-head__meta time"><?= $e($group['meet']['start_date']) ?></span>
        </div>
        <?php if (!empty($group['meet']['source_file'])) : ?>
            <p class="footnote"><?= $t('meet.source') ?>: <span class="mono"><?= $e($group['meet']['source_file']) ?></span></p>
        <?php endif; ?>
        <?php if ($group['captures'] === []) : ?>
            <p class="empty"><?= $t('src.none_for_meet') ?></p>
        <?php else : ?>
            <ul class="source-list">
                <?php foreach ($group['captures'] as $capture) : ?>
                    <?= $row($capture) ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if ($other !== []) : ?>
    <section class="section">
        <h2 class="section__title"><?= $t('src.other') ?></h2>
        <ul class="source-list">
            <?php foreach ($other as $capture) : ?>
                <?= $row($capture) ?>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<p class="footnote"><?= $t('src.how') ?></p>
