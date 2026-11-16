<?php

declare(strict_types=1);

use DropTime\Http\Csrf;
use DropTime\Push\TestNotice;
use DropTime\Support\Clock;
use DropTime\Support\Lang;
use DropTime\Support\View;

/**
 * 管理者用のテスト。通知の診断とテスト送信・テスト時刻・稼働の確認。
 * この端末の診断とブラウザだけでの表示の試しは public/assets/app.js（[data-push-diag]）。
 *
 * @var array{type: string, message: string}|null $flash
 * @var list<array<string, mixed>> $subscriptions
 * @var string $vapidPublic
 * @var string $dueAt 現地の 'Y-m-d H:i:s'
 * @var bool $dueAtGiven
 * @var ?string $testNow
 * @var array{live: ?string, push: ?string, timings: int, timing_meets: int, finished_today: int, vapid: bool, exec: bool} $health
 * @var list<string> $log
 * @var DropTime\Support\View $view
 */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
$local = static fn (?string $utc): string => $utc === null ? '—' : Clock::toLocal($utc)->format('Y-m-d H:i');
$diagTexts = ['yes', 'no', 'checking', 'mode_app', 'mode_tab', 'perm_granted', 'perm_denied', 'perm_default', 'sub_none', 'server_none', 'server_found',
    'verdict_ios_tab', 'verdict_unsupported', 'verdict_denied', 'verdict_off', 'verdict_not_saved', 'verdict_ok', 'local_shown', 'local_blocked', 'local_failed', 'this_device'];
?>
<h1 class="page-title"><?= $t('tools.title') ?></h1>
<p class="panel__note"><?= $t('tools.lead') ?></p>

<?php if ($flash !== null) : ?>
    <p class="alert alert--<?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></p>
<?php endif; ?>

<section class="panel panel--highlight" data-push-diag
    <?php foreach ($diagTexts as $name) : ?> data-text-<?= $name ?>="<?= $t('tools.diag_' . $name) ?>"<?php endforeach; ?>>
    <h2 class="panel__title"><?= $t('tools.diag_title') ?></h2>
    <p class="panel__note"><?= $t('tools.diag_note') ?></p>
    <dl class="diag">
        <?php foreach (['https', 'sw', 'push', 'mode', 'permission', 'subscription', 'server'] as $item) : ?>
            <div class="diag__row" data-diag="<?= $item ?>">
                <dt class="diag__label"><?= $t('tools.diag_item_' . $item) ?></dt>
                <dd class="diag__value" data-value><?= $t('tools.diag_needs_js') ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
    <p class="diag__verdict" data-diag-verdict aria-live="polite"></p>
    <div class="app-setup__actions">
        <button type="button" class="button button--quiet" data-diag-local data-body="<?= $t('push.test_body') ?>"><?= $t('tools.diag_local_button') ?></button>
    </div>
    <p class="panel__note"><?= $t('tools.diag_local_note') ?></p>
    <p class="app-setup__message" data-diag-message aria-live="polite"></p>
</section>

<?= $view->partial('parent/_app-setup', ['vapidPublic' => $vapidPublic]) ?>

