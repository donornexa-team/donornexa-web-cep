<?php
/**
 * Role-aware navigation. Links point to the planned pages; a link shows the
 * 404 page until its slice has built it. Add new links here (one small PR).
 */

use DonorNexa\Core\Csrf;
use DonorNexa\Core\Session;

$user = Session::user();
$role = $user['role'] ?? 'guest';

$menus = [
    'guest' => [
        ['/', 'Home'],
        ['/emergencies', 'Active Emergencies'],
        ['/register/donor', 'Become a Donor'],
        ['/register/hospital', 'Register Hospital'],
        ['/login', 'Login'],
    ],
    'donor' => [
        ['/donor/dashboard', 'Dashboard'],
        ['/donor/profile', 'My Profile'],
        ['/donor/history', 'Donation History'],
        ['/emergencies', 'Active Emergencies'],
        ['/donor/requests', 'Requests for Me'],
    ],
    'hospital' => [
        ['/hospital/dashboard', 'Dashboard'],
        ['/hospital/requests', 'My Requests'],
        ['/hospital/requests/new', 'New Request'],
        ['/hospital/inventory', 'Inventory'],
        ['/emergencies', 'Active Emergencies'],
    ],
    'admin' => [
        ['/admin/dashboard', 'Dashboard'],
         ['/admin/donors', 'Donors'],
        ['/admin/hospitals', 'Hospitals'],
        ['/admin/users', 'Users'],
        ['/admin/audit', 'Audit Log'],
        ['/admin/settings', 'Settings'],
    ],
];

$current = current_path();
?>
<nav class="main-nav" aria-label="Main navigation">
    <ul>
        <?php foreach ($menus[$role] as [$path, $label]): ?>
            <li>
                <a href="<?= e(url($path)) ?>"<?= $current === $path ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            </li>
        <?php endforeach; ?>

        <?php if ($user !== null): ?>
            <li>
                <form method="post" action="<?= e(url('/logout')) ?>" class="logout-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-small btn-outline">Logout (<?= e($user['name']) ?>)</button>
                </form>
            </li>
        <?php endif; ?>
    </ul>
</nav>
