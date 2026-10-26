<?php

declare(strict_types=1);

namespace DropTime\Http;

use DropTime\Db\Database;
use DropTime\Support\Config;
use DropTime\Support\View;

/**
 * コントローラに渡す依存の束。コンテナは持たない。
 */
final class Context
{
    public function __construct(
        public readonly Config $config,
        public readonly Database $db,
        public readonly Router $router,
        public readonly View $view,
    ) {
    }
}
