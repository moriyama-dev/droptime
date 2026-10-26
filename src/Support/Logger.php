<?php

declare(strict_types=1);

namespace DropTime\Support;

use Throwable;

/**
 * ログはドキュメントルート外のファイルに書く。外部サービスへは一切送らない。
 */
final class Logger
{
    public static function error(string $message, ?Throwable $e = null): void
    {
        $line = $message;

        if ($e !== null) {
            $line .= sprintf(
                ' | %s: %s @ %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }

        self::write('ERROR', $line);
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    private static function write(string $level, string $message): void
    {
        $directory = Config::instance()->string('paths.logs', APP_ROOT . '/var/logs');

        if (!is_dir($directory)) {
            return;
        }

        $path = $directory . '/app-' . Clock::now()->format('Y-m-d') . '.log';
        $line = sprintf("[%s] %s %s\n", Clock::utcNow(), $level, str_replace("\n", ' ', $message));

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
