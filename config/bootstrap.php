<?php
/**
 * Bootstrap: everything every entry point needs (public/index.php and api/*.php).
 * Sets constants, the autoloader, timezone, error logging and the session.
 */
declare(strict_types=1);

use DonorNexa\Core\Session;

if (PHP_VERSION_ID < 80000) {
    exit('DonorNexa needs PHP 8.0 or newer. This server runs PHP ' . PHP_VERSION . '.');
}

define('ROOT_PATH', dirname(__DIR__));
define('TEMPLATE_PATH', ROOT_PATH . '/templates');
define('LOG_PATH', ROOT_PATH . '/logs');

// PSR-4 autoloader: DonorNexa\Core\Router -> src/Core/Router.php
spl_autoload_register(function (string $class): void {
    $prefix = 'DonorNexa\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = ROOT_PATH . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require ROOT_PATH . '/src/Core/helpers.php';

// URL prefix of the public folder, e.g. "/DonorNexa/public" under XAMPP.
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if (substr($scriptDir, -4) === '/api') {
    $scriptDir = substr($scriptDir, 0, -4) . '/public';
}
define('BASE_PATH', (string) (config('base_path') ?? rtrim($scriptDir, '/')));

date_default_timezone_set((string) config('timezone', 'Asia/Karachi'));

error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/error.log');

Session::start();
DonorNexa\Core\RememberMe::restoreSession();   // "keep me logged in" cookie
