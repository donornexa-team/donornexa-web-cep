<?php
/**
 * Donation history and badges. Variables: $donations, $count, $badges, $untilNext.
 */
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/requests.css')) ?>">

<section aria-labelledby="history-heading">
    <h1 id="history-heading">Donation history</h1>
    <p>You have donated <strong><?= (int) $count ?></strong> time<?= $count === 1 ? '' : 's' ?>.
        <?php if ($untilNext !== null): ?>
            <?= (int) $untilNext ?> more to earn your next badge.
        <?php else: ?>
            You have earned every badge. Thank you!
        <?php endif; ?>
    </p>

    <h2>Badges</h2>
    <ul class="badge-list">
        <?php foreach ($badges as $badge): ?>
            <li class="badge-item <?= $badge['earned'] ? 'is-earned' : 'is-locked' ?>">
                <strong><?= e($badge['name']) ?></strong>
                <span><?= $badge['earned'] ? 'Earned' : 'Needs ' . (int) $badge['target'] . ' donations' ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <h2>Your donations</h2>
    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Completed donations, newest first</caption>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Hospital</th>
                    <th scope="col">City</th>
                    <th scope="col">Blood group</th>
                    <th scope="col">Units</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($donations)): ?>
                    <tr><td colspan="5" class="empty-cell">No donations recorded yet. When a hospital confirms your donation, it appears here.</td></tr>
                <?php endif; ?>
                <?php foreach ($donations as $row): ?>
                    <tr>
                        <td><?= e($row['donated_on']) ?></td>
                        <td><?= e($row['hospital_name']) ?></td>
                        <td><?= e($row['city_name']) ?></td>
                        <td><?= e($row['blood_group']) ?></td>
                        <td><?= (int) $row['units'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
