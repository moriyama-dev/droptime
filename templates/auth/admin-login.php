<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var string|null $error */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<h1 class="page-title"><?= $t('auth.admin_title') ?></h1>

<?php if ($error !== null) : ?>
    <p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<form class="login" method="post" action="<?= $view->url('admin.login.submit') ?>">
    <?= DropTime\Http\Csrf::field() ?>
    <label class="field">
        <span class="field__label"><?= $t('auth.admin_passphrase') ?></span>
        <input class="field__input" type="password" name="passphrase" autocomplete="off" required autofocus>
    </label>
    <button type="submit" class="button"><?= $t('auth.proceed') ?></button>
</form>
