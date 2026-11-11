<?php
/** Hospital registration. Variables: $old, $errors, $cities (id, city_name). */

use DonorNexa\Core\Csrf;

$cityOptions = [];
foreach ($cities as $city) {
    $cityOptions[$city['id']] = $city['city_name'];
}
?>
<link rel="stylesheet" href="<?= e(asset('css/accounts.css')) ?>">

<section class="auth-card card" aria-labelledby="hospital-heading">
    <h1 id="hospital-heading">Register your hospital</h1>
    <p class="form-intro">Your account stays <strong>pending</strong> until an administrator verifies your licence.
        Fields marked <span class="required-mark" aria-hidden="true">*</span> are required.</p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert">Please correct the highlighted fields.</div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/register/hospital')) ?>" enctype="multipart/form-data" novalidate data-validate>
        <?= Csrf::field() ?>

        <?php
        $field = ['name' => 'hospital_name', 'label' => 'Hospital name', 'required' => true, 'rule' => 'hospital_name',
                  'attrs' => ['maxlength' => 150]];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'rule' => 'email',
                  'attrs' => ['autocomplete' => 'email', 'maxlength' => 150]];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'rule' => 'password',
                  'hint' => 'At least 8 characters, with a letter and a number.',
                  'attrs' => ['autocomplete' => 'new-password', 'maxlength' => 72]];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'password_confirm', 'label' => 'Confirm password', 'type' => 'password', 'required' => true,
                  'rule' => 'password_confirm', 'attrs' => ['autocomplete' => 'new-password', 'maxlength' => 72]];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'licence_no', 'label' => 'Licence number', 'required' => true, 'rule' => 'licence_no',
                  'attrs' => ['maxlength' => 50]];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'licence_file', 'label' => 'Licence document (PDF)', 'type' => 'file', 'required' => true,
                  'rule' => 'pdf', 'hint' => 'PDF only, 5 MB at most.',
                  'attrs' => ['accept' => 'application/pdf,.pdf']];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'city_id', 'label' => 'City', 'required' => true,
                  'options' => $cityOptions, 'placeholder' => 'Choose the hospital city'];
        require TEMPLATE_PATH . '/partials/form_select.php';

        $field = ['name' => 'address', 'label' => 'Address', 'rule' => 'address',
                  'attrs' => ['maxlength' => 255, 'autocomplete' => 'street-address']];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'phone', 'label' => 'Contact mobile number', 'type' => 'tel', 'required' => true, 'rule' => 'phone',
                  'hint' => 'Format: 0300-1234567',
                  'attrs' => ['placeholder' => '0300-1234567', 'maxlength' => 12, 'autocomplete' => 'tel']];
        require TEMPLATE_PATH . '/partials/form_field.php';
        ?>

        <p class="form-actions"><button type="submit" class="btn">Register hospital</button></p>
    </form>

    <p class="auth-switch">Already registered? <a href="<?= e(url('/login')) ?>">Log in</a>.</p>
</section>

<script src="<?= e(asset('js/register.js')) ?>" defer></script>
