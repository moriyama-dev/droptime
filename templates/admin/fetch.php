<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var bool $running */
/** @var array<string, mixed>|null $status */
/** @var list<string> $log */
/** @var array{type: string, message: string}|null $flash */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<?php if ($running) : ?>
    <?php // 実行中は 3 秒ごとに読み直して進み具合を見せる（このアプリは JavaScript を持たない）。 ?>
    <meta http-equiv="refresh" content="3">
<?php endif; ?>

<h1 class="page-title"><?= $t('fetch.title') ?></h1>

<?php if ($flash !== null) : ?>
    <p class="alert alert--<?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></p>
<?php endif; ?>

<section class="panel">
    <?= $view->partial('admin/_fetch-summary', ['status' => $status, 'running' => $running]) ?>

    <?php if (!$running) : ?>
        <form method="post" action="<?= $view->url('admin.fetch.start') ?>">
            <?= DropTime\Http\Csrf::field() ?>
            <button type="submit" class="button"><?= $t('fetch.button_again') ?></button>
        </form>
    <?php endif; ?>
</section>

<?php if ($log !== []) : ?>
    <section class="panel">
        <h2 class="panel__title"><?= $t('fetch.log_title') ?></h2>
        <pre class="fetch-log"><?= $e(implode("\n", $log)) ?></pre>
    </section>
<?php endif; ?>

<p><a class="section-head__link" href="<?= $view->url('admin.home') ?>"><?= $t('fetch.back') ?></a></p>
