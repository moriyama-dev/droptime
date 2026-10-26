<?php

declare(strict_types=1);

namespace DropTime\Http;

/**
 * ルートごとのアクセス区分。ルート定義で必ず指定させる。
 * 省略できないことで「認証チェックの書き忘れ」を構造的に起こらなくする。
 */
enum Access
{
    /** ログイン前でも見える。ログイン画面と静的なエラー画面のみ。 */
    case PUBLIC;

    /** クラブの合言葉が必要。保護者向け画面はすべてこれ。 */
    case PARENT;

    /** 管理者用の別合言葉が必要。 */
    case ADMIN;
}
