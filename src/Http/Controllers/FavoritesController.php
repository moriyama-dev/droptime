<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Http\Controller;
use DropTime\Http\Flash;
use DropTime\Http\LoginThrottle;
use DropTime\Http\ProfileSession;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Repository\FavoriteRepository;
use DropTime\Repository\SwimmerRepository;
use DropTime\Support\Lang;
use DropTime\Support\TransferCode;

/**
 * お気に入りの管理と、端末間の引き継ぎ。
 *
 * お気に入りは「プロフィール」に紐づく。プロフィールはアカウントではなく、
 * 引き継ぎコードの SHA-256 だけを持つ軽い入れ物。
 */
final class FavoritesController extends Controller
{
    /** 発行直後のコードを 1 回だけ表示するためのセッションキー。 */
    private const ISSUED_CODE_KEY = 'favorites.issued_code';

    public function index(Request $request): Response
    {
        $favorites = $this->favorites();
        $profileId = ProfileSession::currentId();

        $query = trim($request->queryString('q'));
        $searchResults = $query !== '' ? (new SwimmerRepository($this->context->db))->search($query) : [];

        $issuedCode = $_SESSION[self::ISSUED_CODE_KEY] ?? null;
        unset($_SESSION[self::ISSUED_CODE_KEY]);

        return $this->view('parent/favorites', [
            'favoriteSwimmers' => $profileId !== null ? $favorites->favoritesOf($profileId) : [],
            'query' => $query,
            'searchResults' => $searchResults,
            'issuedCode' => is_string($issuedCode) ? $issuedCode : null,
            'flash' => Flash::pull(),
        ]);
    }

    public function add(Request $request): Response
    {
        $swimmerId = (int) $request->postString('swimmer_id');
        $swimmer = (new SwimmerRepository($this->context->db))->find($swimmerId);

        if ($swimmer === null) {
            Flash::error(Lang::t('fl.swimmer_missing'));

            return $this->back($request);
        }

        $favorites = $this->favorites();
        $profileId = ProfileSession::ensure($favorites);
        $favorites->add($profileId, $swimmerId);
        $favorites->touch($profileId);

        Flash::success(Lang::t('fl.fav_added', (string) $swimmer['full_name']));

        return $this->back($request);
    }

    public function remove(Request $request): Response
    {
        $profileId = ProfileSession::currentId();

        if ($profileId !== null) {
            $this->favorites()->remove($profileId, (int) $request->postString('swimmer_id'));
            Flash::success(Lang::t('fl.fav_removed'));
        }

        return $this->back($request);
    }

    /**
     * 引き継ぎコードの発行。前のコードは無効になる。
     * コードは保存されないため、この直後の 1 回しか表示できない。
     */
    public function issueCode(Request $request): Response
    {
        $favorites = $this->favorites();
        $profileId = ProfileSession::ensure($favorites);

        $code = TransferCode::generate();
        $favorites->setKeyHash($profileId, TransferCode::hash($code));
        $favorites->touch($profileId);

        $_SESSION[self::ISSUED_CODE_KEY] = TransferCode::pretty($code);

        return $this->redirectToRoute('favorites');
    }

    /**
     * 別の端末で発行されたコードを入力して、そのプロフィールに繋ぎ直す。
     * コードの総当たりを防ぐため、ログインと同じレート制限をかける。
     */
    public function claim(Request $request): Response
    {
        $throttle = $this->throttle();
        $ip = $request->ipBinary();

        $locked = $throttle->lockedSeconds($ip, 'claim');

        if ($locked > 0) {
            Flash::error(Lang::t('auth.locked', max(1, (int) ceil($locked / 60))));

            return $this->redirectToRoute('favorites');
        }

        $code = $request->postString('code');
        $favorites = $this->favorites();
        $profileId = TransferCode::normalize($code) === ''
            ? null
            : $favorites->findProfileByKeyHash(TransferCode::hash($code));

        if ($profileId === null) {
            $throttle->recordFailure($ip, 'claim');
            Flash::error(Lang::t('fl.code_wrong'));

            return $this->redirectToRoute('favorites');
        }

        $throttle->recordSuccess($ip, 'claim');
        ProfileSession::bind($profileId);
        $favorites->touch($profileId);

        $count = count($favorites->favoriteIdsOf($profileId));
        Flash::success(Lang::t('fl.claimed', $count));

        return $this->redirectToRoute('favorites');
    }

    private function favorites(): FavoriteRepository
    {
        return new FavoriteRepository($this->context->db);
    }

    private function throttle(): LoginThrottle
    {
        $config = $this->context->config;

        return new LoginThrottle(
            $this->context->db,
            $config->int('auth.lockout.max_attempts', 10),
            $config->int('auth.lockout.window_minutes', 15),
            $config->int('auth.lockout.lock_minutes', 15),
        );
    }

    /**
     * 操作元の画面に戻す。自サイト内のパスだけを受け付ける。
     */
    private function back(Request $request): Response
    {
        $back = AuthController::safeNext($request->postString('back'));

        if ($back !== '') {
            return Response::redirect($this->context->config->string('app.base_path', '') . $back);
        }

        return $this->redirectToRoute('favorites');
    }
}
