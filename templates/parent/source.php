<?php

declare(strict_types=1);

use DropTime\Evidence\EvidenceStore;
use DropTime\Support\Config;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var array<string, mixed> $capture */
/** @var array{ok: bool, files: array<string, bool>} $check */
/** @var array<string, mixed> $ledger */
/** @var string|null $chainHash */
/** @var list<array<string, mixed>> $meets */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
$tz = Config::instance()->string('app.timezone', 'America/Toronto');
$id = (string) $capture['id'];
$fileUrl = static fn (string $name): string => $view->url('sources.file', ['capture' => $id]) . '?name=' . rawurlencode($name);
$isOriginals = ($capture['kind'] ?? '') === 'originals';
$meetNames = array_column($meets, 'name', 'id');
$wayback = 'https://web.archive.org/web/*/' . (string) $capture['url'];
?>
<p class="back"><a href="<?= $view->url('sources') ?>">← <?= $t('src.title') ?></a></p>

<h1 class="page-title page-title--small"><?= $e($capture['title'] !== '' ? $capture['title'] : $capture['url']) ?></h1>

<div class="verify-box<?= $check['ok'] && $chainHash !== null ? ' verify-box--ok' : ' verify-box--ng' ?>">
    <strong><?= $check['ok'] && $chainHash !== null ? $t('src.verified') : $t('src.not_verified') ?></strong>
    <span><?= $t('src.verified_body') ?></span>
</div>

<dl class="facts">
    <dt><?= $t('src.url') ?></dt>
    <dd class="mono"><a href="<?= $e($capture['url']) ?>" rel="noreferrer"><?= $e($capture['url']) ?></a></dd>

    <dt><?= $isOriginals ? $t('src.saved_at') : $t('src.captured_at') ?></dt>
    <dd class="time"><?= $e(EvidenceStore::localTime((string) ($capture['saved_at'] ?? $capture['captured_at']), $tz)) ?>
        <span class="muted">(<?= $e($capture['saved_at'] ?? $capture['captured_at']) ?>)</span></dd>

    <?php if (!$isOriginals) : ?>
        <dt><?= $t('src.response') ?></dt>
        <dd>HTTP <?= $e($capture['http']['status'] ?? '') ?> · <?= $t('src.conditions') ?></dd>
        <?php if (!empty($capture['http']['headers']['last-modified'])) : ?>
            <dt><?= $t('src.last_modified') ?></dt>
            <dd class="time"><?= $e(EvidenceStore::localTime((string) $capture['http']['headers']['last-modified'], $tz)) ?></dd>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($capture['meet_ids'])) : ?>
        <dt><?= $t('src.meet') ?></dt>
        <dd>
            <?php foreach ($capture['meet_ids'] as $meetId) : ?>
                <?php if (isset($meetNames[$meetId])) : ?>
                    <a href="<?= $view->url('meets.show', ['meet' => (int) $meetId]) ?>"><?= $e($meetNames[$meetId]) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </dd>
    <?php endif; ?>

    <?php if (($capture['note'] ?? '') !== '') : ?>
        <dt><?= $t('src.note') ?></dt>
        <dd><?= $e($capture['note']) ?></dd>
    <?php endif; ?>

    <dt><?= $t('src.chain') ?></dt>
    <dd class="mono hash"><?= $e($chainHash ?? '—') ?></dd>
</dl>

<?php if (!$isOriginals && isset($capture['files']['screenshot.png'])) : ?>
    <section class="section">
        <h2 class="section__title"><?= $t('src.screenshot') ?></h2>
        <a class="shot-frame" href="<?= $e($fileUrl('screenshot.png')) ?>">
            <img src="<?= $e($fileUrl('screenshot.png')) ?>" alt="<?= $t('src.screenshot_alt', (string) $capture['url']) ?>">
        </a>
    </section>
<?php endif; ?>

<section class="section">
    <h2 class="section__title"><?= $t('src.files') ?></h2>
    <ul class="hash-list">
        <?php foreach ((array) $capture['files'] as $name => $info) : ?>
            <li>
                <span class="hash-list__head">
                    <span class="badge<?= ($check['files'][$name] ?? false) ? ' badge--ok' : ' badge--ng' ?>"><?= ($check['files'][$name] ?? false) ? '✓' : '✗' ?></span>
                    <a href="<?= $e($fileUrl((string) $name)) ?>"><?= $e($name) ?></a>
                    <span class="muted"><?= $e(number_format((int) $info['bytes'])) ?> B</span>
                </span>
                <span class="mono hash">SHA-256 <?= $e($info['sha256']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php if (!$isOriginals && !empty($capture['http']['headers'])) : ?>
    <details class="details">
        <summary><?= $t('src.headers') ?></summary>
        <pre class="pre"><?php foreach ((array) $capture['http']['headers'] as $name => $value) : ?><?= $e($name === '_status_line' ? $value : $name . ': ' . $value) ?>
<?php endforeach; ?></pre>
    </details>
<?php endif; ?>

<p class="footnote"><?= $t('src.wayback') ?> <a href="<?= $e($wayback) ?>" rel="noreferrer">web.archive.org</a></p>
