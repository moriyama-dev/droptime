<?php

declare(strict_types=1);

use DropTime\Domain\SwimTime;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var array<string, mixed> $swimmer */
/** @var list<array<string, mixed>> $switcher */
/** @var array<string, mixed> $highlights */
/** @var array<string, mixed>|null $latest */
/** @var array{drops: list<array<string, mixed>>, charts: list<array<string, mixed>>, singles: list<array<string, mixed>>} $history */
/** @var list<array<string, mixed>> $ranking */
/** @var int $clubPeers */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

/*
 * 上から ① いちばん新しい記録（1 つ前との比較つき）② 種目ごとのこれまで
 * ③ 同じ年齢・同じクラブでの順位、の順に並べる。
 *
 * 比較と順位のカテゴリの ON / OFF は、チェックボックスと CSS の :has() だけで
 * 切り替える（スイッチに JavaScript は使わない）。隠した要素は display: none に
 * なり、もう一度見せたときにアニメーションが最初から再生される。
 */
?>
<h1 class="page-title"><?= $t('nav.scores') ?></h1>

<?= $view->partial('parent/_scores-nav', ['swimmer' => $swimmer, 'switcher' => $switcher, 'active' => 'meets']) ?>

<div class="scores-head">
    <h2 class="scores-head__name"><?= $e($swimmer['full_name']) ?></h2>
    <a class="section-head__link" href="<?= $view->url('swimmers.show', ['swimmer' => (int) $swimmer['id']]) ?>"><?= $t('scores.history_link') ?></a>
</div>

<?php if ($history['charts'] === [] && $history['singles'] === []) : ?>
    <p class="empty"><?= $t('scores.none') ?></p>
