<?php
/**
 * One request with its ranked donors (hospital only).
 * Variables: $request, $matches.
 *
 * Privacy: a donor's phone number is NOT shown here. It stays hidden until the
 * donor accepts. (The accept/decline feature will add the phone for accepted
 * matches and write the reveal to audit_logs.)
 */

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

<section aria-labelledby="request-heading">
    <p><a href="<?= e(url('/hospital/requests')) ?>">&laquo; All my requests</a></p>

    <div class="page-head">
        <h1 id="request-heading">Request #<?= (int) $request['id'] ?>: <?= e($request['blood_group']) ?></h1>
        <span class="req-badge req-<?= e($request['urgency']) ?>"><?= e(ucfirst($request['urgency'])) ?></span>
    </div>

    <?php if ($request['past_deadline']): ?>
        <div class="alert alert-warning" role="status">The deadline for this request has passed. It is no longer shown on the public board.</div>
    <?php endif; ?>

    <div class="card">
        <dl class="detail-list">
            <div><dt>Status</dt><dd><?= e(ucfirst($request['status'])) ?></dd></div>
            <div><dt>Blood group</dt><dd><?= e($request['blood_group']) ?></dd></div>
            <div><dt>Units</dt><dd><?= (int) $request['units_fulfilled'] ?> of <?= (int) $request['units_needed'] ?> received</dd></div>
            <div><dt>Needed by</dt><dd><?= e($request['required_by']) ?></dd></div>
            <div><dt>Ward / notes</dt><dd><?= $request['ward_notes'] !== null && $request['ward_notes'] !== '' ? e($request['ward_notes']) : '<span class="muted">None</span>' ?></dd></div>
            <div><dt>Raised on</dt><dd><?= e($request['created_at']) ?></dd></div>
        </dl>
    </div>

    <h2>Matched donors</h2>
    <p class="muted">
        Ranked by match score (out of 100): exact blood group, same city, time since the last donation and past response rate.
        Phone numbers stay hidden until a donor accepts.
    </p>

    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Donors matched to request <?= (int) $request['id'] ?>, best match first</caption>
            <thead>
                <tr>
                    <th scope="col">Rank</th>
                    <th scope="col">Donor</th>
                    <th scope="col">Blood group</th>
                    <th scope="col">City</th>
                    <th scope="col">Score</th>
                    <th scope="col">Answer</th>
                    <th scope="col">Phone</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($matches)): ?>
                    <tr><td colspan="7" class="empty-cell">No eligible donor matched this request.</td></tr>
                <?php endif; ?>

                <?php foreach ($matches as $rank => $match): ?>
                    <tr>
                        <td><?= (int) $rank + 1 ?></td>
                        <td><?= e($match['donor_name']) ?></td>
                        <td><?= e($match['blood_group']) ?></td>
                        <td><?= e($match['city_name']) ?></td>
                        <td><?= e(number_format((float) $match['score'], 1)) ?></td>
                        <td><?= e($matchLabels[$match['status']] ?? $match['status']) ?></td>
                        <td><span class="muted">Hidden until the donor accepts</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
