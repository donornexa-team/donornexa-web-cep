<?php
/** Login page. Variables: $old (email), $errors (['form' => message]). */

use DonorNexa\Core\Csrf;
?>
<link rel="stylesheet" href="<?= e(asset('css/accounts.css')) ?>">

<section class="auth-card card" aria-labelledby="login-heading">
    <h1 id="login-heading">Log in</h1>

    <?php if (!empty($errors['form'])): ?>
        <div class="alert alert-error" role="alert"><?= e($errors['form']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/login')) ?>">
        <?= Csrf::field() ?>

        <?php
        $field = ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true,
                  'attrs' => ['autocomplete' => 'email', 'maxlength' => 150]];
        require TEMPLATE_PATH . '/partials/form_field.php';

        $field = ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true,
                  'attrs' => ['autocomplete' => 'current-password']];
        require TEMPLATE_PATH . '/partials/form_field.php';
        ?>

        <p class="form-actions"><button type="submit" class="btn">Log in</button></p>
    </form>

    <p class="auth-switch">
        New here? <a href="<?= e(url('/register/donor')) ?>">Become a donor</a>
        or <a href="<?= e(url('/register/hospital')) ?>">register your hospital</a>.
    </p>
</section>
