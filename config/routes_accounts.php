<?php
/**
 * Routes owned by the ACCOUNTS slice (login, registration, profiles, admin verification).
 * $router is created in public/index.php.
 */

use DonorNexa\Controllers\AdminAccountsController;
use DonorNexa\Controllers\AdminSettingsController;
use DonorNexa\Controllers\AuthController;
use DonorNexa\Controllers\DashboardController;
use DonorNexa\Controllers\DonorController;
use DonorNexa\Controllers\RegisterController;

/** @var \DonorNexa\Core\Router $router */

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/register/donor', [RegisterController::class, 'showDonor']);
$router->post('/register/donor', [RegisterController::class, 'storeDonor']);
$router->get('/register/hospital', [RegisterController::class, 'showHospital']);
$router->post('/register/hospital', [RegisterController::class, 'storeHospital']);

// Role home pages (donor and hospital; the admin dashboard is in routes_public.php)
$router->get('/donor/dashboard', [DashboardController::class, 'donor']);
$router->get('/hospital/dashboard', [DashboardController::class, 'hospital']);

// Donor: own profile, availability toggle, photo (donor role only, see DonorController)
$router->get('/donor/profile', [DonorController::class, 'profile']);
$router->post('/donor/profile', [DonorController::class, 'update']);
$router->post('/donor/availability', [DonorController::class, 'availability']);
$router->post('/donor/photo', [DonorController::class, 'photo']);

// Admin: verify / reject hospitals, block / unblock users (admin role only)
$router->get('/admin/hospitals', [AdminAccountsController::class, 'hospitals']);
$router->get('/admin/hospitals/{id}/licence', [AdminAccountsController::class, 'licence']);
$router->post('/admin/hospitals/{id}/verify', [AdminAccountsController::class, 'verifyHospital']);
$router->post('/admin/hospitals/{id}/reject', [AdminAccountsController::class, 'rejectHospital']);
$router->get('/admin/users', [AdminAccountsController::class, 'users']);
$router->post('/admin/users/{id}/block', [AdminAccountsController::class, 'blockUser']);
$router->post('/admin/users/{id}/unblock', [AdminAccountsController::class, 'unblockUser']);

// Admin: system settings (cool-down days ...) and cities (admin role only)
$router->get('/admin/settings', [AdminSettingsController::class, 'show']);
$router->post('/admin/settings', [AdminSettingsController::class, 'saveSettings']);
$router->post('/admin/cities', [AdminSettingsController::class, 'addCity']);
$router->post('/admin/cities/{id}', [AdminSettingsController::class, 'updateCity']);
