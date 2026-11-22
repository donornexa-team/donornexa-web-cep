<header class="site-header">
    <div class="container header-inner">
    <a class="brand" href="<?= e(url('/')) ?>">
        <img class="brand-logo" src="<?= e(asset('img/logo.svg')) ?>" alt="" width="44" height="44">
        <span class="brand-text">
            <span class="brand-name"><?= e(config('app_name')) ?></span>
            <span class="brand-tagline">Connecting Donors. Bridging Lives. Saving Hope.</span>
        </span>
    </a>
        <?php require TEMPLATE_PATH . '/partials/navbar.php'; ?>
    </div>
</header>
