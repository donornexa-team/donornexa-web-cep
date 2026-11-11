<?php
/** Donor registration. Variables: $old, $errors, $cities (id, city_name), $bloodGroups. */

use DonorNexa\Core\Csrf;

$cityOptions = [];
foreach ($cities as $city) {
    $cityOptions[$city['id']] = $city['city_name'];
}
$groupOptions = array_combine($bloodGroups, $bloodGroups);
?>
<link rel="stylesheet" href="<?= e(asset('css/accounts.css')) ?>">

<section class="auth-card card" aria-labelledby="donor-heading">
    <h1 id="donor-heading">Become a donor</h1>
    <p class="form-intro">Create your donor account. Hospitals only see your phone number after you accept a request.
        Fields marked <span class="required-mark" aria-hidden="true">*</span> are required.</p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" role="alert">Please correct the highlighted fields.</div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/register/donor')) ?>" novalidate data-validate>
        <?= Csrf::field() ?>

        <?php
        $field = ['name' => 'name', 'label' => 'Full name', 'required' => true, 'rule' => 'name',
                  'attrs' => ['autocomplete' => 'name', 'maxlength' => 100]];
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

        $field = ['name' => 'blood_group', 'label' => 'Blood group', 'required' => true,
                  'options' => $groupOptions, 'placeholder' => 'Choose your blood group'];
        require TEMPLATE_PATH . '/partials/form_select.php';

        $field = ['name' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'required' => true, 'rule' => 'dob',
                  'hint' => 'You must be at least 18 years old.',
                  'attrs' => ['max' => date('Y-m-d')]];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'weight_kg', 'label' => 'Weight (kg)', 'type' => 'number', 'required' => true, 'rule' => 'weight',
                  'hint' => 'Donors need to weigh at least 50 kg to be matched with requests.',
                  'attrs' => ['min' => 30, 'max' => 250, 'step' => '0.1', 'inputmode' => 'decimal']];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'cnic', 'label' => 'CNIC', 'required' => true, 'rule' => 'cnic',
                  'hint' => 'Format: 12345-1234567-1',
                  'attrs' => ['placeholder' => '12345-1234567-1', 'maxlength' => 15, 'inputmode' => 'numeric']];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'city_id', 'label' => 'City', 'required' => true,
                  'options' => $cityOptions, 'placeholder' => 'Choose your city'];
        require TEMPLATE_PATH . '/partials/form_select.php';

        $field = ['name' => 'phone', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true, 'rule' => 'phone',
                  'hint' => 'Format: 0300-1234567',
                  'attrs' => ['placeholder' => '0300-1234567', 'maxlength' => 12, 'autocomplete' => 'tel']];
        require TEMPLATE_PATH . '/partials/form_field.php';
        ?>

        <p class="form-actions"><button type="submit" class="btn">Create donor account</button></p>
    </form>

    <p class="auth-switch">Already registered? <a href="<?= e(url('/login')) ?>">Log in</a>.</p>
</section>

<script src="<?= e(asset('js/register.js')) ?>" defer></script>
