<?php
/**
 * Admin dashboard. Variables: $overview (tile numbers), $rate (overall
 * fulfilment %), $chartUrl (JSON endpoint). The three charts are drawn by
 * public/assets/js/admin.js from the JSON; until then each box says "Loading".
 */

$tiles = [
    ['Registered donors',      $overview['donors']],
    ['Verified hospitals',     $overview['verified_hospitals']],
    ['Hospitals awaiting verification', $overview['pending_hospitals']],
    ['Open requests',          $overview['open_requests']],
    ['Total requests',         $overview['total_requests']],
    ['Units donated',          $overview['units_donated']],
    ['Fulfilment rate',        $rate . '%'],
    ['Blocked users',          $overview['blocked_users']],
];
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">

<section aria-labelledby="dash-heading" data-charts-url="<?= e($chartUrl) ?>">
    <h1 id="dash-heading">Admin Dashboard</h1>

    <ul class="tiles">
        <?php foreach ($tiles as [$label, $value]): ?>
            <li class="tile">
                <span class="tile-number"><?= e($value) ?></span>
                <span class="tile-label"><?= e($label) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="chart-grid">
        <figure class="card chart" id="chart-weekly">
            <figcaption><h2>Requests per week</h2><p class="muted">Blood requests raised in the last 12 weeks.</p></figcaption>
            <div class="chart-body" aria-live="polite">Loading chart&hellip;</div>
        </figure>

        <figure class="card chart" id="chart-supply">
            <figcaption><h2>Blood-group demand vs supply</h2><p class="muted">Units still needed on open requests, against eligible donors.</p></figcaption>
            <div class="chart-body" aria-live="polite">Loading chart&hellip;</div>
        </figure>

        <figure class="card chart" id="chart-fulfilment">
            <figcaption><h2>Fulfilment rate</h2><p class="muted">Units given out of units asked for, by urgency (cancelled requests left out).</p></figcaption>
            <div class="chart-body" aria-live="polite">Loading chart&hellip;</div>
        </figure>
    </div>

    <section class="card report-links" aria-labelledby="reports-heading">
        <h2 id="reports-heading">Reports and tools</h2>
        <p class="muted">CSV files open in Excel. Every export is recorded in the audit log.</p>
        <ul class="report-list">
            <li><a class="btn btn-small btn-outline" href="<?= e(url('/admin/export/requests')) ?>">Export requests (CSV)</a></li>
            <li><a class="btn btn-small btn-outline" href="<?= e(url('/admin/export/donors')) ?>">Export donors (CSV)</a></li>
            <li><a class="btn btn-small btn-outline" href="<?= e(url('/admin/export/audit')) ?>">Export audit log (CSV)</a></li>
            <li><a class="btn btn-small" href="<?= e(url('/admin/donors')) ?>">Search donors</a></li>
            <li><a class="btn btn-small" href="<?= e(url('/admin/audit')) ?>">Open the audit log</a></li>
        </ul>
    </section>
</section>

<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
