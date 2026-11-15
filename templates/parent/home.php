<?php

declare(strict_types=1);

use DropTime\Domain\EventTitle;
use DropTime\Domain\SwimTime;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var list<array<string, mixed>> $favoriteSwimmers */
/** @var list<array{meet: array<string, mixed>, entries: list<array<string, mixed>>, is_today: bool, latest: ?array<string, mixed>}> $meetGroups */
/** @var list<array<string, mixed>> $todayWindows */
/** @var string $vapidPublic */
/** @var bool $hasMeets */
/** @var bool $isAdmin */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

// カウントダウンの大きな文字。
$countdown = static function (array $live) use ($t): string {
    return match (true) {
        $live['status'] === 'done' => $t('home.cd_done'),
        $live['status'] === 'running' => $t('home.cd_running'),
        $live['minutes'] <= 5 => $t('home.cd_soon'),
        $live['minutes'] >= 60 => $t('home.cd_hours', intdiv($live['minutes'], 60), $live['minutes'] % 60),
        default => $t('home.cd_minutes', $live['minutes']),
    };
};
?>
<?php if ($todayWindows !== []) : ?>
    <?= $view->partial('parent/_today-window', ['todayWindows' => $todayWindows]) ?>
<?php endif; ?>

<h1 class="page-title"><?= $t('home.title') ?></h1>

<?php if ($favoriteSwimmers === []) : ?>
    <div class="hero">
        <p class="hero__lead"><?= $t('home.hero_lead') ?></p>
        <a class="button" href="<?= $view->url('favorites') ?>"><?= $t('home.hero_cta') ?></a>
        <?php if (!$hasMeets) : ?>
            <p class="hero__note"><?= $t('home.hero_note') ?></p>
        <?php endif; ?>
    </div>
<?php elseif ($meetGroups === []) : ?>
    <p class="empty"><?= $t('home.no_upcoming') ?></p>
