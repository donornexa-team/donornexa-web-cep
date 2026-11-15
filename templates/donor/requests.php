<?php
/**
 * The donor's inbox: blood requests matched to this donor.
 * Variables: $list (ListQuery), $rows, $statuses, $status.
 * Accept / decline are POST forms (CSRF token); the server decides whether the
 * answer is still allowed, so the buttons are only shown as a convenience.
 */

use DonorNexa\Core\Csrf;

$matchLabels = [
    'notified'    => 'Waiting for your answer',
    'accepted'    => 'You accepted',
    'declined'    => 'You declined',
    'no_response' => 'No response',
    'donated'     => 'Donated',
];
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/requests.css')) ?>">
<script src="<?= e(asset('js/request_actions.js')) ?>" defer></script>

<section aria-labelledby="inbox-heading">
    <h1 id="inbox-heading">Requests for me</h1>
    <p>Hospitals that need your blood group asked for you. Only answer if you can donate: if you accept, the hospital sees your phone number.</p>

    <form method="get" action="<?= e(url('/donor/requests')) ?>" class="list-filters" role="search">
        <div class="filter-field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($list->search()) ?>" maxlength="100" placeholder="Hospital or blood group">
        </div>

        <div class="filter-field">
            <label for="status">Your answer</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach ($statuses as $name): ?>
                    <option value="<?= e($name) ?>"<?= $name === $status ? ' selected' : '' ?>><?= e($matchLabels[$name]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <input type="hidden" name="sort" value="<?= e($list->sortKey()) ?>">
        <input type="hidden" name="dir" value="<?= e(strtolower($list->direction())) ?>">

        <div class="filter-actions">
            <button type="submit" class="btn btn-small">Apply</button>
            <a class="btn btn-small btn-outline" href="<?= e(url('/donor/requests')) ?>">Reset</a>
        </div>
    </form>

    <?php if (empty($rows)): ?>
        <p class="empty-cell">No requests found.</p>
    <?php endif; ?>

    <ul class="inbox-list">
        <?php foreach ($rows as $row): ?>
            <?php
            $isOpen  = ($row['request_status'] === 'open' && (int) $row['past_deadline'] === 0);
            $isFull  = ((int) $row['committed'] >= (int) $row['units_needed']);
            $canAnswer = ($row['match_status'] === 'notified' && $isOpen && !$isFull);
            ?>
            <li class="card inbox-item">
                <div class="page-head">
                    <h2><?= e($row['blood_group']) ?> needed at <?= e($row['hospital_name']) ?></h2>
                    <span class="req-badge req-<?= e($row['urgency']) ?>"><?= e(ucfirst($row['urgency'])) ?></span>
                </div>

                <dl class="detail-list">
                    <div><dt>City</dt><dd><?= e($row['city_name']) ?></dd></div>
                    <div><dt>Units needed</dt><dd><?= (int) $row['units_needed'] ?></dd></div>
                    <div><dt>Needed by</dt><dd><?= e($row['required_by']) ?></dd></div>
                    <div><dt>Ward / notes</dt><dd><?= $row['ward_notes'] !== null && $row['ward_notes'] !== '' ? e($row['ward_notes']) : '<span class="muted">None</span>' ?></dd></div>
                    <div><dt>Your answer</dt><dd><?= e($matchLabels[$row['match_status']] ?? $row['match_status']) ?>
                        <?php if ($row['decline_reason'] !== null && $row['decline_reason'] !== ''): ?>
                            <span class="muted">(<?= e($row['decline_reason']) ?>)</span>
                        <?php endif; ?></dd></div>
                </dl>

                <?php if ($canAnswer): ?>
                    <div class="inbox-actions">
                        <form method="post" action="<?= e(url('/donor/requests/' . (int) $row['request_id'] . '/accept')) ?>">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn" data-confirm="Accept this request? The hospital will see your phone number.">Accept</button>
                        </form>

                        <form method="post" action="<?= e(url('/donor/requests/' . (int) $row['request_id'] . '/decline')) ?>" class="decline-form">
                            <?= Csrf::field() ?>
                            <label for="reason-<?= (int) $row['request_id'] ?>" class="visually-hidden">Reason for declining (optional)</label>
                            <input type="text" id="reason-<?= (int) $row['request_id'] ?>" name="reason" maxlength="255" placeholder="Reason (optional)">
                            <button type="submit" class="btn btn-outline">Decline</button>
                        </form>
                    </div>
                <?php elseif ($row['match_status'] === 'notified'): ?>
                    <p class="muted">
                        <?= $isOpen ? 'Enough donors have already accepted this request.' : 'This request is no longer open (' . e($row['request_status']) . ').' ?>
                    </p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php require TEMPLATE_PATH . '/partials/pagination.php'; ?>
</section>
