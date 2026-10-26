<?php

declare(strict_types=1);

namespace DropTime\Http;

use DropTime\Repository\FavoriteRepository;

/**
 * セッション・端末とプロフィール（お気に入りの入れ物）の結び付け。
 *
 * プロフィールは最初のお気に入り登録時に静かに作られ、セッションと端末の目印
 * （DeviceCookie）の両方に結び付く。ログアウトでセッションが消えても、目印から
 * 同じ入れ物に戻る（restore）。引き継ぎコードで別の端末から同じ入れ物に繋ぎ直せる。
 */
final class ProfileSession
{
    private const KEY = 'profile.id';

    public static function currentId(): ?int
    {
        $id = $_SESSION[self::KEY] ?? null;

        return is_int($id) ? $id : null;
    }

    /** セッションと端末を、この入れ物に結び付ける。 */
    public static function bind(int $profileId, FavoriteRepository $favorites): void
    {
        $_SESSION[self::KEY] = $profileId;
        $favorites->linkDevice(DeviceCookie::hash() ?? DeviceCookie::issue(), $profileId);
    }

    /**
     * 毎回のリクエストの最初に呼ぶ（保護者として入っているときだけ）。
     * - セッションに入れ物が無く、端末の目印があれば、その入れ物に戻す（ログアウト後・期限切れ後）。
     * - セッションに入れ物があり、端末がまだ結び付いていなければ結び付ける（この仕組みより前の端末）。
     */
    public static function restore(FavoriteRepository $favorites): void
    {
        $sessionId = self::currentId();
        $deviceHash = DeviceCookie::hash();
        $deviceProfile = $deviceHash === null ? null : $favorites->profileForDevice($deviceHash);

        if ($sessionId === null) {
            if ($deviceProfile !== null) {
                $_SESSION[self::KEY] = $deviceProfile;
                DeviceCookie::extend();
            }

            return;
        }

        if ($deviceProfile === $sessionId) {
            DeviceCookie::extend();

            return;
        }

        if ($favorites->profileExists($sessionId)) {
            self::bind($sessionId, $favorites);
        }
    }

    /**
     * 現在のプロフィール ID を返す。無ければ作って結び付ける。
     * DB から消えていた場合（バックアップ復元など）も作り直す。
     */
    public static function ensure(FavoriteRepository $favorites): int
    {
        $id = self::currentId();

        if ($id !== null && $favorites->profileExists($id)) {
            return $id;
        }

        $id = $favorites->createProfile();
        self::bind($id, $favorites);

        return $id;
    }

    /** 「この端末のお気に入りを消す」。セッションと端末の両方から外す。 */
    public static function forget(FavoriteRepository $favorites): void
    {
        $id = self::currentId();

        if ($id !== null) {
            $favorites->forgetDevice($id, DeviceCookie::hash());
        }

        unset($_SESSION[self::KEY]);
        DeviceCookie::forget();
    }
}
