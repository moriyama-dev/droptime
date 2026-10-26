<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var DropTime\Support\View $view */
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<h1 class="page-title"><?= $t('nav.scores') ?></h1>

<section class="panel rise">
    <h2 class="panel__title"><?= $t('scores.empty_title') ?></h2>
    <p class="panel__note"><?= $t('scores.empty_body') ?></p>
    <p><a class="button" href="<?= $view->url('favorites') ?>"><?= $t('scores.empty_cta') ?></a></p>
</section>
