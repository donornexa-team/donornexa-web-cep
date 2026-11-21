<?php
/**
 * One request with its ranked donors (hospital only).
 * Variables: $request, $matches.
 *
 * Privacy: a donor's phone number appears ONLY for a donor who accepted (the
 * query returns NULL for everyone else), and the first reveal is written to
 * audit_logs by RequestController::show().
 */

use DonorNexa\Core\Csrf;

$matchLabels = [
    'notified'    => 'Waiting for reply',
    'accepted'    => 'Accepted',
    'declined'    => 'Declined',
    'no_response' => 'No response',
    'donated'     => 'Donated',
];
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/requests.css')) ?>">
<script src="<?= e(asset('js/request_actions.js')) ?>" defer></script>

<section aria-labelledby="request-heading">
    <p><a href="<?= e(url('/hospital/requests')) ?>">&laquo; All my requests</a></p>

    <div class="page-head">
        <h1 id="request-heading">Request #<?= $request->id ?>: <?= e($request->bloodGroup) ?></h1>
        <span class="req-badge req-<?= e($request->urgency) ?>"><?= e(ucfirst($request->urgency)) ?></span>
    </div>

    <?php if ($request->pastDeadline): ?>
        <div class="alert alert-warning" role="status">The deadline for this request has passed. It is no longer shown on the public board.</div>
    <?php endif; ?>

    <div class="card">
        <dl class="detail-list">
            <div><dt>Status</dt><dd><?= e(ucfirst($request->status)) ?></dd></div>
            <div><dt>Blood group</dt><dd><?= e($request->bloodGroup) ?></dd></div>
            <div><dt>Units</dt><dd><?= $request->unitsFulfilled ?> of <?= $request->unitsNeeded ?> received</dd></div>
            <div><dt>Needed by</dt><dd><?= e($request->requiredBy) ?></dd></div>
            <div><dt>Ward / notes</dt><dd><?= $request->hasNotes() ? e($request->wardNotes) : '<span class="muted">None</span>' ?></dd></div>
            <div><dt>Raised on</dt><dd><?= e($request->createdAt) ?></dd></div>
        </dl>
    </div>

    <?php if ($request->isOpen()): ?>
        <div class="card request-actions">
            <h2>Manage this request</h2>
            <p class="muted">Cancelling lowers your hospital's trust score. Close the request instead if you simply do not need the blood any more.</p>
            <div class="inbox-actions">
                <form method="post" action="<?= e(url('/hospital/requests/' . $request->id . '/close')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-outline" data-confirm="Close this request? Donors will be told.">Close request</button>
                </form>
                <form method="post" action="<?= e(url('/hospital/requests/' . $request->id . '/cancel')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-danger" data-confirm="Cancel this request? This lowers your hospital's trust score.">Cancel request</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <h2>Matched donors</h2>
    <p class="muted">
        Ranked by match score (out of 100): exact blood group, same city, time since the last donation and past response rate.
        A donor's phone number appears only after the donor accepts.
    </p>

    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Donors matched to request <?= $request->id ?>, best match first</caption>
            <thead>
                <tr>
                    <th scope="col">Rank</th>
                    <th scope="col">Donor</th>
                    <th scope="col">Blood group</th>
                    <th scope="col">City</th>
                    <th scope="col">Score</th>
                    <th scope="col">Answer</th>
                    <th scope="col">Phone</th>
                    <th scope="col">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($matches)): ?>
                    <tr><td colspan="8" class="empty-cell">No eligible donor matched this request.</td></tr>
                <?php endif; ?>

                <?php foreach ($matches as $rank => $match): ?>
                    <tr>
                        <td><?= (int) $rank + 1 ?></td>
                        <td><?= e($match['donor_name']) ?></td>
                        <td><?= e($match['blood_group']) ?></td>
                        <td><?= e($match['city_name']) ?></td>
                        <td><?= e(number_format((float) $match['score'], 1)) ?></td>
                        <td><?= e($matchLabels[$match['status']] ?? $match['status']) ?>
                            <?php if ($match['decline_reason'] !== null && $match['decline_reason'] !== ''): ?>
                                <br><small class="muted"><?= e($match['decline_reason']) ?></small>
                            <?php endif; ?></td>
                        <td>
                            <?php if ($match['phone'] !== null): ?>
                                <a href="tel:<?= e($match['phone']) ?>"><?= e($match['phone']) ?></a>
                            <?php else: ?>
                                <span class="muted">Hidden until the donor accepts</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($match['status'] === 'accepted' && $request->isOpen()): ?>
                                <form method="post" action="<?= e(url('/hospital/requests/' . $request->id . '/matches/' . (int) $match['id'] . '/donated')) ?>">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn-small" data-confirm="Confirm that this donor has donated blood?">Mark donated</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
