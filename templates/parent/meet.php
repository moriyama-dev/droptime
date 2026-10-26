<?php

declare(strict_types=1);

use DropTime\Domain\EventTitle;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var array<string, mixed> $meet */
/** @var list<array<string, mixed>> $program */
/** @var array<int, array{time: string, estimated: bool}> $estimates */
/** @var array<int, true> $favoriteEventIds */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<p class="breadcrumb"><a href="<?= $view->url('meets') ?>">← <?= $t('meets.title') ?></a></p>
<h1 class="page-title"><?= $e($meet['name']) ?></h1>
<p class="meet-block__meta">
    <span class="time"><?= $e($meet['start_date']) ?></span>
    <?php if (!empty($meet['end_date']) && $meet['end_date'] !== $meet['start_date']) : ?>
        〜 <span class="time"><?= $e($meet['end_date']) ?></span>
    <?php endif; ?>
    ・ <?= $e($meet['course']) ?>
    <?php if (!empty($meet['venue'])) : ?>
        ・ <?= $e($meet['venue']) ?>
    <?php endif; ?>
</p>

<?php if ($program === []) : ?>
    <p class="empty"><?= $t('meet.none') ?></p>
<?php else : ?>
    <?php if ($favoriteEventIds !== []) : ?>
        <p class="footnote"><?= $t('meet.fav_note') ?></p>
    <?php endif; ?>
    <ol class="program-list">
        <?php foreach ($program as $event) : ?>
            <?php $schedule = $estimates[(int) $event['id']] ?? null; ?>
            <li>
                <a class="program-row<?= isset($favoriteEventIds[(int) $event['id']]) ? ' program-row--favorite' : '' ?>"
                   href="<?= $view->url('meets.event', ['meet' => (int) $meet['id'], 'event' => (int) $event['id']]) ?>">
                    <span class="program-row__number time"><?= $e($event['event_number']) ?></span>
                    <span class="program-row__main">
                        <span class="program-row__title">
                            <?php if (isset($favoriteEventIds[(int) $event['id']])) : ?><span class="program-row__star" aria-hidden="true">★</span><?php endif; ?>
                            <?= $e(EventTitle::of($event)) ?>
                            <?php if (EventTitle::roundLabel((string) $event['round']) !== '') : ?>
                                <span class="program-row__round"><?= $e(EventTitle::roundLabel((string) $event['round'])) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="program-row__meta">
                            <?php if ((int) $event['heat_count'] > 0) : ?>
                                <?= $t('meet.heats_entries', (int) $event['heat_count'], (int) $event['entry_count']) ?>
                            <?php endif; ?>
                            <?php if ((int) $event['result_count'] > 0) : ?>
                                <?= $t('meet.results_suffix') ?>
                            <?php endif; ?>
                        </span>
                    </span>
                    <?php if ($schedule !== null) : ?>
                        <span class="program-row__when">
                            <span class="time"><?= $e($schedule['time']) ?></span>
                            <span class="race-card__tag"><?= $schedule['estimated'] ? $t('common.estimated') : $t('common.scheduled') ?></span>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ol>
    <p class="footnote"><?= $t('meet.footnote') ?></p>
<?php endif; ?>

<?php if (!empty($meet['source_file'])) : ?>
    <p class="footnote footnote--source"><?= $t('meet.source') ?>:
        <?php if (str_starts_with((string) $meet['source_file'], 'http')) : ?>
            <a href="<?= $e($meet['source_file']) ?>" rel="noreferrer"><?= $e($meet['source_file']) ?></a>
        <?php else : ?>
            <?= $e($meet['source_file']) ?>
        <?php endif; ?>
        · <a href="<?= $view->url('sources') ?>"><?= $t('src.proof_link') ?></a>
    </p>
<?php endif; ?>
