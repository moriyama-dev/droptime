<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/**
 * 「比べる年齢の幅」。年下・年上を 1 歳ずつ広げたり狭めたりする（JavaScript なしのリンク）。
 * 成績ページの「同じ年齢」「同じクラブ」と、クラブの仲間の画面で使う。
 */

/** @var string $title */
/** @var int $younger */
/** @var int $older */
/** @var int $max */
/** @var callable(int, int): string $url 幅を変えたページの URL */
/** @var string $class */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

$steppers = [
    ['label' => $t('rankx.younger'), 'current' => $younger, 'less' => $url($younger - 1, $older), 'more' => $url($younger + 1, $older),
        'value' => $younger === 0 ? $t('rankx.years_none') : $t('rankx.years_younger', $younger)],
    ['label' => $t('rankx.older'), 'current' => $older, 'less' => $url($younger, $older - 1), 'more' => $url($younger, $older + 1),
        'value' => $older === 0 ? $t('rankx.years_none') : $t('rankx.years_older', $older)],
];
?>
<div class="age-range <?= $e($class) ?>" role="group" aria-label="<?= $e($title) ?>">
    <span class="age-range__title"><?= $e($title) ?></span>
    <?php foreach ($steppers as $stepper) : ?>
        <span class="age-range__stepper">
            <span class="age-range__label"><?= $stepper['label'] ?></span>
            <?php if ($stepper['current'] > 0) : ?>
                <a class="age-range__button" href="<?= $e($stepper['less']) ?>" aria-label="<?= $stepper['label'] ?> <?= $t('rankx.narrow') ?>">−</a>
            <?php else : ?>
                <span class="age-range__button is-disabled" aria-hidden="true">−</span>
            <?php endif; ?>
            <span class="age-range__value time"><?= $stepper['value'] ?></span>
            <?php if ($stepper['current'] < $max) : ?>
                <a class="age-range__button" href="<?= $e($stepper['more']) ?>" aria-label="<?= $stepper['label'] ?> <?= $t('rankx.widen') ?>">+</a>
            <?php else : ?>
                <span class="age-range__button is-disabled" aria-hidden="true">+</span>
            <?php endif; ?>
        </span>
    <?php endforeach; ?>
    <?php if ($younger > 0 || $older > 0) : ?>
        <a class="age-range__reset" href="<?= $e($url(0, 0)) ?>"><?= $t('rankx.reset_range') ?></a>
    <?php endif; ?>
</div>
