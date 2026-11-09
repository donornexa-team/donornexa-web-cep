<?php
declare(strict_types=1);

namespace DonorNexa\Core;

use DonorNexa\Exceptions\AppException;
use PDO;
use PDOException;

/**
 * One shared PDO connection. Repositories call Database::connection()
 * and ALWAYS use prepared statements (never concatenate user input into SQL).
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $cfg = require ROOT_PATH . '/config/db.php';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'] ?? 3307,
            $cfg['dbname'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . config('db_time_zone', '+05:00') . "'",
            ]);
        } catch (PDOException $e) {
            Logger::exception($e);
            throw new AppException(
                'Could not connect to the database. Check that MySQL is running and config/db.local.php is correct.',
                500,
                $e
            );
        }

        return self::$pdo;
    }
}
