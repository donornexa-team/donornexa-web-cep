<?php
/**
 * New blood request (hospital only).
 * Variables: $hospital (hospital_name, city_name, verification_status), $verified (bool),
 *            $openCritical, $maxCritical, $bloodGroups, $old, $errors.
 */

use DonorNexa\Core\Csrf;

$groupOptions = [];
foreach ($bloodGroups as $group) {
    $groupOptions[$group] = $group;
}
?>
<link rel="stylesheet" href="<?= e(asset('css/requests.css')) ?>">

<section class="request-card card" aria-labelledby="request-heading">
    <h1 id="request-heading">New blood request</h1>

    <?php if (!$verified): ?>
        <div class="alert alert-info" role="status">
            <?php if ($hospital['verification_status'] === 'rejected'): ?>
                Your hospital registration was rejected, so you cannot raise blood requests. Please contact the administrator.
            <?php else: ?>
                Your hospital is <strong>pending verification</strong>. You can raise blood requests as soon as an administrator approves your licence.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="form-intro">
            Raising a request for <strong><?= e($hospital['hospital_name']) ?></strong> (<?= e($hospital['city_name']) ?>).
            Eligible donors with a compatible blood group are matched and notified automatically.
            Fields marked <span class="required-mark" aria-hidden="true">*</span> are required.
        </p>

        <p class="critical-meter" role="status">
            Open critical requests: <strong><?= (int) $openCritical ?> of <?= (int) $maxCritical ?></strong>
            <?php if ($openCritical >= $maxCritical): ?>
                <span class="critical-full">&ndash; limit reached, choose Urgent or Normal.</span>
            <?php endif; ?>
        </p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error" role="alert">Please correct the highlighted fields.</div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/hospital/requests')) ?>" novalidate data-request-form>
            <?= Csrf::field() ?>

            <?php
            $field = ['name' => 'blood_group', 'label' => 'Blood group needed', 'required' => true,
                      'options' => $groupOptions, 'placeholder' => 'Choose the blood group'];
            require TEMPLATE_PATH . '/partials/form_select.php';

            $field = ['name' => 'units_needed', 'label' => 'Units needed', 'type' => 'number', 'required' => true,
                      'hint' => 'Between 1 and 20 units.',
                      'attrs' => ['min' => 1, 'max' => 20, 'inputmode' => 'numeric']];
            require TEMPLATE_PATH . '/partials/form_field.php';

            $field = ['name' => 'urgency', 'label' => 'Urgency', 'required' => true,
                      'options' => ['normal' => 'Normal', 'urgent' => 'Urgent', 'critical' => 'Critical'],
                      'placeholder' => 'Choose the urgency'];
            require TEMPLATE_PATH . '/partials/form_select.php';

            $field = ['name' => 'required_by', 'label' => 'Needed by', 'type' => 'datetime-local', 'required' => true,
                      'hint' => 'Date and time the blood is needed by (within the next 30 days).'];
            require TEMPLATE_PATH . '/partials/form_field.php';

            $field = ['name' => 'ward_notes', 'label' => 'Ward / notes', 'hint' => 'Ward or bed information only. Never enter a patient name.',
                      'attrs' => ['maxlength' => 255]];
            require TEMPLATE_PATH . '/partials/form_field.php';
            ?>

            <p class="form-actions">
                <button type="submit" class="btn">Raise request</button>
                <a class="btn btn-outline" href="<?= e(url('/hospital/requests')) ?>">My requests</a>
            </p>
        </form>
    <?php endif; ?>
</section>

<script src="<?= e(asset('js/request_form.js')) ?>" defer></script>
