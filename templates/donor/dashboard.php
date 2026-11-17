<?php
/**
 * Donor home page. Variables: $profile, $eligibility, $counts, $waiting, $badges, $untilNext.
 */
$elig = $eligibility;
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/requests.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/dashboard.css')) ?>">

<section aria-labelledby="dash-heading">
    <h1 id="dash-heading">Welcome, <?= e($profile['name']) ?></h1>
    <p class="muted">Blood group <strong><?= e($profile['blood_group']) ?></strong> &middot; <?= e($profile['city_name']) ?></p>

    <div class="card">
        <?php if ($elig['eligible']): ?>
            <p><strong>You are eligible to donate.</strong> Hospitals can match you with new requests.</p>
        <?php else: ?>
            <p><strong>You cannot be matched right now:</strong></p>
            <ul>
                <?php foreach ($elig['reasons'] as $reason): ?>
                    <li><?= e($reason) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p><a class="btn btn-small btn-outline" href="<?= e(url('/donor/profile')) ?>">Edit profile or availability</a></p>
    </div>

    <ul class="tiles">
        <li class="tile">
            <span class="tile-number"><?= (int) $counts['waiting'] ?></span>
            <span class="tile-label">Requests waiting for you</span>
        </li>
        <li class="tile">
            <span class="tile-number"><?= (int) $counts['accepted_open'] ?></span>
            <span class="tile-label">Accepted, not yet donated</span>
        </li>
        <li class="tile">
            <span class="tile-number"><?= (int) $counts['donations'] ?></span>
            <span class="tile-label">Donations made</span>
        </li>
        <li class="tile">
            <span class="tile-number"><?= $untilNext === null ? '&#10003;' : (int) $untilNext ?></span>
            <span class="tile-label"><?= $untilNext === null ? 'All badges earned' : 'Donations to next badge' ?></span>
        </li>
    </ul>

    <h2>Waiting for your answer</h2>
    <?php if ($waiting === []): ?>
        <p class="muted">No open request needs you right now.</p>
    <?php else: ?>
        <ul class="plain-list">
            <?php foreach ($waiting as $r): ?>
                <li class="card">
                    <span class="req-badge req-<?= e($r['urgency']) ?>"><?= e(ucfirst($r['urgency'])) ?></span>
                    <strong><?= e($r['blood_group']) ?></strong>
                    &middot; <?= (int) $r['units_needed'] ?> unit<?= (int) $r['units_needed'] === 1 ? '' : 's' ?>
                    at <?= e($r['hospital_name']) ?>, <?= e($r['city_name']) ?>
                    <span class="muted">(needed by <?= e(date('d M Y, H:i', strtotime((string) $r['required_by']))) ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p><a class="btn" href="<?= e(url('/donor/requests')) ?>">Answer requests</a></p>
    <?php endif; ?>

    <h2>Badges</h2>
    <ul class="badge-list">
        <?php foreach ($badges as $badge): ?>
            <li class="badge-item <?= $badge['earned'] ? 'is-earned' : 'is-locked' ?>">
                <strong><?= e($badge['name']) ?></strong>
                <span><?= $badge['earned'] ? 'Earned' : 'Needs ' . (int) $badge['target'] . ' donations' ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <p>
        <a href="<?= e(url('/donor/requests')) ?>">Requests for me</a> &middot;
        <a href="<?= e(url('/donor/history')) ?>">Donation history</a> &middot;
        <a href="<?= e(url('/emergencies')) ?>">Active emergencies</a>
    </p>
</section>
