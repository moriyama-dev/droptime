<?php

declare(strict_types=1);

use DropTime\Domain\EventTitle;
use DropTime\Support\Clock;
use DropTime\Support\Lang;
use DropTime\Support\View;

/**
 * 今日の出番（ホームの上）。お気に入りの選手が今日泳ぐ最初と最後の種目の時刻。
 * 「会場に何時から何時までいればよいか」が 3 秒で分かるように、時刻を大きく、1 日の帯の上に印を並べる。
 *
 * @var list<array<string, mixed>> $todayWindows RacePlanRepository::todayWindows()
 */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

$minutes = static fn (string $wallClock): int => (int) substr($wallClock, 11, 2) * 60 + (int) substr($wallClock, 14, 2);
$nowMinutes = $minutes(Clock::now()->setTimezone(Clock::localZone())->format('Y-m-d H:i:s'));

// 帯の幅は全員ぶんの最初の 1 時間前〜最後の 1 時間後（1 時間刻み）。
$from = 24 * 60;
$to = 0;

foreach ($todayWindows as $window) {
    $from = min($from, $minutes((string) $window['first']['today_at']));
    $to = max($to, $minutes((string) $window['last']['today_at']));
}

$from = max(0, intdiv($from, 60) * 60 - 60);
$to = min(24 * 60, (intdiv($to, 60) + 2) * 60);
$span = max(60, $to - $from);
$x = static fn (int $m): float => round(4 + 92 * ($m - $from) / $span, 2);
?>
<section class="section today-window" aria-labelledby="today-window-title">
    <h2 class="section__title" id="today-window-title"><?= $t('today.title') ?></h2>
    <?php foreach ($todayWindows as $i => $window) : ?>
        <article class="today-card rise">
            <p class="today-card__name"><?= $e($window['swimmer_name']) ?></p>
            <p class="today-card__range">
                <span class="today-card__time time"><?= $e($window['first']['clock']) ?></span>
                <?php if ($window['count'] > 1) : ?>
                    <span class="today-card__dash" aria-hidden="true">〜</span>
                    <span class="sr-only"><?= $t('today.to') ?></span>
                    <span class="today-card__time time"><?= $e($window['last']['clock']) ?></span>
                <?php endif; ?>
                <span class="today-card__count"><?= $t('today.count', (int) $window['count']) ?></span>
            </p>
            <svg class="today-card__bar" viewBox="0 0 100 18" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                <rect class="today-card__track" x="4" y="7" width="92" height="4" rx="2"></rect>
                <rect class="today-card__stay" x="<?= $x($minutes((string) $window['first']['today_at'])) ?>" y="5" width="<?= max(1.5, $x($minutes((string) $window['last']['today_at'])) - $x($minutes((string) $window['first']['today_at']))) ?>" height="8" rx="4"></rect>
                <?php foreach ($window['times'] as $time) : ?>
                    <circle class="today-card__race<?= $time['done'] ? ' today-card__race--done' : '' ?>" cx="<?= $x($minutes($time['at'])) ?>" cy="9" r="2.6"></circle>
                <?php endforeach; ?>
                <?php if ($nowMinutes >= $from && $nowMinutes <= $to) : ?>
                    <line class="today-card__now" x1="<?= $x($nowMinutes) ?>" x2="<?= $x($nowMinutes) ?>" y1="1" y2="17"></line>
                <?php endif; ?>
            </svg>
            <p class="today-card__axis" aria-hidden="true">
                <span class="time"><?= intdiv($from, 60) ?>:00</span>
                <?php if ($nowMinutes >= $from && $nowMinutes <= $to) : ?><span class="today-card__now-label"><?= $t('today.now') ?></span><?php endif; ?>
                <span class="time"><?= intdiv($to, 60) ?>:00</span>
            </p>
            <dl class="today-card__events">
                <div>
                    <dt><?= $t('today.first') ?></dt>
                    <dd><span class="time"><?= $e($window['first']['clock']) ?></span> <?= $e(EventTitle::withNumber($window['first'])) ?></dd>
                </div>
                <?php if ($window['count'] > 1) : ?>
                    <div>
                        <dt><?= $t('today.last') ?></dt>
                        <dd><span class="time"><?= $e($window['last']['clock']) ?></span> <?= $e(EventTitle::withNumber($window['last'])) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
            <p class="today-card__note">
                <?= $e($window['meet']['name']) ?><?php if (!empty($window['meet']['venue'])) : ?> ・ <?= $e($window['meet']['venue']) ?><?php endif; ?>
                <br><?= $window['estimated'] ? $t('today.note_estimated') : $t('today.note_scheduled') ?>
            </p>
        </article>
    <?php endforeach; ?>
</section>
