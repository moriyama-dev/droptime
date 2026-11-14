<?php

declare(strict_types=1);

use DropTime\Domain\SwimTime;
use DropTime\Support\Lang;
use DropTime\Support\View;

/**
 * 1 つの泳ぎと、その 1 つ前の比較のカード（LatestComparison の races の 1 件）。
 * 成績タブの ① と、大会当日のホームの「この大会の泳ぎ」で使う。
 */
/** @var array<string, mixed> $race */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<?php $swim = $race['swim']; $previous = $race['previous']; ?>
<article class="cmp-card rise<?= $race['highlight'] ? ' cmp-card--pb' : '' ?>">
    <header class="rank-card__head">
        <h3 class="rank-card__event"><?= $e($race['label']) ?></h3>
        <?php if ($swim['is_pb'] && $previous !== null && !$race['cross_course']) : ?>
            <span class="fact fact--pb">PB</span>
        <?php elseif ($previous === null || $race['cross_course']) : ?>
            <span class="fact fact--quiet"><?= $t('cmp.first') ?></span>
        <?php endif; ?>
    </header>

    <div class="cmp-row">
        <span class="cmp-row__label"><?= $t('cmp.now') ?></span>
        <span class="cmp-row__bar"><?= $race['now_bar'] ?></span>
        <span class="cmp-row__time time"><?= $e(SwimTime::format((int) $swim['time_cs'])) ?></span>
    </div>

    <?php if ($previous !== null) : ?>
        <div class="cmp-row cmp-row--prev is-prev">
            <span class="cmp-row__label"><?= $race['cross_course'] ? $t('cmp.prev_course', (string) $previous['course']) : $t('cmp.prev') ?></span>
            <span class="cmp-row__bar"><?= $race['prev_bar'] ?></span>
            <span class="cmp-row__time time"><?= $e(SwimTime::format((int) $previous['time_cs'])) ?></span>
        </div>
        <p class="cmp-card__note is-prev">
            <?php if ($race['cross_course']) : ?>
                <?php if ($race['highlight']) : ?>
                    <strong class="cmp-card__gain time"><?= $t('cmp.faster_long', SwimTime::formatGap((int) $previous['time_cs'] - (int) $swim['time_cs'])) ?></strong>
                <?php else : ?>
                    <?= $t('cmp.other_course') ?>
                <?php endif; ?>
            <?php elseif ($swim['delta_cs'] < 0) : ?>
                <strong class="cmp-card__gain time"><?= $t('cmp.faster', SwimTime::formatGap(-(int) $swim['delta_cs'])) ?></strong>
            <?php elseif ($swim['delta_cs'] === 0) : ?>
                <?= $t('cmp.same') ?>
            <?php else : ?>
                <span class="time"><?= $t('cmp.slower', SwimTime::formatDelta((int) $swim['delta_cs'])) ?></span>
            <?php endif; ?>
            <span class="cmp-card__when"><?= $t('cmp.prev_when', $previous['date'], $previous['meet_name']) ?></span>
        </p>
    <?php endif; ?>
</article>
