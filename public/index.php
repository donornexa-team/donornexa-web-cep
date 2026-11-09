<?php
/**
 * Front controller: every page request arrives here.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Logger;
use DonorNexa\Core\Router;
use DonorNexa\Core\View;
use DonorNexa\Exceptions\AppException;

try {
    $router = new Router();

    // One route file per slice, so teammates never edit the same file.
    foreach (['accounts', 'requests', 'public'] as $slice) {
        require ROOT_PATH . '/config/routes_' . $slice . '.php';
    }

    $router->dispatch();
} catch (AppException $e) {
    if ($e->getCode() >= 500) {
        Logger::exception($e);
    }
    View::error((int) $e->getCode(), $e->getMessage());
} catch (Throwable $e) {
    Logger::exception($e);
    View::error(500, config('debug') ? $e->getMessage() : 'Please try again in a few minutes.');
}
