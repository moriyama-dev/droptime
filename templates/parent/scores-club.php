<?php

declare(strict_types=1);

use DropTime\Domain\SwimTime;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var array<string, mixed> $swimmer */
/** @var list<array<string, mixed>> $switcher */
/** @var array<string, mixed>|null $comparison */
/** @var array{younger: int, older: int} $clubRange */
/** @var int $maxAgeSpread */
/** @var bool $allGenders */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

/** 推定した生年の範囲を「2017」「2016–2017」の形にする。 */
$years = static function (?array $range): string {
    if ($range === null) {
        return '–';
    }

    return $range[0] === $range[1] ? (string) $range[0] : $range[0] . '–' . $range[1];
};

$base = $view->url('scores.club', ['swimmer' => (int) $swimmer['id']]);
$link = static function (array $range, bool $all) use ($base): string {
    $query = http_build_query(array_filter([
        'club_younger' => $range['younger'],
        'club_older' => $range['older'],
        'gender' => $all ? 'all' : null,
    ]));

    return $base . ($query === '' ? '' : '?' . $query);
};
?>
<h1 class="page-title"><?= $t('nav.scores') ?></h1>

<?= $view->partial('parent/_scores-nav', ['swimmer' => $swimmer, 'switcher' => $switcher, 'active' => 'club']) ?>

<div class="scores-head">
    <h2 class="scores-head__name"><?= $e($swimmer['full_name']) ?></h2>
</div>

<?php if ($comparison === null) : ?>
    <p class="empty"><?= $t('club.no_team') ?></p>
<?php elseif ($comparison['child_years'] === null) : ?>
    <p class="empty"><?= $t('club.no_age') ?></p>
<?php else : ?>
    <p class="club-lead">
        <?= $t('club.lead', (string) $swimmer['team_name'], $years([$comparison['child_years'][0] - $clubRange['older'], $comparison['child_years'][1] + $clubRange['younger']]), count($comparison['peers']) - 1) ?>
    </p>

    <?= $view->partial('parent/_age-range', [
        'title' => DropTime\Support\Lang::t('rankx.club_range'),
        'younger' => $clubRange['younger'],
        'older' => $clubRange['older'],
        'max' => $maxAgeSpread,
        'url' => static fn (int $y, int $o): string => $link(['younger' => $y, 'older' => $o], $allGenders),
        'class' => '',
    ]) ?>

    <div class="filter-row" role="group" aria-label="<?= $t('club.filters') ?>">
        <a class="filter-chip<?= !$allGenders ? ' filter-chip--active' : '' ?>" href="<?= $e($link($clubRange, false)) ?>"><?= $t('club.same_gender') ?></a>
        <a class="filter-chip<?= $allGenders ? ' filter-chip--active' : '' ?>" href="<?= $e($link($clubRange, true)) ?>"><?= $t('club.all_genders') ?></a>
    </div>

    <?php if ($comparison['shapes'] === []) : ?>
        <p class="empty"><?= $t('scores.none') ?></p>
    <?php endif; ?>

    <?php foreach ($comparison['shapes'] as $shape) : ?>
        <?php $medal = $shape['child_rank'] !== null && $shape['child_rank'] <= 3 && $shape['count'] > 1; ?>
        <article class="club-card rise<?= $medal ? ' club-card--medal' : '' ?>">
            <header class="rank-card__head">
                <h3 class="rank-card__event"><?= $e($shape['label']) ?></h3>
                <span class="club-card__place"><?= $shape['count'] > 1 ? $t('rank.place_of', (int) $shape['child_rank'], (int) $shape['count']) : $t('club.only_one') ?></span>
            </header>
            <?php if (($shape['gap_above_cs'] ?? null) !== null) : ?>
                <p class="fact fact--target"><?= $t('club.gap_above', SwimTime::formatGap((int) $shape['gap_above_cs'])) ?></p>
            <?php elseif ($shape['count'] > 1 && $shape['child_rank'] === 1) : ?>
                <p class="fact fact--lead"><?= $t('club.top') ?></p>
            <?php endif; ?>

            <ol class="club-table">
                <?php foreach ($shape['rows'] as $row) : ?>
                    <li class="club-table__row<?= $row['is_child'] ? ' club-table__row--me' : '' ?>">
                        <span class="club-table__rank"><?= $e($row['rank']) ?></span>
                        <span class="club-table__name">
                            <?php if ($row['is_child']) : ?>
                                <?= $e($row['name']) ?>
                            <?php else : ?>
                                <a href="<?= $view->url('swimmers.show', ['swimmer' => (int) $row['swimmer_id']]) ?>"><?= $e($row['name']) ?></a>
                            <?php endif; ?>
                            <span class="club-table__meta"><?= $t('club.born', $years($row['years'])) ?> · <?= $e($row['date']) ?></span>
                        </span>
                        <span class="club-table__time time"><?= $e(SwimTime::format((int) $row['time_cs'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </article>
    <?php endforeach; ?>

    <?php if ($comparison['other_shapes'] !== []) : ?>
        <section class="section">
            <h2 class="section__title"><?= $t('club.others_title') ?></h2>
            <p class="footnote"><?= $t('club.others_body') ?></p>
            <ul class="tag-list">
                <?php foreach ($comparison['other_shapes'] as $other) : ?>
                    <li class="tag"><?= $e($other['label']) ?> <span class="tag__count"><?= $t('club.people', (int) $other['count']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <p class="footnote"><?= $t('club.footnote') ?></p>
<?php endif; ?>
