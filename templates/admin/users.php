<?php
/**
 * Admin: users. Variables: $list (ListQuery), $rows, $roles, $statuses, $role, $status.
 * The admin can block or unblock donors and hospitals. A blocked user cannot log in
 * and is never matched with requests. Administrator accounts cannot be blocked.
 */

use DonorNexa\Core\Csrf;
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/account_pages.css')) ?>">

<section aria-labelledby="users-heading">
    <h1 id="users-heading">Users</h1>
    <p>Block or unblock accounts. Every change is written to the audit log.</p>

    <form method="get" action="<?= e(url('/admin/users')) ?>" class="list-filters" role="search">
        <div class="filter-field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($list->search()) ?>"
                   maxlength="100" placeholder="Name or email">
        </div>

        <div class="filter-field">
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="">All</option>
                <?php foreach ($roles as $name): ?>
                    <option value="<?= e($name) ?>"<?= $name === $role ? ' selected' : '' ?>><?= e(ucfirst($name)) ?></option>
                <?php endforeach; ?>
            </select>
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
            <a class="btn btn-small btn-outline" href="<?= e(url('/admin/users')) ?>">Reset</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">User accounts</caption>
            <thead>
                <tr>
                    <?php
                    foreach ([['name', 'Name'], ['email', 'Email'], ['role', 'Role'], ['status', 'Status'], ['joined', 'Joined']] as [$key, $label]) {
                        require TEMPLATE_PATH . '/partials/sort_th.php';
                    }
                    ?>
                    <th scope="col">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" class="empty-cell">No users match your search.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $row): ?>
                    <?php $id = (int) $row['id']; ?>
                    <tr>
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['email']) ?></td>
                        <td><?= e(ucfirst($row['role'])) ?></td>
                        <td>
                            <span class="badge <?= $row['status'] === 'active' ? 'badge-ok' : 'badge-bad' ?>"><?= e(ucfirst($row['status'])) ?></span>
                        </td>
                        <td><?= e(date('d M Y', strtotime((string) $row['created_at']))) ?></td>
                        <td>
                            <?php if ($row['role'] === 'admin'): ?>
                                <span class="muted">&mdash;</span>
                            <?php elseif ($row['status'] === 'active'): ?>
                                <form method="post" action="<?= e(url('/admin/users/' . $id . '/block')) ?>" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn-small btn-outline">Block</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?= e(url('/admin/users/' . $id . '/unblock')) ?>" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn-small">Unblock</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php require TEMPLATE_PATH . '/partials/pagination.php'; ?>
</section>
