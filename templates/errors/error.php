<?php
/** Friendly error page. Variables: $code, $title, $message. */
?>
<section class="error-page">
    <p class="error-code"><?= e($code) ?></p>
    <h1><?= e($title) ?></h1>
    <p><?= e($message) ?></p>
    <p>
        <a class="btn" href="<?= e(url('/')) ?>">Back to home</a>
        <?php if ((int) $code === 401 || (int) $code === 403): ?>
            <a class="btn btn-outline" href="<?= e(url('/login')) ?>">Login</a>
        <?php endif; ?>
    </p>
</section>