<section class="panel">
    <h2 class="panel__title"><?= $t('tools.push_title') ?></h2>
    <p class="panel__note"><?= $t('tools.push_note') ?></p>
    <?php if (!$health['vapid']) : ?>
        <p class="alert alert--error"><?= $t('tools.no_vapid') ?></p>
    <?php elseif ($subscriptions === []) : ?>
        <p class="empty"><?= $t('tools.sub_none') ?></p>
    <?php else : ?>
        <form method="post" action="<?= $view->url('admin.tools.push') ?>">
            <?= Csrf::field() ?>
            <label class="field">
                <span class="field__label"><?= $t('tools.push_target') ?></span>
                <select class="field__input" name="subscription" data-diag-target>
                    <?php foreach ($subscriptions as $row) : ?>
                        <option value="<?= (int) $row['id'] ?>" data-sub-hash="<?= $e($row['hash']) ?>"<?= $row['mine'] ? ' selected' : '' ?>>
                            <?= $t('tools.sub_label', (int) $row['id'], $row['relay'], (int) $row['profile_id']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field__label"><?= $t('tools.push_kind') ?></span>
                <select class="field__input" name="kind">
                    <?php foreach (TestNotice::KINDS as $kind) : ?>
                        <option value="<?= $e($kind) ?>"><?= $t('tools.kind_' . $kind) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field__label"><?= $t('tools.push_delay') ?></span>
                <select class="field__input" name="delay">
                    <?php foreach (TestNotice::DELAYS as $delay) : ?>
                        <option value="<?= $delay ?>"<?= $delay > 0 && !$health['exec'] ? ' disabled' : '' ?>>
                            <?= $delay === 0 ? $t('tools.delay_now') : ($delay < 60 ? $t('tools.delay_seconds', $delay) : $t('tools.delay_minutes', intdiv($delay, 60))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button"><?= $t('tools.push_button') ?></button>
        </form>
        <p class="panel__note"><?= $t('tools.push_delay_note') ?></p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 class="panel__title"><?= $t('tools.subs_title') ?></h2>
    <p class="panel__note"><?= $t('tools.subs_note') ?></p>
    <?php if ($subscriptions === []) : ?>
        <p class="empty"><?= $t('tools.sub_none') ?></p>
    <?php else : ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= $t('tools.th_id') ?></th>
                        <th><?= $t('tools.th_relay') ?></th>
                        <th><?= $t('tools.th_profile') ?></th>
                        <th><?= $t('tools.th_favorites') ?></th>
                        <th><?= $t('tools.th_lang') ?></th>
                        <th><?= $t('tools.th_created') ?></th>
                        <th><?= $t('tools.th_last_sent') ?></th>
                        <th><?= $t('tools.th_sent_count') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subscriptions as $row) : ?>
                        <tr data-sub-row="<?= $e($row['hash']) ?>">
                            <td class="time"><?= (int) $row['id'] ?> <span class="badge" data-this-device hidden></span></td>
                            <td><?= $e($row['relay']) ?></td>
                            <td class="time"><?= (int) $row['profile_id'] ?></td>
                            <td class="time"><?= (int) $row['favorites'] ?></td>
                            <td><?= $e($row['lang']) ?></td>
                            <td class="time"><?= $e($local($row['created_at'])) ?></td>
                            <td class="time"><?= $e($local($row['last_sent_at'])) ?></td>
                            <td class="time"><?= (int) $row['sent_count'] ?></td>
                            <td>
                                <form method="post" action="<?= $view->url('admin.tools.push.delete') ?>">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="subscription" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button button--quiet button--small"><?= $t('tools.sub_delete') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 class="panel__title"><?= $t('tools.due_title') ?></h2>
    <p class="panel__note"><?= $t('tools.due_note') ?></p>
    <form method="get" action="<?= $view->url('admin.tools') ?>">
        <label class="field">
            <span class="field__label"><?= $t('tools.due_at') ?></span>
            <input class="field__input" type="datetime-local" name="at" value="<?= $e(str_replace(' ', 'T', substr($dueAt, 0, 16))) ?>">
        </label>
        <button type="submit" class="button button--quiet"><?= $t('tools.due_button') ?></button>
    </form>
    <p class="panel__note"><?= $t($dueAtGiven ? 'tools.due_showing_at' : 'tools.due_showing_now', substr($dueAt, 0, 16)) ?></p>
    <?php $anyDue = false; ?>
    <?php foreach ($subscriptions as $row) : ?>
        <?php foreach ($row['due'] as $notice) : ?>
            <?php $anyDue = true; ?>
            <div class="due">
                <p class="due__to"><?= $t('tools.sub_label', (int) $row['id'], $row['relay'], (int) $row['profile_id']) ?>
                    <span class="badge"><?= $t($notice['sent'] ? 'tools.due_sent' : 'tools.due_pending') ?></span></p>
                <p class="due__title"><?= $e($notice['title']) ?></p>
                <p class="due__body"><?= nl2br($e($notice['body'])) ?></p>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if (!$anyDue) : ?>
        <p class="empty"><?= $t('tools.due_none') ?></p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 class="panel__title"><?= $t('tools.clock_title') ?></h2>
    <p class="panel__note"><?= $t('tools.clock_note') ?></p>
    <?php if ($testNow !== null) : ?>
        <p class="alert alert--info"><?= $t('tools.clock_active', substr($testNow, 0, 16)) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $view->url('admin.tools.clock') ?>">
        <?= Csrf::field() ?>
        <label class="field">
            <span class="field__label"><?= $t('tools.clock_at') ?></span>
            <input class="field__input" type="datetime-local" name="at" value="<?= $e(str_replace(' ', 'T', substr($testNow ?? $dueAt, 0, 16))) ?>">
        </label>
        <button type="submit" class="button"><?= $t('tools.clock_button') ?></button>
        <?php if ($testNow !== null) : ?>
            <button type="submit" class="button button--quiet" name="clear" value="1"><?= $t('tools.clock_clear') ?></button>
        <?php endif; ?>
    </form>
</section>

<section class="panel">
    <h2 class="panel__title"><?= $t('tools.health_title') ?></h2>
    <dl class="diag">
        <div class="diag__row"><dt class="diag__label"><?= $t('tools.health_live') ?></dt><dd class="diag__value time"><?= $e($health['live'] ?? Lang::t('tools.health_never')) ?></dd></div>
        <div class="diag__row"><dt class="diag__label"><?= $t('tools.health_push') ?></dt><dd class="diag__value time"><?= $e($health['push'] ?? Lang::t('tools.health_never')) ?></dd></div>
        <div class="diag__row"><dt class="diag__label"><?= $t('tools.health_finished') ?></dt><dd class="diag__value time"><?= (int) $health['finished_today'] ?></dd></div>
        <div class="diag__row"><dt class="diag__label"><?= $t('tools.health_timings') ?></dt><dd class="diag__value time"><?= $t('tools.health_timings_value', (int) $health['timings'], (int) $health['timing_meets']) ?></dd></div>
    </dl>
    <p class="panel__note"><?= $t('tools.health_note') ?></p>
</section>

<section class="panel">
    <h2 class="panel__title"><?= $t('tools.log_title') ?></h2>
    <?php if ($log === []) : ?>
        <p class="empty"><?= $t('tools.log_none') ?></p>
    <?php else : ?>
        <pre class="fetch-log"><?= $e(implode("\n", $log)) ?></pre>
    <?php endif; ?>
</section>

<nav class="footer-nav">
    <a class="footer-nav__link" href="<?= $view->url('admin.home') ?>"><?= $t('fetch.back') ?></a>
    <a class="footer-nav__link" href="<?= $view->url('home') ?>"><?= $t('admin.to_parent') ?></a>
</nav>
