<?php

declare(strict_types=1);

use DropTime\Http\Access;
use DropTime\Http\Controllers\HomeController;
use DropTime\Http\HttpException;
use DropTime\Http\Router;
use DropTime\Tests\Assert;

$build = static function (): Router {
    return Router::fromDefinitions([
        ['GET', '/', [HomeController::class, 'index'], Access::PARENT, 'home'],
        ['GET', '/meets/{meet}/events/{event}', [HomeController::class, 'index'], Access::PARENT, 'event'],
        ['POST', '/login', [HomeController::class, 'index'], Access::PUBLIC, 'login.submit'],
    ]);
};

return [
    'ルート直下に一致する' => static function () use ($build): void {
        $matched = $build()->match('GET', '/');
        Assert::true($matched !== null);
        Assert::same('home', $matched[0]->name);
    },
    'パスパラメータを取り出す' => static function () use ($build): void {
        $matched = $build()->match('GET', '/meets/12/events/34');
        Assert::true($matched !== null);
        Assert::same('12', $matched[1]['meet']);
        Assert::same('34', $matched[1]['event']);
    },
    'パラメータはスラッシュをまたがない' => static function () use ($build): void {
        Assert::null($build()->match('GET', '/meets/12/34/events/56'));
    },
    '未定義のパスは null' => static function () use ($build): void {
        Assert::null($build()->match('GET', '/nope'));
    },
    'パスは一致してメソッドが違えば 405' => static function () use ($build): void {
        Assert::throws(HttpException::class, static fn () => $build()->match('GET', '/login'));
    },
    'ルート名から URL を組み立てる' => static function () use ($build): void {
        Assert::same('/meets/7/events/8', $build()->url('event', ['meet' => 7, 'event' => 8]));
    },
    'ルート名の重複を拒否する' => static function (): void {
        Assert::throws(InvalidArgumentException::class, static fn () => Router::fromDefinitions([
            ['GET', '/a', [HomeController::class, 'index'], Access::PUBLIC, 'dup'],
            ['GET', '/b', [HomeController::class, 'index'], Access::PUBLIC, 'dup'],
        ]));
    },
    'アクセス区分を省略した定義を拒否する' => static function (): void {
        Assert::throws(InvalidArgumentException::class, static fn () => Router::fromDefinitions([
            // @phpstan-ignore-next-line 意図的に不正な定義
            ['GET', '/a', [HomeController::class, 'index'], 'home'],
        ]));
    },
    '全ルートにアクセス区分が指定されている' => static function (): void {
        /** @var list<array<int, mixed>> $definitions */
        $definitions = require APP_ROOT . '/src/Http/routes.php';

        foreach ($definitions as $definition) {
            Assert::true(
                ($definition[3] ?? null) instanceof Access,
                '4 番目の要素は Access でなければならない: ' . (string) ($definition[1] ?? '?')
            );
        }
    },
];
