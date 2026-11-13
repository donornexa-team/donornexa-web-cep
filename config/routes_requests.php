<?php
/**
 * Routes owned by the REQUESTS slice (blood requests, matching, accept/decline).
 * $router is created in public/index.php.
 */

use DonorNexa\Controllers\RequestController;

/** @var \DonorNexa\Core\Router $router */

// Hospital side. "/hospital/requests/new" must be listed BEFORE "/hospital/requests/{id}",
// otherwise the router would treat the word "new" as a request number.
$router->get('/hospital/requests', [RequestController::class, 'index']);
$router->get('/hospital/requests/new', [RequestController::class, 'create']);
$router->post('/hospital/requests', [RequestController::class, 'store']);
$router->get('/hospital/requests/{id}', [RequestController::class, 'show']);
