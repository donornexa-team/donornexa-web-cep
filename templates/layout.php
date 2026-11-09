<?php
/**
 * Page shell used by every page. Variables available:
 *   $content  the rendered page body
 *   $title    optional page title
 */

use DonorNexa\Core\Csrf;
use DonorNexa\Core\Session;

$pageTitle = (isset($title) && $title !== '') ? $title . ' | ' . config('app_name') : config('app_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
    <a class="skip-link" href="#main">Skip to main content</a>

    <?php require TEMPLATE_PATH . '/partials/header.php'; ?>

    <main id="main" class="container">
        <?php foreach (Session::pullFlash() as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <?= $content ?>
    </main>

    <?php require TEMPLATE_PATH . '/partials/footer.php'; ?>

    <script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
