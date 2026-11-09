<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand-name"><?= e(config('app_name')) ?></span>
            <span class="brand-tagline">Connecting Donors. Bridging Lives. Saving Hope.</span>
        </a>
        <?php require TEMPLATE_PATH . '/partials/navbar.php'; ?>
    </div>
</header>
