<?php

declare(strict_types=1);

use DropTime\Http\Auth;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var string $content */
/** @var string $appName */
/** @var array<string, string> $brand */
/** @var string $styleNonce */
/** @var string $basePath */
/** @var string $homeUrl */
/** @var string $currentPath */
/** @var array<string, string> $currentQuery */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

$isParent = Auth::isParent();

/*
 * 上部バーの行き来のリンク。管理画面にいるときはユーザー画面へ、それ以外は管理画面へ。
 * 管理画面の入口（/admin/login）は、管理の合言葉を通っていれば管理画面へそのまま送る。
 * どちらもクラブの合言葉を通ったあとでしか開けないので、未ログインでは出さない。
 */
$inAdmin = $currentPath === '/admin' || str_starts_with($currentPath, '/admin/');

/** タブの活性判定。トップは完全一致、それ以外は前方一致。 */
$active = static function (string $prefix) use ($currentPath): string {
    if ($prefix === '/') {
        return $currentPath === '/' ? ' tabbar__link--active' : '';
    }

    return str_starts_with($currentPath, $prefix) ? ' tabbar__link--active' : '';
};

/** 言語切り替えリンク。今いるページに ?lang= を付けて戻ってくる。 */
$langUrl = static function (string $code) use ($basePath, $currentPath, $currentQuery): string {
    $query = $currentQuery;
    $query['lang'] = $code;

    return $basePath . $currentPath . '?' . http_build_query($query);
};

$languages = ['en' => 'EN', 'ja' => '日本語', 'yue' => '廣東話'];
?>
<!doctype html>
<html lang="<?= $e(Lang::htmlLang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive, noimageindex">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="<?= $e($brand['primary'] ?? '#0b4f6c') ?>">
<title><?= $e($appName) ?></title>
<link rel="stylesheet" href="<?= $e($basePath) ?>/assets/app.css?v=<?= $e((string) @filemtime(APP_ROOT . '/public/assets/app.css')) ?>">
<script src="<?= $e($basePath) ?>/assets/app.js?v=<?= $e((string) @filemtime(APP_ROOT . '/public/assets/app.js')) ?>"></script>
<link rel="manifest" href="<?= $e($basePath) ?>/manifest.json">
<link rel="apple-touch-icon" href="<?= $e($basePath) ?>/assets/icons/icon-180.png">
<link rel="icon" href="<?= $e($basePath) ?>/assets/icons/icon-192.png" type="image/png">
<style nonce="<?= $e($styleNonce) ?>">
:root {
    --dt-primary: <?= $e($brand['primary'] ?? '#0b4f6c') ?>;
    --dt-accent: <?= $e($brand['accent'] ?? '#00c2a8') ?>;
}
</style>
</head>
<body<?= $isParent ? ' class="has-tabbar"' : '' ?>>
<a class="skip" href="#main"><?= $t('nav.skip') ?></a>
<header class="topbar">
    <div class="topbar__start">
        <a class="topbar__name" href="<?= $e($homeUrl) ?>"><?= $e($appName) ?></a>
        <?php if ($isParent) : ?>
            <?php if ($inAdmin) : ?>
                <a class="topbar__switch" href="<?= $e($basePath) ?>/"><?= $t('nav.to_parent') ?></a>
            <?php else : ?>
                <a class="topbar__switch" href="<?= $e($basePath) ?>/admin/login"><?= $t('nav.to_admin') ?></a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="topbar__end">
        <?php if ($isParent) : ?>
            <form class="topbar__logout" method="post" action="<?= $e($basePath) ?>/logout">
                <?= DropTime\Http\Csrf::field() ?>
                <button type="submit" class="topbar__switch"><?= $t('common.logout') ?></button>
            </form>
        <?php endif; ?>
        <nav class="langswitch" aria-label="<?= $t('nav.language') ?>">
            <?php foreach ($languages as $code => $label) : ?>
                <?php if (Lang::current() === $code) : ?>
                    <span class="langswitch__item langswitch__item--active" aria-current="true"><?= $e($label) ?></span>
                <?php else : ?>
                    <a class="langswitch__item" href="<?= $e($langUrl($code)) ?>" lang="<?= $code === 'yue' ? 'yue-Hant' : $e($code) ?>"><?= $e($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
<main id="main" class="main">
<?= $content ?>
</main>
<?php if ($isParent) : ?>
    <nav class="tabbar" aria-label="<?= $e($appName) ?>">
        <a class="tabbar__link<?= $active('/') ?>" href="<?= $e($basePath) ?>/">
            <svg class="tabbar__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 17c1.5 0 1.5-1.2 3.3-1.2S9.2 17 11 17s1.9-1.2 3.7-1.2S16.5 17 18.3 17 20 15.8 20 15.8V18c-1.5 0-1.7 1-3.4 1-1.8 0-1.9-1-3.7-1s-1.8 1-3.6 1c-1.7 0-1.9-1-3.3-1zm9.5-11.5a2 2 0 1 1 4 0 2 2 0 0 1-4 0zM4 13.5s2.8-4.7 5.2-6.4c1.5-1 3 .7 2 2-1 1.4-3.1 3-3.1 3s1.4.8 2.9.8c2 0 2.6-1.4 4.5-1.4v2.3c-1.7 0-1.9 1.1-3.7 1.1s-1.9-1.1-3.7-1.1S6 15 4 13.5z"/></svg>
            <span><?= $t('nav.next') ?></span>
        </a>
        <a class="tabbar__link<?= $active('/meets') ?>" href="<?= $e($basePath) ?>/meets">
            <svg class="tabbar__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm1 2v12h12V6H6zm2 3h8v2H8V9zm0 4h5v2H8v-2z"/></svg>
            <span><?= $t('nav.meets') ?></span>
        </a>
        <a class="tabbar__link<?= $active('/scores') ?>" href="<?= $e($basePath) ?>/scores">
            <svg class="tabbar__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6h6v14H9V6zM3 11h6v9H3v-9zm12 3h6v6h-6v-6z"/></svg>
            <span><?= $t('nav.scores') ?></span>
        </a>
        <a class="tabbar__link<?= $active('/favorites') ?><?= $active('/swimmers') ?>" href="<?= $e($basePath) ?>/favorites">
            <svg class="tabbar__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.3 4.8 13a4.7 4.7 0 0 1 0-6.6 4.6 4.6 0 0 1 6.5 0l.7.7.7-.7a4.6 4.6 0 0 1 6.5 0 4.7 4.7 0 0 1 0 6.6L12 20.3z"/></svg>
            <span><?= $t('nav.favorites') ?></span>
        </a>
    </nav>
<?php endif; ?>
</body>
</html>
