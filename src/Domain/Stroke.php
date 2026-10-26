<?php

declare(strict_types=1);

namespace DropTime\Domain;

use DropTime\Support\Lang;

/**
 * 泳法。DB には値（2〜3 文字）をそのまま保存する。
 */
enum Stroke: string
{
    case FREE = 'FR';
    case BACK = 'BK';
    case BREAST = 'BR';
    case FLY = 'FL';
    case IM = 'IM';
    /** メドレーリレー用。個人メドレーは IM。 */
    case MEDLEY = 'MED';

    public function label(): string
    {
        return Lang::t('stroke.' . $this->value);
    }
}