<?php else : ?>
<div class="scores-page">

    <!-- ── ① いちばん新しい記録と、その 1 つ前 ─────────────────────── -->
    <?php if ($latest !== null) : ?>
        <section class="section" aria-labelledby="latest-title">
            <div class="section-head">
                <h2 class="section__title" id="latest-title"><?= $t('scores.latest') ?></h2>
                <label class="switch">
                    <input class="switch__input" type="checkbox" id="show-prev" checked>
                    <span class="switch__track" aria-hidden="true"></span>
                    <span class="switch__label"><?= $t('cmp.toggle') ?></span>
                </label>
            </div>
            <p class="scores-meet">
                <a href="<?= $view->url('meets.show', ['meet' => (int) $latest['meet_id']]) ?>"><?= $e($latest['meet_name']) ?></a>
                <span class="section-head__meta time"><?= $e($latest['date']) ?></span>
            </p>

            <?php foreach ($latest['races'] as $race) : ?>
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
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- ── ② 種目ごとのこれまで ─────────────────────────────────── -->
    <section class="section" aria-labelledby="progress">
        <h2 class="section__title" id="progress"><?= $t('scores.progress') ?></h2>

        <ul class="stat-row stat-row--three rise">
            <li class="stat">
                <span class="stat__value time"><?= $e($highlights['races']) ?></span>
                <span class="stat__label"><?= $t('scores.races') ?></span>
            </li>
            <li class="stat">
                <span class="stat__value time stat__value--good"><?= $e($highlights['pb_count']) ?></span>
                <span class="stat__label"><?= $t('scores.pb_count') ?></span>
            </li>
            <li class="stat">
                <span class="stat__value time stat__value--good"><?= $t('scores.seconds', SwimTime::formatDelta(-(int) $highlights['total_drop_cs'])) ?></span>
                <span class="stat__label"><?= $t('scores.total_drop') ?></span>
            </li>
        </ul>

        <?php if ($history['drops'] !== []) : ?>
            <div class="drop-chart rise">
                <h3 class="shape__title"><?= $t('hist.drops_title') ?></h3>
                <ol class="drop-list">
                    <?php foreach ($history['drops'] as $drop) : ?>
                        <li class="drop-list__row">
                            <span class="drop-list__label"><?= $e($drop['label']) ?></span>
                            <span class="drop-list__bar"><?= $drop['bar'] ?></span>
                            <span class="drop-list__value time"><?= $t('scores.seconds', SwimTime::formatDelta(-(int) $drop['drop_cs'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="footnote"><?= $t('hist.drops_note') ?></p>
            </div>
        <?php endif; ?>

        <?php foreach ($history['charts'] as $item) : ?>
            <?php $shape = $item['shape']; ?>
            <article class="shape rise">
                <div class="section-head">
                    <h3 class="shape__title"><?= $e($shape['label']) ?></h3>
                    <span class="pb-chip">
                        <span class="pb-chip__label"><?= $t('prog.best') ?></span>
                        <span class="time pb-chip__time"><?= $e(SwimTime::format((int) $shape['best']['time_cs'])) ?></span>
                    </span>
                </div>

                <?php if ($shape['total_drop_cs'] > 0) : ?>
                    <p class="shape__drop time"><?= $t('prog.total_drop', SwimTime::formatDelta(-(int) $shape['total_drop_cs'])) ?></p>
                <?php endif; ?>

                <div class="chart-frame"><?= $item['svg'] ?></div>

                <ul class="swim-log">
                    <?php foreach (array_reverse($shape['swims']) as $swim) : ?>
                        <li class="swim-log__row<?= $swim['is_pb'] ? ' swim-log__row--pb' : '' ?>">
                            <span class="swim-log__date time"><?= $e($swim['date']) ?></span>
                            <span class="swim-log__meet"><?= $e($swim['meet_name']) ?></span>
                            <span class="swim-log__end">
                                <span class="swim-log__time time"><?= $e(SwimTime::format((int) $swim['time_cs'])) ?></span>
                                <?php if ($swim['delta_cs'] !== null) : ?>
                                    <span class="swim-log__delta time <?= $swim['delta_cs'] < 0 ? 'swim-log__delta--drop' : 'swim-log__delta--add' ?>">
                                        <?= $e(SwimTime::formatDelta((int) $swim['delta_cs'])) ?>
                                    </span>
                                <?php else : ?>
                                    <span class="swim-log__delta time"><?= $t('prog.first') ?></span>
                                <?php endif; ?>
                                <?php if ($swim['is_pb']) : ?>
                                    <span class="swim-log__pb" title="<?= $t('prog.pb') ?>">PB</span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endforeach; ?>

        <?php if ($history['singles'] !== []) : ?>
            <div class="singles rise">
                <h3 class="shape__title"><?= $t('hist.singles_title') ?></h3>
                <ul class="singles__list">
                    <?php foreach ($history['singles'] as $shape) : ?>
                        <?php $swim = $shape['swims'][0]; ?>
                        <li class="singles__row">
                            <span class="singles__event"><?= $e($shape['label']) ?></span>
                            <span class="singles__time time"><?= $e(SwimTime::format((int) $swim['time_cs'])) ?></span>
                            <span class="singles__date time"><?= $e($swim['date']) ?> · <?= $e($swim['meet_name']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="footnote"><?= $t('hist.singles_note') ?></p>
            </div>
        <?php endif; ?>

        <?php if ($history['charts'] !== []) : ?>
            <p class="footnote"><?= $t('prog.footnote') ?></p>
            <p class="footnote"><?= $t('prog.peer_note') ?></p>
        <?php endif; ?>
    </section>

    <!-- ── ③ 同じ年齢・同じクラブでの順位 ─────────────────────────── -->
    <?php if ($ranking !== []) : ?>
        <section class="section" aria-labelledby="ranking-title">
            <h2 class="section__title" id="ranking-title"><?= $t('rankx.title') ?></h2>

            <div class="switch-row" role="group" aria-label="<?= $t('rankx.categories') ?>">
                <label class="switch">
                    <input class="switch__input" type="checkbox" id="show-age" checked>
                    <span class="switch__track" aria-hidden="true"></span>
                    <span class="switch__label"><?= $t('rankx.age') ?></span>
                </label>
                <label class="switch">
                    <input class="switch__input" type="checkbox" id="show-club" checked>
                    <span class="switch__track" aria-hidden="true"></span>
                    <span class="switch__label"><?= $t('rankx.club') ?></span>
                </label>
            </div>

            <?php if ($highlights['best_peer'] !== null) : ?>
                <p class="rank-best is-age rise">
                    <span class="rank-best__label"><?= $t('scores.best_peer') ?></span>
                    <span class="rank-best__value time"><?= $t('rank.place_of', (int) $highlights['best_peer']['rank'], (int) $highlights['best_peer']['count']) ?></span>
                    <span class="rank-best__event"><?= $e($highlights['best_peer']['label']) ?></span>
                </p>
            <?php endif; ?>

            <div class="rank-table rise">
                <div class="rank-table__row rank-table__row--head" aria-hidden="true">
                    <span class="rank-table__event"></span>
                    <span class="rank-table__cell is-age"><?= $t('rankx.age') ?></span>
                    <span class="rank-table__cell is-club"><?= $t('rankx.club') ?></span>
                </div>

                <?php foreach ($ranking as $row) : ?>
                    <div class="rank-table__row">
                        <span class="rank-table__event"><?= $e($row['label']) ?></span>

                        <span class="rank-table__cell is-age">
                            <?php if ($row['age'] !== null) : ?>
                                <span class="rank-table__place time"><?= $t('rank.place_of', $row['age']['rank'], $row['age']['count']) ?></span>
                                <?= $row['age']['bar'] ?>
                                <span class="rank-table__sub">
                                    <?= $row['age']['is_peer'] ? $t('rank.peer_of', (int) $row['age']['age']) : $t('rankx.overall') ?>
                                    <?php if ($row['age']['gap_ahead_cs'] !== null) : ?>
                                        · <?= $t('rank.gap_ahead', SwimTime::formatGap((int) $row['age']['gap_ahead_cs'])) ?>
                                    <?php endif; ?>
                                </span>
                            <?php else : ?>
                                <span class="rank-table__sub"><?= $t('rank.none') ?></span>
                            <?php endif; ?>
                        </span>

                        <span class="rank-table__cell is-club">
                            <?php if ($row['club'] !== null) : ?>
                                <span class="rank-table__place time"><?= $t('rank.place_of', $row['club']['rank'], $row['club']['count']) ?></span>
                                <?= $row['club']['bar'] ?>
                                <span class="rank-table__sub">
                                    <?php if ($row['club']['gap_ahead_cs'] !== null) : ?>
                                        <?= $t('club.gap_above', SwimTime::formatGap((int) $row['club']['gap_ahead_cs'])) ?>
                                    <?php elseif ($row['club']['rank'] === 1) : ?>
                                        <?= $t('club.top') ?>
                                    <?php endif; ?>
                                </span>
                            <?php else : ?>
                                <span class="rank-table__sub"><?= $t('rankx.club_none') ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>

                <p class="rank-table__off"><?= $t('rankx.all_off') ?></p>
            </div>

            <p class="footnote is-age"><?= $t('rankx.age_note') ?></p>
            <p class="footnote is-club">
                <?= $t('rankx.club_note', $clubPeers) ?>
                <a href="<?= $view->url('scores.club', ['swimmer' => (int) $swimmer['id']]) ?>"><?= $t('rankx.club_link') ?></a>
            </p>
        </section>
    <?php endif; ?>
</div>
<?php endif; ?>
