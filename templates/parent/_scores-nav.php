<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/**
 * 成績タブの上部。選手の切り替えと、2 つの見方（大会での順位・クラブの仲間）の切り替え。
 *
 * @var array<string, mixed>       $swimmer
 * @var list<array<string, mixed>> $switcher
 * @var string                     $active 'meets' か 'club'
 * @var DropTime\Support\View      $view
 */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
$route = $active === 'club' ? 'scores.club' : 'scores.show';
?>
<?php if (count($switcher) > 1) : ?>
    <nav class="swimmer-switch" aria-label="<?= $t('scores.switch') ?>">
        <?php foreach ($switcher as $option) : ?>
            <?php if ((int) $option['id'] === (int) $swimmer['id']) : ?>
                <span class="swimmer-switch__item swimmer-switch__item--active" aria-current="true"><?= $e($option['full_name']) ?></span>
            <?php else : ?>
                <a class="swimmer-switch__item" href="<?= $view->url($route, ['swimmer' => (int) $option['id']]) ?>"><?= $e($option['full_name']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<nav class="segmented" aria-label="<?= $t('scores.view') ?>">
    <?php foreach (['meets' => 'scores.show', 'club' => 'scores.club'] as $key => $name) : ?>
        <a class="segmented__item<?= $key === $active ? ' segmented__item--active' : '' ?>"
           href="<?= $view->url($name, ['swimmer' => (int) $swimmer['id']]) ?>"
           <?= $key === $active ? 'aria-current="page"' : '' ?>><?= $t('scores.view_' . $key) ?></a>
    <?php endforeach; ?>
</nav>
