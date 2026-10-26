<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var string|null $error */
/** @var string $next */
/** @var string $appName */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<h1 class="page-title"><?= $e($appName) ?></h1>

<?php if ($error !== null) : ?>
    <p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<form class="login" method="post" action="<?= $view->url('login.submit') ?>">
    <?= DropTime\Http\Csrf::field() ?>
    <input type="hidden" name="next" value="<?= $e($next) ?>">
    <label class="field">
        <span class="field__label"><?= $t('auth.passphrase') ?></span>
        <?= $view->partial('auth/_passphrase-input', ['autocomplete' => 'current-password']) ?>
    </label>
    <button type="submit" class="button"><?= $t('auth.enter') ?></button>
</form>
