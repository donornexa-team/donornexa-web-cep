<?php
/**
 * Routes owned by the PUBLIC slice (landing page, emergencies board, inventory, admin dashboard).
 * $router is created in public/index.php.
 */

use DonorNexa\Controllers\HomeController;

/** @var \DonorNexa\Core\Router $router */

$router->get('/', [HomeController::class, 'index']);
