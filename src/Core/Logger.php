<?php
declare(strict_types=1);

namespace DonorNexa\Core;

use Throwable;

/**
 * Writes errors to logs/error.log (file handling requirement).
 */
class Logger
{
    public static function error(string $message): void
    {
        if (!is_dir(LOG_PATH)) {
            mkdir(LOG_PATH, 0755, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents(LOG_PATH . '/error.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function exception(Throwable $e): void
    {
        self::error(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
}
