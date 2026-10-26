<?php

declare(strict_types=1);

use DropTime\Support\Lang;
use DropTime\Support\View;

/**
 * 合言葉の欄。右端の目のアイコンで、伏せ字と通常の文字を切り替える。
 * 目が開いている間は伏せ字、閉じている間は文字がそのまま見える（Yoshi の指定）。
 * 切り替えは app.js が行う。JavaScript が無いときはボタンを出さない（hidden のまま）。
 */

/** @var string $autocomplete */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));
?>
<span class="field__reveal">
    <input class="field__input" type="password" name="passphrase" autocomplete="<?= $e($autocomplete) ?>" required autofocus>
    <button type="button" class="field__eye" data-reveal hidden aria-pressed="false"
        aria-label="<?= $t('auth.show') ?>" data-label-show="<?= $t('auth.show') ?>" data-label-hide="<?= $t('auth.hide') ?>">
        <svg class="field__eye-open" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
            <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
        </svg>
        <svg class="field__eye-closed" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
            <path d="M3 10c2 3.2 5.2 5 9 5s7-1.8 9-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M6.5 13.6 5 16M12 15v3M17.5 13.6 19 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
    </button>
</span>