<?php else : ?>
    <?php foreach ($meetGroups as $group) : ?>
        <section class="meet-block">
            <h2 class="meet-block__title">
                <a href="<?= $view->url('meets.show', ['meet' => (int) $group['meet']['id']]) ?>"><?= $e($group['meet']['name']) ?></a>
            </h2>
            <?php if ($group['is_today']) : ?>
                <p class="live-status">
                    <span class="live-status__badge"><?= $t('home.live_badge') ?></span>
                    <?php if ($group['latest'] !== null) : ?>
                        <?= $t('home.live_latest', $group['latest']['event_number'], $group['latest']['time']) ?>
                    <?php else : ?>
                        <?= $t('home.live_waiting') ?>
                    <?php endif; ?>
                    <span class="live-status__note"><?= $t('home.live_refresh') ?></span>
                </p>
            <?php endif; ?>
            <p class="meet-block__meta">
                <span class="time"><?= $e($group['meet']['start_date']) ?></span>
                <?php if (!empty($group['meet']['end_date']) && $group['meet']['end_date'] !== $group['meet']['start_date']) : ?>
                    〜 <span class="time"><?= $e($group['meet']['end_date']) ?></span>
                <?php endif; ?>
                <?php if (!empty($group['meet']['venue'])) : ?>
                    ・ <?= $e($group['meet']['venue']) ?>
                <?php endif; ?>
            </p>
            <ol class="race-list">
                <?php foreach ($group['entries'] as $entry) : ?>
                    <?php $live = $entry['live'] ?? null; $showCountdown = $live !== null && $live['is_today']; ?>
                    <li>
                        <a class="race-card<?= $showCountdown ? ' race-card--live race-card--' . $e($live['status']) : '' ?>" href="<?= $view->url('meets.event', ['meet' => (int) $group['meet']['id'], 'event' => (int) $entry['event_id']]) ?>">
                            <?php if ($showCountdown) : ?>
                                <?php
                                $done = (int) $live['session_done'];
                                $percent = $live['status'] === 'done' ? 100 : max(4, (int) round(100 * $done / ($done + (int) $live['events_before'] + 1)));
                                ?>
                                <div class="countdown">
                                    <span class="countdown__big"><?= $countdown($live) ?></span>
                                    <?php if ($live['status'] === 'upcoming') : ?>
                                        <span class="countdown__sub">
                                            <?= $t('home.cd_at', $live['time']) ?> ・
                                            <?= $live['events_before'] === 0 ? $t('home.cd_next') : $t('home.cd_events_before', (int) $live['events_before']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <svg class="countdown__bar" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                        <rect class="countdown__track" x="0" y="0" width="100" height="6" rx="3"></rect>
                                        <rect class="countdown__fill" x="0" y="0" width="<?= $percent ?>" height="6" rx="3"></rect>
                                    </svg>
                                </div>
                            <?php endif; ?>
                            <div class="race-card__when">
                                <?php if ($entry['schedule'] !== null) : ?>
                                    <span class="race-card__time time"><?= $e($entry['schedule']['time']) ?></span>
                                    <?php if ($live !== null && !$live['is_today']) : ?>
                                        <span class="race-card__tag"><?= $e(ltrim(substr((string) $live['date'], 5, 2), '0') . '/' . ltrim(substr((string) $live['date'], 8, 2), '0')) ?></span>
                                    <?php else : ?>
                                        <span class="race-card__tag"><?= $entry['schedule']['estimated'] ? $t('common.estimated') : $t('common.scheduled') ?></span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="race-card__time time">--:--</span>
                                <?php endif; ?>
                            </div>
                            <div class="race-card__body">
                                <span class="race-card__swimmer"><?= $e($entry['swimmer_name']) ?></span>
                                <span class="race-card__event"><?= $e(EventTitle::withNumber($entry)) ?></span>
                                <span class="race-card__seed">
                                    <?php if ($entry['seed_is_ntime']) : ?>
                                        <?= $t('common.seed_none') ?>
                                    <?php elseif ($entry['seed_time_cs'] !== null) : ?>
                                        <?= $t('common.seed') ?> <span class="time"><?= $e(SwimTime::format((int) $entry['seed_time_cs'])) ?></span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($live !== null && $live['status'] !== 'done') : ?>
                                    <?php if ((int) $live['measured'] > 0) : ?>
                                        <span class="race-card__basis race-card__basis--measured"><?= $t('home.basis_measured', (int) $live['measured']) ?></span>
                                    <?php else : ?>
                                        <span class="race-card__basis"><?= $t('home.basis_formula') ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="race-card__place">
                                <?php if ($entry['heat'] !== null) : ?>
                                    <span class="race-card__heat"><span class="race-card__label"><?= $t('common.heat') ?></span><span class="time"><?= $e($entry['heat']) ?></span></span>
                                <?php endif; ?>
                                <?php if ($entry['lane'] !== null) : ?>
                                    <span class="race-card__lane"><span class="race-card__label"><?= $t('common.lane') ?></span><span class="time"><?= $e($entry['lane']) ?></span></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php $planned = array_values(array_filter($group['entries'], static fn (array $entry): bool => ($entry['live'] ?? null) !== null)); ?>
            <?php if ($planned !== []) : ?>
                <p class="footnote"><?= $t($planned[0]['live']['venue_known'] ? 'home.basis_note' : 'home.basis_no_venue') ?></p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <p class="footnote"><?= $t('home.footnote') ?></p>
<?php endif; ?>

<?= $view->partial('parent/_app-setup', ['vapidPublic' => $vapidPublic]) ?>

<nav class="footer-nav">
    <a class="footer-nav__link" href="<?= $view->url('sources') ?>"><?= $t('src.title') ?></a>
    <?= $view->partial('_feedback') ?>
    <?php if ($isAdmin) : ?>
        <a class="footer-nav__link" href="<?= $view->url('admin.home') ?>"><?= $t('common.admin_link') ?></a>
    <?php endif; ?>
</nav>
