<?php
/**
 * Landing page. The three statistics are loaded with AJAX by public/assets/js/home.js
 * from api/stats.php (the page first shows a dash).
 */

// URL of the api folder: the project folder is the parent of /public.
$projectUrl = rtrim(str_replace('\\', '/', dirname(BASE_PATH)), '/');
$statsUrl   = $projectUrl . '/api/stats.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/public.css')) ?>">

<section class="hero">
    <h1>Every drop counts.</h1>
    <p>DonorNexa connects verified hospitals with eligible blood donors in real time,
       so the right blood reaches the right patient in time.</p>
    <p class="hero-actions">
        <a class="btn" href="<?= e(url('/register/donor')) ?>">Become a donor</a>
        <a class="btn btn-outline" href="<?= e(url('/emergencies')) ?>">See active emergencies</a>
    </p>
</section>

<section class="stats" aria-labelledby="stats-heading" data-stats-url="<?= e($statsUrl) ?>">
    <h2 id="stats-heading" class="visually-hidden">DonorNexa in numbers</h2>
    <div class="stat-card">
        <span class="stat-number" id="stat-active" aria-live="polite">&ndash;</span>
        <span class="stat-label">Active requests</span>
    </div>
    <div class="stat-card">
        <span class="stat-number" id="stat-donors" aria-live="polite">&ndash;</span>
        <span class="stat-label">Registered donors</span>
    </div>
    <div class="stat-card">
        <span class="stat-number" id="stat-units" aria-live="polite">&ndash;</span>
        <span class="stat-label">Units fulfilled</span>
    </div>
</section>

<section class="how-it-works" aria-labelledby="how-heading">
    <h2 id="how-heading">How it works</h2>
    <ol class="steps">
        <li>
            <article class="card">
                <h3>1. A hospital raises a request</h3>
                <p>Verified hospitals post the blood group, units and how urgent it is.</p>
            </article>
        </li>
        <li>
            <article class="card">
                <h3>2. Eligible donors are matched</h3>
                <p>We rank compatible donors nearby, so nobody who is not eligible is called.</p>
            </article>
        </li>
        <li>
            <article class="card">
                <h3>3. A donor accepts</h3>
                <p>Contact details are shared with the hospital only after the donor accepts.</p>
            </article>
        </li>
    </ol>
</section>

<script src="<?= e(asset('js/home.js')) ?>" defer></script>
