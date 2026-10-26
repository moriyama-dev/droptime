<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var bool $fetchRunning */
/** @var array<string, mixed>|null $fetchStatus */
/** @var list<array<string, mixed>> $imports */
/** @var list<array<string, mixed>> $meets */
/** @var int $pendingMerges */
/** @var array{type: string, message: string}|null $flash */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

$statusLabel = static fn (string $s): string => in_array($s, ['uploaded', 'extracted', 'parsed', 'committed', 'failed', 'discarded'], true)
    ? Lang::t('admin.status.' . $s)
    : $s;
?>
<h1 class="page-title"><?= $t('admin.title') ?></h1>

<?php if ($flash !== null) : ?>
    <p class="alert alert--<?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></p>
<?php endif; ?>

<section class="panel panel--highlight">
    <h2 class="panel__title"><?= $t('fetch.title') ?></h2>
    <p class="panel__note"><?= $t('fetch.note') ?></p>
    <?= $view->partial('admin/_fetch-summary', ['status' => $fetchStatus, 'running' => $fetchRunning]) ?>
    <?php if ($fetchRunning) : ?>
        <a class="button" href="<?= $view->url('admin.fetch') ?>"><?= $t('fetch.see_progress') ?></a>
    <?php else : ?>
        <form method="post" action="<?= $view->url('admin.fetch.start') ?>">
            <?= DropTime\Http\Csrf::field() ?>
            <button type="submit" class="button"><?= $t('fetch.button') ?></button>
        </form>
        <?php if ($fetchStatus !== null) : ?>
            <p class="panel__note"><a href="<?= $view->url('admin.fetch') ?>"><?= $t('fetch.see_log') ?></a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 class="panel__title"><?= $t('admin.upload_title') ?></h2>
    <p class="panel__note"><?= $t('admin.upload_note') ?></p>
    <form method="post" action="<?= $view->url('admin.imports.upload') ?>" enctype="multipart/form-data">
        <?= DropTime\Http\Csrf::field() ?>
        <label class="field">
            <span class="field__label"><?= $t('admin.upload_label') ?></span>
            <input class="field__input" type="file" name="file" accept=".pdf,.htm,.html,application/pdf,text/html" required>
        </label>
        <button type="submit" class="button"><?= $t('admin.upload_button') ?></button>
    </form>
</section>

<?php if ($pendingMerges > 0) : ?>
    <section class="panel panel--highlight">
        <h2 class="panel__title"><?= $t('admin.merge_title') ?></h2>
        <p class="panel__note"><?= $t('admin.merge_note', $pendingMerges) ?></p>
        <a class="button button--small" href="<?= $view->url('admin.merges') ?>"><?= $t('admin.merge_go') ?></a>
    </section>
<?php endif; ?>

<?php if ($unproven !== []) : ?>
    <section class="panel panel--highlight">
        <h2 class="panel__title"><?= $t('admin.evidence_title') ?></h2>
        <p class="panel__note"><?= $t('admin.evidence_note') ?></p>
        <ul>
            <?php foreach ($unproven as $meet) : ?>
                <li><?= $e($meet['name']) ?> <span class="time">(<?= $e($meet['start_date']) ?>)</span></li>
            <?php endforeach; ?>
        </ul>
        <pre class="pre">php bin/capture-source.php --all-meets
php bin/capture-source.php --swimmer-pages "Doe, Jane"</pre>
    </section>
<?php endif; ?>

<section class="panel">
    <h2 class="panel__title"><?= $t('admin.imports_title') ?></h2>
    <?php if ($imports === []) : ?>
        <p class="empty"><?= $t('admin.imports_none') ?></p>
    <?php else : ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th>#</th><th><?= $t('admin.th_file') ?></th><th><?= $t('admin.th_status') ?></th><th><?= $t('admin.th_meet') ?></th><th><?= $t('admin.th_received') ?></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($imports as $row) : ?>
                        <tr>
                            <td><a href="<?= $view->url('admin.imports.show', ['import' => (int) $row['id']]) ?>">#<?= $e($row['id']) ?></a></td>
                            <td class="table__file" title="<?= $e($row['original_name']) ?>"><?= $e($row['original_name']) ?></td>
                            <td><span class="badge badge--<?= $e($row['status']) ?>"><?= $e($statusLabel((string) $row['status'])) ?></span></td>
                            <td><?= $e($row['meet_name'] ?? '—') ?></td>
                            <td class="time"><?= $e(DropTime\Support\Clock::toLocal((string) $row['uploaded_at'])->format('Y-m-d H:i')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 class="panel__title"><?= $t('admin.meets_title') ?></h2>
    <?php if ($meets === []) : ?>
        <p class="empty"><?= $t('common.no_meets') ?></p>
    <?php else : ?>
        <ul class="list">
            <?php foreach ($meets as $meet) : ?>
                <li class="list__item">
                    <span><?= $e($meet['name']) ?></span>
                    <span class="list__meta time"><?= $e($meet['start_date']) ?> ・ <?= $e($meet['course']) ?> ・ <?= $t('admin.meets_events', (int) $meet['event_count']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<nav class="footer-nav">
    <a class="footer-nav__link" href="<?= $view->url('admin.merges') ?>"><?= $t('admin.merges_link') ?></a>
    <a class="footer-nav__link" href="<?= $view->url('home') ?>"><?= $t('admin.to_parent') ?></a>
</nav>
