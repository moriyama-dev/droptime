<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var int $status */
/** @var string|null $detail 開発環境でのみ渡される */
/** @var string|null $trace 開発環境でのみ渡される */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<h1 class="page-title"><?= $t('err.error_title') ?></h1>
<p class="empty"><?= $t('err.error_body') ?></p>

<?php if ($detail !== null) : ?>
    <pre class="debug"><?= $e($status) ?>: <?= $e($detail) ?>

<?= $e($trace) ?></pre>
<?php endif; ?>
