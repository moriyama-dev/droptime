<?php

declare(strict_types=1);

use DropTime\Http\Access;
use DropTime\Http\Controllers\AdminController;
use DropTime\Http\Controllers\AuthController;
use DropTime\Http\Controllers\FavoritesController;
use DropTime\Http\Controllers\HomeController;
use DropTime\Http\Controllers\MeetController;
use DropTime\Http\Controllers\PwaController;
use DropTime\Http\Controllers\ScoreController;
use DropTime\Http\Controllers\SourceController;
use DropTime\Http\Controllers\SwimmerController;

/**
 * ルート定義表。
 *
 * 1 件は [メソッド, パス, [コントローラ, アクション], アクセス区分, ルート名]。
 * アクセス区分は省略できない。省略するとルーターが例外を投げる。
 */
return [
    ['GET', '/', [HomeController::class, 'index'], Access::PARENT, 'home'],

    ['GET', '/meets', [MeetController::class, 'index'], Access::PARENT, 'meets'],
    ['GET', '/meets/{meet}', [MeetController::class, 'show'], Access::PARENT, 'meets.show'],
    ['GET', '/meets/{meet}/events/{event}', [MeetController::class, 'event'], Access::PARENT, 'meets.event'],

    ['GET', '/swimmers/{swimmer}', [SwimmerController::class, 'show'], Access::PARENT, 'swimmers.show'],
    // 旧アドレス。成績タブに一本化したので、そちらへ送る。
    ['GET', '/swimmers/{swimmer}/progress', [SwimmerController::class, 'progress'], Access::PARENT, 'swimmers.progress'],

    // 成績タブ。順位（その日の他の選手との比較）と推移（自分の過去との比較）。
    ['GET', '/scores', [ScoreController::class, 'index'], Access::PARENT, 'scores'],
    ['GET', '/scores/{swimmer}', [ScoreController::class, 'show'], Access::PARENT, 'scores.show'],
    // 同じクラブの年の近い仲間との自己ベスト比較。
    ['GET', '/scores/{swimmer}/club', [ScoreController::class, 'club'], Access::PARENT, 'scores.club'],

    // データの出典。取得元ページを公開状態で保存した証拠を見せる。
    ['GET', '/sources', [SourceController::class, 'index'], Access::PARENT, 'sources'],
    ['GET', '/sources/{capture}', [SourceController::class, 'show'], Access::PARENT, 'sources.show'],
    ['GET', '/sources/{capture}/file', [SourceController::class, 'file'], Access::PARENT, 'sources.file'],

    ['GET', '/favorites', [FavoritesController::class, 'index'], Access::PARENT, 'favorites'],
    ['POST', '/favorites/add', [FavoritesController::class, 'add'], Access::PARENT, 'favorites.add'],
    ['POST', '/favorites/remove', [FavoritesController::class, 'remove'], Access::PARENT, 'favorites.remove'],
    ['POST', '/favorites/code', [FavoritesController::class, 'issueCode'], Access::PARENT, 'favorites.code'],
    ['POST', '/favorites/claim', [FavoritesController::class, 'claim'], Access::PARENT, 'favorites.claim'],
    ['POST', '/favorites/forget', [FavoritesController::class, 'forget'], Access::PARENT, 'favorites.forget'],

    // PWA。ログイン前でも読めるが、含まれるのはアプリ名だけ。
    ['GET', '/manifest.json', [PwaController::class, 'manifest'], Access::PUBLIC, 'manifest'],

    ['GET', '/login', [AuthController::class, 'form'], Access::PUBLIC, 'login'],
    ['POST', '/login', [AuthController::class, 'submit'], Access::PUBLIC, 'login.submit'],
    ['POST', '/logout', [AuthController::class, 'logout'], Access::PARENT, 'logout'],

    // 管理画面はクラブの合言葉を通ったうえで、もう一段別の合言葉を要求する。
    ['GET', '/admin/login', [AuthController::class, 'adminForm'], Access::PARENT, 'admin.login'],
    ['POST', '/admin/login', [AuthController::class, 'adminSubmit'], Access::PARENT, 'admin.login.submit'],

    ['GET', '/admin', [AdminController::class, 'index'], Access::ADMIN, 'admin.home'],
    ['POST', '/admin/imports', [AdminController::class, 'upload'], Access::ADMIN, 'admin.imports.upload'],
    ['GET', '/admin/imports/{import}', [AdminController::class, 'importShow'], Access::ADMIN, 'admin.imports.show'],
    ['POST', '/admin/imports/{import}/commit', [AdminController::class, 'importCommit'], Access::ADMIN, 'admin.imports.commit'],
    ['POST', '/admin/imports/{import}/discard', [AdminController::class, 'importDiscard'], Access::ADMIN, 'admin.imports.discard'],
    ['POST', '/admin/imports/{import}/reparse', [AdminController::class, 'importReparse'], Access::ADMIN, 'admin.imports.reparse'],
    ['POST', '/admin/fetch', [AdminController::class, 'fetchStart'], Access::ADMIN, 'admin.fetch.start'],
    ['GET', '/admin/fetch', [AdminController::class, 'fetchShow'], Access::ADMIN, 'admin.fetch'],
    ['GET', '/admin/merges', [AdminController::class, 'merges'], Access::ADMIN, 'admin.merges'],
    ['POST', '/admin/merges/candidates/{candidate}/merge', [AdminController::class, 'mergeCommit'], Access::ADMIN, 'admin.merges.commit'],
    ['POST', '/admin/merges/candidates/{candidate}/dismiss', [AdminController::class, 'mergeDismiss'], Access::ADMIN, 'admin.merges.dismiss'],
    ['POST', '/admin/merges/{merge}/undo', [AdminController::class, 'mergeUndo'], Access::ADMIN, 'admin.merges.undo'],
];
