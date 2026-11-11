<?php
/**
 * Routes owned by the ACCOUNTS slice (login, registration, profiles, admin verification).
 * $router is created in public/index.php.
 */

use DonorNexa\Controllers\AuthController;
use DonorNexa\Controllers\RegisterController;

/** @var \DonorNexa\Core\Router $router */

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/register/donor', [RegisterController::class, 'showDonor']);
$router->post('/register/donor', [RegisterController::class, 'storeDonor']);
$router->get('/register/hospital', [RegisterController::class, 'showHospital']);
$router->post('/register/hospital', [RegisterController::class, 'storeHospital']);
