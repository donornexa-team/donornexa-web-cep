<?php
/**
 * Routes owned by the REQUESTS slice (blood requests, matching, accept/decline).
 * $router is created in public/index.php.
 */

use DonorNexa\Controllers\DonorRequestController;
use DonorNexa\Controllers\RequestController;

/** @var \DonorNexa\Core\Router $router */

// Hospital side. "/hospital/requests/new" must be listed BEFORE "/hospital/requests/{id}",
// otherwise the router would treat the word "new" as a request number.
$router->get('/hospital/requests', [RequestController::class, 'index']);
$router->get('/hospital/requests/new', [RequestController::class, 'create']);
$router->post('/hospital/requests', [RequestController::class, 'store']);
$router->get('/hospital/requests/{id}', [RequestController::class, 'show']);

// Hospital actions on one of its own requests (hospital role, own requests only)
$router->post('/hospital/requests/{id}/matches/{match}/donated', [RequestController::class, 'markDonated']);
$router->post('/hospital/requests/{id}/cancel', [RequestController::class, 'cancel']);
$router->post('/hospital/requests/{id}/close', [RequestController::class, 'close']);

// Donor side: the inbox of matched requests, accept / decline, donation history and badges
$router->get('/donor/requests', [DonorRequestController::class, 'index']);
$router->post('/donor/requests/{id}/accept', [DonorRequestController::class, 'accept']);
$router->post('/donor/requests/{id}/decline', [DonorRequestController::class, 'decline']);
$router->get('/donor/history', [DonorRequestController::class, 'history']);
