<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var list<array<string, mixed>> $favoriteSwimmers */
/** @var string $query */
/** @var list<array<string, mixed>> $searchResults */
/** @var string|null $issuedCode */
/** @var array{type: string, message: string}|null $flash */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

$favoriteIds = array_flip(array_map(static fn (array $s): int => (int) $s['id'], $favoriteSwimmers));
?>
<h1 class="page-title"><?= $t('fav.title') ?></h1>

<?php if ($flash !== null) : ?>
    <p class="alert alert--<?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></p>
<?php endif; ?>

<?php if ($issuedCode !== null) : ?>
    <section class="panel panel--highlight">
        <h2 class="panel__title"><?= $t('fav.issued_title') ?></h2>
        <p class="code-display time"><?= $e($issuedCode) ?></p>
        <p class="panel__note"><?= $t('fav.issued_note') ?></p>
    </section>
<?php endif; ?>

<section class="section">
    <h2 class="section__title"><?= $t('fav.registered') ?></h2>
    <?php if ($favoriteSwimmers === []) : ?>
        <p class="empty"><?= $t('fav.none') ?></p>
    <?php else : ?>
        <ul class="swimmer-list">
            <?php foreach ($favoriteSwimmers as $swimmer) : ?>
                <li class="swimmer-row">
                    <a class="swimmer-row__main" href="<?= $view->url('swimmers.show', ['swimmer' => (int) $swimmer['id']]) ?>">
                        <span class="swimmer-row__name"><?= $e($swimmer['full_name']) ?></span>
                        <span class="swimmer-row__meta">
                            <?php if ($swimmer['birth_year'] !== null) : ?><?= $t('common.born', (int) $swimmer['birth_year']) ?><?php endif; ?>
                            <?php if ($swimmer['team_name'] !== null) : ?> ・ <?= $e($swimmer['team_name']) ?><?php endif; ?>
                        </span>
                    </a>
                    <form method="post" action="<?= $view->url('favorites.remove') ?>">
                        <?= DropTime\Http\Csrf::field() ?>
                        <input type="hidden" name="swimmer_id" value="<?= $e($swimmer['id']) ?>">
                        <button type="submit" class="button button--quiet button--small"><?= $t('fav.remove') ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="section">
    <h2 class="section__title"><?= $t('fav.search_title') ?></h2>
    <form method="get" action="<?= $view->url('favorites') ?>" class="search-form" role="search">
        <label class="field">
            <span class="field__label"><?= $t('fav.search_label') ?></span>
            <input class="field__input" type="search" name="q" value="<?= $e($query) ?>" placeholder="<?= $t('fav.search_placeholder') ?>" autocomplete="off">
        </label>
        <button type="submit" class="button"><?= $t('fav.search_button') ?></button>
    </form>

    <?php if ($query !== '') : ?>
        <?php if ($searchResults === []) : ?>
            <p class="empty"><?= $t('fav.no_match', $query) ?></p>
        <?php else : ?>
            <ul class="swimmer-list">
                <?php foreach ($searchResults as $swimmer) : ?>
                    <li class="swimmer-row">
                        <a class="swimmer-row__main" href="<?= $view->url('swimmers.show', ['swimmer' => (int) $swimmer['id']]) ?>">
                            <span class="swimmer-row__name"><?= $e($swimmer['full_name']) ?></span>
                            <span class="swimmer-row__meta">
                                <?php if ($swimmer['birth_year'] !== null) : ?><?= $t('common.born', (int) $swimmer['birth_year']) ?><?php endif; ?>
                                <?php if ($swimmer['team_name'] !== null) : ?> ・ <?= $e($swimmer['team_name']) ?><?php endif; ?>
                            </span>
                        </a>
                        <?php if (!isset($favoriteIds[(int) $swimmer['id']])) : ?>
                            <form method="post" action="<?= $view->url('favorites.add') ?>">
                                <?= DropTime\Http\Csrf::field() ?>
                                <input type="hidden" name="swimmer_id" value="<?= $e($swimmer['id']) ?>">
                                <input type="hidden" name="back" value="/favorites?q=<?= $e(rawurlencode($query)) ?>">
                                <button type="submit" class="button button--small"><?= $t('fav.add') ?></button>
                            </form>
                        <?php else : ?>
                            <span class="badge badge--committed"><?= $t('fav.registered') ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="section">
    <h2 class="section__title"><?= $t('fav.transfer_title') ?></h2>
    <div class="panel">
        <h3 class="panel__title"><?= $t('fav.export_title') ?></h3>
        <p class="panel__note"><?= $t('fav.export_note') ?></p>
        <form method="post" action="<?= $view->url('favorites.code') ?>">
            <?= DropTime\Http\Csrf::field() ?>
            <button type="submit" class="button button--quiet"><?= $t('fav.issue_button') ?></button>
        </form>
    </div>
    <div class="panel">
        <h3 class="panel__title"><?= $t('fav.import_title') ?></h3>
        <form method="post" action="<?= $view->url('favorites.claim') ?>">
            <?= DropTime\Http\Csrf::field() ?>
            <label class="field">
                <span class="field__label"><?= $t('fav.code_label') ?></span>
                <input class="field__input field__input--code" type="text" name="code" placeholder="XXXXX-XXXXX"
                       autocomplete="off" autocapitalize="characters" spellcheck="false">
            </label>
            <button type="submit" class="button"><?= $t('fav.claim_button') ?></button>
        </form>
        <p class="panel__note"><?= $t('fav.claim_note') ?></p>
    </div>
</section>
