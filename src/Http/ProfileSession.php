<?php

declare(strict_types=1);

namespace DropTime\Http;

use DropTime\Repository\FavoriteRepository;

/**
 * セッションとプロフィール（お気に入りの入れ物）の結び付け。
 *
 * プロフィールは最初のお気に入り登録時に静かに作られる。
 * 引き継ぎコードで別の端末から同じプロフィールに繋ぎ直せる。
 */
final class ProfileSession
{
    private const KEY = 'profile.id';

    public static function currentId(): ?int
    {
        $id = $_SESSION[self::KEY] ?? null;

        return is_int($id) ? $id : null;
    }

    public static function bind(int $profileId): void
    {
        $_SESSION[self::KEY] = $profileId;
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
        self::bind($id);

        return $id;
    }
}
