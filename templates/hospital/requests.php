<?php
/**
 * The hospital's own requests. Variables: $list (ListQuery), $rows, $statuses, $status.
 * The search bar, sortable headers and page links come from the shared
 * ListQuery helper and its partials; their styles are in admin.css.
 */
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/requests.css')) ?>">

<section aria-labelledby="requests-heading">
    <div class="page-head">
        <h1 id="requests-heading">My blood requests</h1>
        <a class="btn" href="<?= e(url('/hospital/requests/new')) ?>">New request</a>
    </div>

    <form method="get" action="<?= e(url('/hospital/requests')) ?>" class="list-filters" role="search">
        <div class="filter-field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($list->search()) ?>"
                   maxlength="100" placeholder="Blood group or ward notes">
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
            <a class="btn btn-small btn-outline" href="<?= e(url('/hospital/requests')) ?>">Reset</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Blood requests raised by your hospital</caption>
            <thead>
                <tr>
                    <th scope="col">No.</th>
                    <?php
                    foreach ([['blood', 'Blood group'], ['units', 'Units'], ['urgency', 'Urgency'],
                              ['needed_by', 'Needed by'], ['status', 'Status']] as [$key, $label]) {
                        require TEMPLATE_PATH . '/partials/sort_th.php';
                    }
                    ?>
                    <th scope="col">Donors matched</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="empty-cell">No requests found. Raise your first request with the button above.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>#<?= (int) $row['id'] ?></td>
                        <td><strong><?= e($row['blood_group']) ?></strong></td>
                        <td><?= (int) $row['units_fulfilled'] ?> of <?= (int) $row['units_needed'] ?></td>
                        <td><span class="req-badge req-<?= e($row['urgency']) ?>"><?= e(ucfirst($row['urgency'])) ?></span></td>
                        <td><?= e($row['required_by']) ?></td>
                        <td>
                            <?= e(ucfirst($row['status'])) ?>
                            <?php if ($row['past_deadline']): ?>
                                <small class="muted">(deadline passed)</small>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $row['donors_matched'] ?></td>
                        <td><a href="<?= e(url('/hospital/requests/' . (int) $row['id'])) ?>">View<span class="visually-hidden"> request <?= (int) $row['id'] ?></span></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php require TEMPLATE_PATH . '/partials/pagination.php'; ?>
</section>
