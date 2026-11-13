<?php
/**
 * Admin: hospitals. Variables: $list (ListQuery), $rows, $statuses, $status.
 * The admin reads the licence PDF and verifies or rejects the hospital.
 * Until a hospital is verified it cannot raise blood requests.
 */

use DonorNexa\Core\Csrf;

$badgeFor = ['pending' => 'badge-warn', 'verified' => 'badge-ok', 'rejected' => 'badge-bad'];
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/account_pages.css')) ?>">

<section aria-labelledby="hospitals-heading">
    <h1 id="hospitals-heading">Hospitals</h1>
    <p>Check the licence document, then verify or reject the hospital. Every decision is written to the audit log.</p>

    <form method="get" action="<?= e(url('/admin/hospitals')) ?>" class="list-filters" role="search">
        <div class="filter-field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($list->search()) ?>"
                   maxlength="100" placeholder="Name, licence number, email or city">
        </div>

        <div class="filter-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach ($statuses as $name): ?>
                    <option value="<?= e($name) ?>"<?= $name === $status ? ' selected' : '' ?>><?= e(ucfirst($name)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <input type="hidden" name="sort" value="<?= e($list->sortKey()) ?>">
        <input type="hidden" name="dir" value="<?= e(strtolower($list->direction())) ?>">

        <div class="filter-actions">
            <button type="submit" class="btn btn-small">Apply</button>
            <a class="btn btn-small btn-outline" href="<?= e(url('/admin/hospitals')) ?>">Reset</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Registered hospitals</caption>
            <thead>
                <tr>
                    <?php
                    foreach ([['name', 'Hospital'], ['city', 'City'], ['licence', 'Licence no.'], ['status', 'Status'],
                              ['trust', 'Trust'], ['registered', 'Registered']] as [$key, $label]) {
                        require TEMPLATE_PATH . '/partials/sort_th.php';
                    }
                    ?>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="empty-cell">No hospitals match your search.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $row): ?>
                    <?php $id = (int) $row['user_id']; ?>
                    <tr>
                        <td>
                            <?= e($row['hospital_name']) ?><br>
                            <small class="muted"><?= e($row['email']) ?></small>
                            <?php if ($row['account_status'] === 'blocked'): ?>
                                <span class="badge badge-bad">Blocked</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($row['city_name']) ?></td>
                        <td>
                            <?= e($row['licence_no']) ?><br>
                            <a href="<?= e(url('/admin/hospitals/' . $id . '/licence')) ?>" target="_blank" rel="noopener">View licence (PDF)</a>
                        </td>
                        <td><span class="badge <?= e($badgeFor[$row['verification_status']] ?? 'badge-neutral') ?>"><?= e(ucfirst($row['verification_status'])) ?></span></td>
                        <td><?= (int) $row['trust_score'] ?></td>
                        <td><?= e(date('d M Y', strtotime((string) $row['created_at']))) ?></td>
                        <td>
                            <div class="row-actions">
                                <?php if ($row['verification_status'] !== 'verified'): ?>
                                    <form method="post" action="<?= e(url('/admin/hospitals/' . $id . '/verify')) ?>" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="btn btn-small">Verify</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($row['verification_status'] !== 'rejected'): ?>
                                    <form method="post" action="<?= e(url('/admin/hospitals/' . $id . '/reject')) ?>" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="btn btn-small btn-outline">Reject</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php require TEMPLATE_PATH . '/partials/pagination.php'; ?>
</section>
