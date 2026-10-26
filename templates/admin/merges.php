<?php

declare(strict_types=1);

use DropTime\Support\Clock;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var list<array<string, mixed>> $candidates */
/** @var list<array<string, mixed>> $merges */
/** @var array{type: string, message: string}|null $flash */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

$who = static function (array $row, string $prefix) use ($e): string {
    $parts = [];
    $parts[] = '<strong>' . $e($row[$prefix . '_name']) . '</strong>';
    $parts[] = $row[$prefix . '_birth'] !== null
        ? $e(Lang::t('common.born', (int) $row[$prefix . '_birth']))
        : $e(Lang::t('mg.birth_unknown'));
    $parts[] = $row[$prefix . '_team'] !== null ? $e($row[$prefix . '_team']) : $e(Lang::t('mg.team_unknown'));
    $parts[] = $e(Lang::t('mg.results_count', (int) $row[$prefix . '_results']));

    return implode(' ・ ', $parts);
};
?>
<p class="breadcrumb"><a href="<?= $view->url('admin.home') ?>"><?= $t('imp.back') ?></a></p>
<h1 class="page-title"><?= $t('mg.title') ?></h1>

<?php if ($flash !== null) : ?>
    <p class="alert alert--<?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></p>
<?php endif; ?>

<section class="panel">
    <h2 class="panel__title"><?= $t('mg.pending_title', count($candidates)) ?></h2>
    <p class="panel__note"><?= $t('mg.pending_note') ?></p>

    <?php if ($candidates === []) : ?>
        <p class="empty"><?= $t('mg.none') ?></p>
    <?php else : ?>
        <?php foreach ($candidates as $candidate) : ?>
            <div class="merge-card">
                <p class="merge-card__reason"><?= $e($candidate['reason']) ?></p>
                <dl class="merge-card__pair">
                    <div>
                        <dt><?= $t('mg.side_a') ?></dt>
                        <dd><a href="<?= $view->url('swimmers.show', ['swimmer' => (int) $candidate['a_id']]) ?>"><?= $who($candidate, 'a') ?></a></dd>
                    </div>
                    <div>
                        <dt><?= $t('mg.side_b') ?></dt>
                        <dd><a href="<?= $view->url('swimmers.show', ['swimmer' => (int) $candidate['b_id']]) ?>"><?= $who($candidate, 'b') ?></a></dd>
                    </div>
                </dl>
                <div class="button-row">
                    <form method="post" action="<?= $view->url('admin.merges.commit', ['candidate' => (int) $candidate['id']]) ?>">
                        <?= DropTime\Http\Csrf::field() ?>
                        <input type="hidden" name="keep" value="b">
                        <button type="submit" class="button button--small"><?= $t('mg.same_b') ?></button>
                    </form>
                    <form method="post" action="<?= $view->url('admin.merges.commit', ['candidate' => (int) $candidate['id']]) ?>">
                        <?= DropTime\Http\Csrf::field() ?>
                        <input type="hidden" name="keep" value="a">
                        <button type="submit" class="button button--quiet button--small"><?= $t('mg.keep_a') ?></button>
                    </form>
                    <form method="post" action="<?= $view->url('admin.merges.dismiss', ['candidate' => (int) $candidate['id']]) ?>">
                        <?= DropTime\Http\Csrf::field() ?>
                        <button type="submit" class="button button--quiet button--small"><?= $t('mg.different') ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 class="panel__title"><?= $t('mg.history_title') ?></h2>
    <?php if ($merges === []) : ?>
        <p class="empty"><?= $t('mg.history_none') ?></p>
    <?php else : ?>
        <ul class="list">
            <?php foreach ($merges as $merge) : ?>
                <?php $removed = json_decode((string) $merge['removed_json'], true); ?>
                <li class="list__item list__item--row">
                    <span>
                        <?= $e(is_array($removed) ? ($removed['full_name'] ?? '?') : '?') ?>
                        → <?= $e($merge['kept_name']) ?>
                        <span class="list__meta time"><?= $e(Clock::toLocal((string) $merge['merged_at'])->format('Y-m-d H:i')) ?></span>
                    </span>
                    <?php if ($merge['undone_at'] === null) : ?>
                        <form method="post" action="<?= $view->url('admin.merges.undo', ['merge' => (int) $merge['id']]) ?>">
                            <?= DropTime\Http\Csrf::field() ?>
                            <button type="submit" class="button button--danger button--small"><?= $t('mg.undo') ?></button>
                        </form>
                    <?php else : ?>
                        <span class="badge"><?= $t('mg.undone') ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
