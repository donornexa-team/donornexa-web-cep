<?php
/**
 * Hospital home page. Variables: $hospital, $counts, $open, $inventory.
 */
$verified = $hospital['verification_status'] === 'verified';
$trust    = (int) $hospital['trust_score'];
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/requests.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/dashboard.css')) ?>">

<section aria-labelledby="dash-heading">
    <h1 id="dash-heading"><?= e($hospital['hospital_name']) ?></h1>
    <p class="muted"><?= e($hospital['city_name']) ?></p>

    <?php if (!$verified): ?>
        <div class="alert alert-info" role="status">
            Your hospital is <strong><?= e($hospital['verification_status']) ?></strong>.
            You can raise blood requests after an administrator approves your licence.
        </div>
    <?php endif; ?>

    <ul class="tiles">
        <li class="tile"><span class="tile-number"><?= (int) $counts['open_requests'] ?></span><span class="tile-label">Open requests</span></li>
        <li class="tile"><span class="tile-number"><?= (int) $counts['donors_coming'] ?></span><span class="tile-label">Donors coming</span></li>
        <li class="tile"><span class="tile-number"><?= (int) $counts['fulfilled'] ?></span><span class="tile-label">Fulfilled</span></li>
        <li class="tile"><span class="tile-number"><?= $trust ?></span><span class="tile-label">Trust score (of 100)</span></li>
    </ul>
    <p class="muted">Cancelling a request lowers the trust score. Closing one does not.</p>

    <div class="page-head">
        <h2>Open requests</h2>
        <?php if ($verified): ?>
            <a class="btn btn-small" href="<?= e(url('/hospital/requests/new')) ?>">New request</a>
        <?php endif; ?>
    </div>
    <?php if ($open === []): ?>
        <p class="muted">You have no open requests.</p>
    <?php else: ?>
        <ul class="plain-list">
            <?php foreach ($open as $r): ?>
                <li class="card">
                    <span class="req-badge req-<?= e($r['urgency']) ?>"><?= e(ucfirst($r['urgency'])) ?></span>
                    <a href="<?= e(url('/hospital/requests/' . (int) $r['id'])) ?>"><strong><?= e($r['blood_group']) ?></strong>
                    request #<?= (int) $r['id'] ?></a>
                    &middot; <?= (int) $r['units_fulfilled'] ?> of <?= (int) $r['units_needed'] ?> unit(s) received,
                    <?= (int) $r['accepted'] ?> donor(s) accepted
                    <span class="muted">(needed by <?= e(date('d M Y, H:i', strtotime((string) $r['required_by']))) ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2>Blood stock</h2>
    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Units in stock by blood group</caption>
            <thead>
                <tr>
                    <?php foreach ($inventory as $group => $row): ?>
                        <th scope="col"><?= e($group) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach ($inventory as $row): ?>
                        <td><?= (int) $row['units'] ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
    <p>
        <a href="<?= e(url('/hospital/inventory')) ?>">Update inventory</a> &middot;
        <a href="<?= e(url('/hospital/requests')) ?>">All my requests</a>
    </p>
</section>
