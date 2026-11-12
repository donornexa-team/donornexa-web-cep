<?php
/**
 * Routes owned by the PUBLIC slice (landing page, emergencies board, inventory, admin dashboard).
 * $router is created in public/index.php.
 */

use DonorNexa\Controllers\AdminController;
use DonorNexa\Controllers\EmergencyController;
use DonorNexa\Controllers\HomeController;

/** @var \DonorNexa\Core\Router $router */

$router->get('/', [HomeController::class, 'index']);
$router->get('/emergencies', [EmergencyController::class, 'index']);

// Admin insights (the admin must be logged in; see AdminController)
$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin/dashboard', [AdminController::class, 'dashboard']);
$router->get('/admin/charts', [AdminController::class, 'charts']);
$router->get('/admin/audit', [AdminController::class, 'audit']);
