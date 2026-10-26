<?php

declare(strict_types=1);

namespace DropTime\Import;

use RuntimeException;

/**
 * 取り込み時の想定内の失敗。メッセージは管理画面に表示してよい内容にする。
 */
final class ImportException extends RuntimeException
{
}
