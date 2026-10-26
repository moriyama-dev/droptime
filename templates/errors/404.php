<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<h1 class="page-title"><?= $t('err.notfound_title') ?></h1>
<p class="empty"><?= $t('err.notfound_body') ?></p>
