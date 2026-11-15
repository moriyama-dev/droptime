<?php

declare(strict_types=1);

use DropTime\Http\Csrf;
use DropTime\Support\Lang;
use DropTime\Support\View;

/**
 * ホームの下の「携帯で使う」。インストールのボタンと、通知のオン・オフ。
 * 動きは public/assets/app.js。JavaScript が無い・対応していない環境では何も出ない（hidden のまま）。
 *
 * @var string $vapidPublic 通知の公開鍵（空なら通知の欄を出さない）
 */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

$texts = ['on', 'off', 'denied', 'unsupported', 'ios', 'working', 'saved', 'failed', 'test_sent'];
?>
<section class="section app-setup" data-app-setup data-csrf="<?= $e(Csrf::token()) ?>" aria-labelledby="app-setup-title" hidden>
    <h2 class="section__title" id="app-setup-title"><?= $t('app.title') ?></h2>

    <div class="app-setup__card" data-install hidden>
        <p class="app-setup__lead"><?= $t('app.install_lead') ?></p>
        <button type="button" class="button app-setup__button" data-install-button hidden>
            <svg class="app-setup__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a1 1 0 0 1 1 1v9.6l3.3-3.3 1.4 1.4L12 17.4l-5.7-5.7 1.4-1.4 3.3 3.3V4a1 1 0 0 1 1-1zM5 19h14v2H5z"/></svg>
            <?= $t('app.install_button') ?>
        </button>
        <p class="app-setup__help" data-install-ios hidden><?= $t('app.install_ios') ?></p>
    </div>

    <?php if ($vapidPublic !== '') : ?>
        <div class="app-setup__card" data-push data-vapid="<?= $e($vapidPublic) ?>"
            <?php foreach ($texts as $name) : ?> data-text-<?= $name ?>="<?= $t('app.push_' . $name) ?>"<?php endforeach; ?> hidden>
            <p class="app-setup__lead"><?= $t('app.push_lead') ?></p>
            <p class="app-setup__state" data-push-state aria-live="polite"></p>
            <div class="app-setup__actions">
                <button type="button" class="button app-setup__button" data-push-on hidden>
                    <svg class="app-setup__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.5-2.5h-5A2.5 2.5 0 0 0 12 22zm7-6V11a7 7 0 0 0-5.5-6.8V3.5a1.5 1.5 0 0 0-3 0v.7A7 7 0 0 0 5 11v5l-2 2v1h18v-1z"/></svg>
                    <?= $t('app.push_on_button') ?>
                </button>
                <button type="button" class="button button--quiet app-setup__button" data-push-test hidden><?= $t('app.push_test_button') ?></button>
                <button type="button" class="button button--quiet app-setup__button" data-push-off hidden><?= $t('app.push_off_button') ?></button>
            </div>
            <p class="app-setup__message" data-push-message aria-live="polite"></p>
        </div>
    <?php endif; ?>
</section>
