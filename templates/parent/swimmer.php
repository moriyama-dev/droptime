<?php

declare(strict_types=1);

use DropTime\Domain\EventTitle;
use DropTime\Domain\SwimTime;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var array<string, mixed> $swimmer */
/** @var list<array{meet: array<string, mixed>, results: list<array<string, mixed>>}> $meetGroups */
/** @var list<array<string, mixed>> $shapes */
/** @var bool $isFavorite */
/** @var array{type: string, message: string}|null $flash */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<p class="breadcrumb"><a href="<?= $view->url('favorites') ?>">← <?= $t('fav.title') ?></a></p>
<div class="swimmer-head">
    <div>
        <h1 class="page-title swimmer-head__name"><?= $e($swimmer['full_name']) ?></h1>
        <p class="meet-block__meta">
            <?php if ($swimmer['birth_year'] !== null) : ?><?= $t('common.born', (int) $swimmer['birth_year']) ?><?php endif; ?>
            <?php if ($swimmer['team_name'] !== null) : ?> ・ <?= $e($swimmer['team_name']) ?><?php endif; ?>
        </p>
    </div>
    <form method="post" action="<?= $view->url($isFavorite ? 'favorites.remove' : 'favorites.add') ?>">
        <?= DropTime\Http\Csrf::field() ?>
        <input type="hidden" name="swimmer_id" value="<?= $e($swimmer['id']) ?>">
        <input type="hidden" name="back" value="/swimmers/<?= $e($swimmer['id']) ?>">
        <button type="submit" class="button button--small<?= $isFavorite ? ' button--quiet' : '' ?>">
            <?= $isFavorite ? $t('sw.fav_on') : $t('sw.fav_off') ?>
        </button>
    </form>
</div>

<?php if ($flash !== null) : ?>
    <p class="alert alert--<?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></p>
<?php endif; ?>

<?php if ($shapes !== []) : ?>
    <section class="section">
        <div class="section-head">
            <h2 class="section__title"><?= $t('sw.pb') ?></h2>
            <a class="section-head__link" href="<?= $view->url('scores.show', ['swimmer' => (int) $swimmer['id']]) ?>"><?= $t('sw.progress_link') ?></a>
        </div>
        <ul class="pb-list">
            <?php foreach ($shapes as $shape) : ?>
                <li class="pb-row">
                    <span class="pb-row__event"><?= $e($shape['label']) ?></span>
                    <span class="pb-row__end">
                        <span class="time pb-row__time"><?= $e(SwimTime::format($shape['best']['time_cs'])) ?></span>
                        <span class="pb-row__date time"><?= $e($shape['best']['date']) ?></span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="section">
    <h2 class="section__title"><?= $t('sw.records') ?></h2>
    <?php if ($meetGroups === []) : ?>
        <p class="empty"><?= $t('sw.none') ?></p>
    <?php else : ?>
        <?php foreach ($meetGroups as $group) : ?>
            <h3 class="heat-title">
                <a href="<?= $view->url('meets.show', ['meet' => (int) $group['meet']['id']]) ?>"><?= $e($group['meet']['name']) ?></a>
                <span class="heat-title__date time"><?= $e($group['meet']['start_date']) ?></span>
            </h3>
            <ul class="result-list">
                <?php foreach ($group['results'] as $result) : ?>
                    <li>
                        <a class="result-row" href="<?= $view->url('meets.event', ['meet' => (int) $group['meet']['id'], 'event' => (int) $result['event_id']]) ?>">
                            <span class="result-row__main">
                                <span class="result-row__event"><?= $e(EventTitle::of($result)) ?>
                                    <?php if (EventTitle::roundLabel((string) $result['round']) !== '') : ?>
                                        <?= $e(Lang::t('evt.round_wrap', EventTitle::roundLabel((string) $result['round']))) ?>
                                    <?php endif; ?>
                                </span>
                            </span>
                            <span class="result-row__end">
                                <?php if ($result['status'] !== 'OK') : ?>
                                    <span class="result-row__status"><?= $e($result['status']) ?></span>
                                <?php else : ?>
                                    <span class="result-row__time time"><?= $e(SwimTime::format($result['final_time_cs'] !== null ? (int) $result['final_time_cs'] : null)) ?></span>
                                    <?php if ($result['place'] !== null) : ?>
                                        <span class="result-row__place"><?= $t('common.place', (int) $result['place']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
