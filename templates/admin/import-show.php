<?php

declare(strict_types=1);

use DropTime\Support\Clock;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var array<string, mixed> $import */
/** @var array<string, mixed> $summary */
/** @var array{type: string, message: string}|null $flash */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

$status = (string) $import['status'];
$counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
$meet = is_array($summary['meet'] ?? null) ? $summary['meet'] : [];
$preview = is_array($summary['preview'] ?? null) ? $summary['preview'] : [];
$unrecognized = is_array($summary['unrecognized'] ?? null) ? $summary['unrecognized'] : [];
$warnings = is_array($summary['warnings'] ?? null) ? $summary['warnings'] : [];
$id = (int) $import['id'];
?>
<p class="breadcrumb"><a href="<?= $view->url('admin.home') ?>"><?= $t('imp.back') ?></a></p>
<h1 class="page-title"><?= $t('imp.title', $id) ?></h1>

<?php if ($flash !== null) : ?>
    <p class="alert alert--<?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></p>
<?php endif; ?>

<section class="panel">
    <dl class="meta">
        <div><dt><?= $t('imp.file') ?></dt><dd class="table__file"><?= $e($import['original_name']) ?></dd></div>
        <div><dt><?= $t('imp.size') ?></dt><dd class="time"><?= $t('imp.bytes', number_format((int) $import['byte_size'])) ?></dd></div>
        <div><dt><?= $t('imp.type') ?></dt><dd><?= $e($import['mime']) ?></dd></div>
        <div><dt><?= $t('imp.received') ?></dt><dd class="time"><?= $e(Clock::toLocal((string) $import['uploaded_at'])->format('Y-m-d H:i')) ?></dd></div>
        <div><dt><?= $t('imp.status') ?></dt><dd><span class="badge badge--<?= $e($status) ?>"><?= $e($status) ?></span></dd></div>
        <?php if ($import['parser'] !== null) : ?>
            <div><dt><?= $t('imp.parser') ?></dt><dd><?= $e($summary['parser_label'] ?? $import['parser']) ?></dd></div>
        <?php endif; ?>
    </dl>
</section>

<?php if ($status === 'failed') : ?>
    <p class="alert alert--error" role="alert"><?= $e($summary['error'] ?? Lang::t('imp.failed_default')) ?></p>
<?php endif; ?>

<?php if (isset($summary['duplicate_of'])) : ?>
    <p class="alert alert--error" role="alert">
        <?= sprintf(
            $t('imp.duplicate', '%s'),
            '<a href="' . $view->url('admin.imports.show', ['import' => (int) $summary['duplicate_of']]) . '">#' . $e($summary['duplicate_of']) . '</a>'
        ) ?>
    </p>
<?php endif; ?>

<?php foreach ($warnings as $warning) : ?>
    <p class="alert" role="alert"><?= $e($warning) ?></p>
<?php endforeach; ?>

<?php if ($counts !== []) : ?>
    <section class="panel">
        <h2 class="panel__title"><?= $t('imp.counts_title') ?></h2>
        <div class="stat-grid">
            <div class="stat"><span class="stat__value time"><?= $e($counts['events'] ?? 0) ?></span><span class="stat__label"><?= $t('imp.c_events') ?></span></div>
            <div class="stat"><span class="stat__value time"><?= $e($counts['entries'] ?? 0) ?></span><span class="stat__label"><?= $t('imp.c_entries') ?></span></div>
            <div class="stat"><span class="stat__value time"><?= $e($counts['results'] ?? 0) ?></span><span class="stat__label"><?= $t('imp.c_results') ?></span></div>
            <div class="stat"><span class="stat__value time"><?= $e($counts['splits'] ?? 0) ?></span><span class="stat__label"><?= $t('imp.c_splits') ?></span></div>
            <div class="stat"><span class="stat__value time"><?= $e($counts['swimmers'] ?? 0) ?></span><span class="stat__label"><?= $t('imp.c_swimmers') ?></span></div>
            <div class="stat stat--warn"><span class="stat__value time"><?= $e($summary['unrecognized_total'] ?? 0) ?></span><span class="stat__label"><?= $t('imp.c_unrec') ?></span></div>
        </div>
    </section>
<?php endif; ?>

