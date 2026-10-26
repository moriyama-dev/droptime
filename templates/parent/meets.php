<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var list<array<string, mixed>> $meets */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<h1 class="page-title"><?= $t('meets.title') ?></h1>

<?php if ($meets === []) : ?>
    <p class="empty"><?= $t('common.no_meets') ?></p>
<?php else : ?>
    <ul class="meet-list">
        <?php foreach ($meets as $meet) : ?>
            <li>
                <a class="meet-row" href="<?= $view->url('meets.show', ['meet' => (int) $meet['id']]) ?>">
                    <span class="meet-row__main">
                        <span class="meet-row__name"><?= $e($meet['name']) ?></span>
                        <span class="meet-row__meta">
                            <span class="time"><?= $e($meet['start_date']) ?></span>
                            <?php if (!empty($meet['end_date']) && $meet['end_date'] !== $meet['start_date']) : ?>
                                〜 <span class="time"><?= $e($meet['end_date']) ?></span>
                            <?php endif; ?>
                            ・ <?= $e($meet['course']) ?>
                            <?php if (!empty($meet['venue'])) : ?>
                                ・ <?= $e($meet['venue']) ?>
                            <?php endif; ?>
                        </span>
                    </span>
                    <span class="meet-row__badge">
                        <?php if ((int) $meet['result_count'] > 0) : ?>
                            <span class="badge badge--committed"><?= $t('meets.badge_results') ?></span>
                        <?php else : ?>
                            <span class="badge"><?= $t('meets.badge_entries') ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
