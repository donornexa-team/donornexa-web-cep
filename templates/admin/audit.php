<?php
/**
 * Audit log (admin only). Variables: $list (ListQuery), $rows, $entities, $entity.
 * Every status change in the system is written to audit_logs; this page lets
 * the admin search, filter, sort and page through it.
 */
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">

<section aria-labelledby="audit-heading">
    <h1 id="audit-heading">Audit Log</h1>
    <p>Who did what, when and from where. Every request, match, donation and verification change is recorded here.</p>

    <form method="get" action="<?= e(url('/admin/audit')) ?>" class="list-filters" role="search">
        <div class="filter-field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($list->search()) ?>"
                   maxlength="100" placeholder="User, action, value or IP address">
        </div>

        <div class="filter-field">
            <label for="entity">Entity</label>
            <select id="entity" name="entity">
                <option value="">All</option>
                <?php foreach ($entities as $name): ?>
                    <option value="<?= e($name) ?>"<?= $name === $entity ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <input type="hidden" name="sort" value="<?= e($list->sortKey()) ?>">
        <input type="hidden" name="dir" value="<?= e(strtolower($list->direction())) ?>">

        <div class="filter-actions">
            <button type="submit" class="btn btn-small">Apply</button>
            <a class="btn btn-small btn-outline" href="<?= e(url('/admin/audit')) ?>">Reset</a>
            <?php $exportQuery = $list->queryString(); ?>
            <a class="btn btn-small btn-outline"
               href="<?= e(url('/admin/export/audit') . ($exportQuery !== '' ? '?' . $exportQuery : '')) ?>">Export CSV</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Audit log entries</caption>
            <thead>
                <tr>
                    <?php
                    foreach ([['when', 'Time'], ['user', 'User'], ['action', 'Action'], ['entity', 'Entity']] as [$key, $label]) {
                        require TEMPLATE_PATH . '/partials/sort_th.php';
                    }
                    ?>
                    <th scope="col">Old value</th>
                    <th scope="col">New value</th>
                    <?php
                    $key = 'ip';
                    $label = 'IP address';
                    require TEMPLATE_PATH . '/partials/sort_th.php';
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="empty-cell">No audit entries match your search.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['created_at']) ?></td>
                        <td>
                            <?php if ($row['user_name'] !== null): ?>
                                <?= e($row['user_name']) ?> <small class="muted">(<?= e($row['user_role']) ?>)</small>
                            <?php else: ?>
                                <span class="muted">System / guest</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($row['action']) ?></code></td>
                        <td><?= e($row['entity']) ?><?= $row['entity_id'] !== null ? ' #' . (int) $row['entity_id'] : '' ?></td>
                        <td><?= e($row['old_value'] ?? '') ?></td>
                        <td><?= e($row['new_value'] ?? '') ?></td>
                        <td><?= e($row['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php require TEMPLATE_PATH . '/partials/pagination.php'; ?>
</section>
