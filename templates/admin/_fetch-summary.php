<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/**
 * 直近の「最新の記録を取得」の結果。管理画面のトップと進み具合の画面で共有する。
 *
 * @var array<string, mixed>|null $status
 * @var bool                      $running
 * @var DropTime\Support\View     $view
 */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

/** 記録は ISO 8601（オフセット付き）。トロントの現地時刻で見せる。 */
$local = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '–';
    }

    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('America/Toronto'))->format('Y-m-d H:i T');
    } catch (Exception) {
        return $iso;
    }
};
?>
<?php if ($running) : ?>
    <p class="fetch-state fetch-state--running"><span class="fetch-spinner" aria-hidden="true"></span><?= $t('fetch.running', $local($status['started_at'] ?? null)) ?></p>
<?php elseif ($status === null) : ?>
    <p class="panel__note"><?= $t('fetch.never') ?></p>
<?php elseif (($status['state'] ?? '') === 'failed') : ?>
    <p class="alert alert--error"><?= $t('fetch.failed', $local($status['finished_at'] ?? null), (string) ($status['error'] ?? '')) ?></p>
<?php elseif (($status['state'] ?? '') === 'done') : ?>
    <?php $summary = (array) ($status['summary'] ?? []); $imported = (array) ($summary['imported'] ?? []); ?>
    <p class="fetch-state"><?= $t('fetch.done', $local($status['finished_at'] ?? null), (int) ($summary['checked'] ?? 0), count($imported)) ?></p>

    <?php if ($imported !== []) : ?>
        <ul class="fetch-list">
            <?php foreach ($imported as $item) : ?>
                <li>
                    <a href="<?= $view->url('meets.show', ['meet' => (int) $item['meet_id']]) ?>"><?= $e($item['meet_name']) ?></a>
                    <span class="fetch-list__meta"><?= $t($item['is_new'] ? 'fetch.new_meet' : 'fetch.updated_meet', (int) $item['results']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php foreach ((array) ($summary['failed'] ?? []) as $item) : ?>
        <p class="alert alert--error"><?= $e($item['folder']) ?>: <?= $e($item['note']) ?></p>
    <?php endforeach; ?>

    <?php if ((int) ($status['pending_merges'] ?? 0) > 0) : ?>
        <p class="panel__note"><?= $t('fetch.merges', (int) $status['pending_merges']) ?> <a href="<?= $view->url('admin.merges') ?>"><?= $t('admin.merge_go') ?></a></p>
    <?php endif; ?>
<?php endif; ?>
