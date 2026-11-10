<?php
/**
 * Public "Active Emergencies" board. Variables: $requests (see PublicRepository).
 * Shows blood group, city, urgency and a countdown ONLY: never patient names,
 * phone numbers or hospital details.
 */
?>
<link rel="stylesheet" href="<?= e(asset('css/public.css')) ?>">

<section aria-labelledby="board-heading">
    <h1 id="board-heading">Active Emergencies</h1>
    <p>These patients need blood now. Register as a donor and we will notify you when a request matches you.</p>

    <?php if (empty($requests)): ?>
        <div class="card empty-state">
            <p>There are no active emergencies right now. Thank you for checking.</p>
        </div>
    <?php else: ?>
        <ul class="emergency-grid">
            <?php foreach ($requests as $request): ?>
                <li>
                    <article class="emergency-card urgency-<?= e($request['urgency']) ?>">
                        <header class="emergency-head">
                            <span class="blood-group"><?= e($request['blood_group']) ?></span>
                            <span class="badge badge-<?= e($request['urgency']) ?>"><?= e(ucfirst($request['urgency'])) ?></span>
                        </header>
                        <p class="emergency-city"><span class="visually-hidden">City: </span><?= e($request['city_name']) ?></p>
                        <p class="emergency-time">
                            Time left:
                            <strong class="countdown" data-seconds-left="<?= (int) $request['seconds_left'] ?>">&ndash;</strong>
                        </p>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<script src="<?= e(asset('js/emergencies.js')) ?>" defer></script>
