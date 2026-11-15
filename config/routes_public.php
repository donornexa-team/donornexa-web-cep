<?php
/**
 * Routes owned by the PUBLIC slice (landing page, emergencies board, inventory, admin dashboard).
 * $router is created in public/index.php.
 */

use DonorNexa\Controllers\AdminController;
use DonorNexa\Controllers\EmergencyController;
use DonorNexa\Controllers\HomeController;
use DonorNexa\Controllers\InventoryController;

/** @var \DonorNexa\Core\Router $router */

$router->get('/', [HomeController::class, 'index']);
$router->get('/emergencies', [EmergencyController::class, 'index']);

// Admin insights (the admin must be logged in; see AdminController)
$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin/dashboard', [AdminController::class, 'dashboard']);
$router->get('/admin/charts', [AdminController::class, 'charts']);
$router->get('/admin/audit', [AdminController::class, 'audit']);
$router->get('/admin/donors', [AdminController::class, 'donors']);
$router->get('/admin/export/{report}', [AdminController::class, 'export']);

// Hospital blood stock (hospital role only, see InventoryController)
$router->get('/hospital/inventory', [InventoryController::class, 'index']);
$router->post('/hospital/inventory', [InventoryController::class, 'update']);