<?php if ($status === 'parsed') : ?>
    <section class="panel">
        <h2 class="panel__title"><?= $t('imp.commit_title') ?></h2>
        <?php if (($counts['events'] ?? 0) === 0) : ?>
            <p class="panel__note"><?= $t('imp.commit_disabled') ?></p>
        <?php else : ?>
            <p class="panel__note"><?= $t('imp.commit_note') ?></p>
            <form method="post" action="<?= $view->url('admin.imports.commit', ['import' => $id]) ?>">
                <?= DropTime\Http\Csrf::field() ?>
                <label class="field">
                    <span class="field__label"><?= $t('imp.f_name') ?></span>
                    <input class="field__input" type="text" name="meet_name" value="<?= $e($meet['name'] ?? '') ?>" required>
                </label>
                <div class="field-row">
                    <label class="field">
                        <span class="field__label"><?= $t('imp.f_start') ?></span>
                        <input class="field__input" type="date" name="start_date" value="<?= $e($meet['start_date'] ?? '') ?>" required>
                    </label>
                    <label class="field">
                        <span class="field__label"><?= $t('imp.f_end') ?></span>
                        <input class="field__input" type="date" name="end_date" value="<?= $e($meet['end_date'] ?? '') ?>">
                    </label>
                </div>
                <label class="field">
                    <span class="field__label"><?= $t('imp.f_course') ?></span>
                    <select class="field__input" name="course" required>
                        <?php foreach (DropTime\Domain\Course::cases() as $course) : ?>
                            <option value="<?= $e($course->value) ?>" <?= ($meet['course'] ?? '') === $course->value ? 'selected' : '' ?>>
                                <?= $e($course->value) ?> — <?= $e($course->label()) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="field-row">
                    <label class="field">
                        <span class="field__label"><?= $t('imp.f_host') ?></span>
                        <input class="field__input" type="text" name="host" value="<?= $e($meet['host'] ?? '') ?>">
                    </label>
                    <label class="field">
                        <span class="field__label"><?= $t('imp.f_venue') ?></span>
                        <input class="field__input" type="text" name="venue" value="<?= $e($meet['venue'] ?? '') ?>">
                    </label>
                </div>
                <button type="submit" class="button"><?= $t('imp.commit_button') ?></button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($preview !== []) : ?>
    <section class="panel">
        <h2 class="panel__title"><?= $t('imp.preview_title', count($preview)) ?></h2>
        <div class="table-scroll">
            <table class="table table--mono">
                <thead><tr><th><?= $t('imp.th_line') ?></th><th><?= $t('imp.th_content') ?></th><th><?= $t('imp.th_kind') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($preview as $line) : ?>
                        <tr>
                            <td class="time"><?= $e($line['line'] ?? '') ?></td>
                            <td class="pre"><?= $e($line['text'] ?? '') ?></td>
                            <td><?= $e($line['kind'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if (($summary['unrecognized_total'] ?? 0) > 0) : ?>
    <section class="panel">
        <h2 class="panel__title"><?= $t('imp.unrec_title', (string) $summary['unrecognized_total']) ?><?php
            if (count($unrecognized) < ($summary['unrecognized_total'] ?? 0)) {
                echo $t('imp.unrec_shown', count($unrecognized));
            } ?></h2>
        <p class="panel__note"><?= $t('imp.unrec_note') ?></p>
        <div class="table-scroll">
            <table class="table table--mono">
                <tbody>
                    <?php foreach ($unrecognized as $line) : ?>
                        <tr>
                            <td class="time"><?= $e($line['line'] ?? '') ?></td>
                            <td class="pre"><?= $e($line['text'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($status !== 'committed' && $status !== 'discarded') : ?>
    <section class="panel">
        <h2 class="panel__title"><?= $t('imp.actions') ?></h2>
        <div class="button-row">
            <form method="post" action="<?= $view->url('admin.imports.reparse', ['import' => $id]) ?>">
                <?= DropTime\Http\Csrf::field() ?>
                <button type="submit" class="button button--quiet"><?= $t('imp.reparse') ?></button>
            </form>
            <form method="post" action="<?= $view->url('admin.imports.discard', ['import' => $id]) ?>">
                <?= DropTime\Http\Csrf::field() ?>
                <button type="submit" class="button button--danger"><?= $t('imp.discard') ?></button>
            </form>
        </div>
    </section>
<?php endif; ?>
