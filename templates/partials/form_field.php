<?php
/**
 * One labelled text-like input with its error message.
 * Set $field before including this file; $old and $errors come from the page:
 *
 *   $field = ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true,
 *             'hint' => 'Optional help text', 'rule' => 'email',
 *             'attrs' => ['autocomplete' => 'email', 'maxlength' => 150]];
 *   require TEMPLATE_PATH . '/partials/form_field.php';
 *
 * 'rule' tells public/assets/js/register.js which check to run in the browser.
 * The empty <p class="field-error"> is always printed so JavaScript can fill it.
 */
$fName  = $field['name'];
$fType  = $field['type'] ?? 'text';
$fId    = 'f-' . $fName;
$fValue = ($fType === 'password') ? '' : (string) ($old[$fName] ?? '');
$fError = (string) ($errors[$fName] ?? '');
$fHint  = $field['hint'] ?? '';
$fDesc  = $fId . '-error' . ($fHint !== '' ? ' ' . $fId . '-hint' : '');
?>
<div class="form-group">
    <label for="<?= e($fId) ?>">
        <?= e($field['label']) ?><?php if (!empty($field['required'])): ?> <span class="required-mark" aria-hidden="true">*</span><?php endif; ?>
    </label>
    <input type="<?= e($fType) ?>" id="<?= e($fId) ?>" name="<?= e($fName) ?>"
           value="<?= e($fValue) ?>"
           data-rule="<?= e($field['rule'] ?? '') ?>"
           aria-describedby="<?= e($fDesc) ?>"
           <?= !empty($field['required']) ? 'required' : '' ?>
           <?php foreach (($field['attrs'] ?? []) as $attrName => $attrValue): ?>
           <?= e($attrName) ?>="<?= e($attrValue) ?>"
           <?php endforeach; ?>
           <?= $fError !== '' ? 'class="input-invalid" aria-invalid="true"' : '' ?>>
    <?php if ($fHint !== ''): ?>
        <p class="form-hint" id="<?= e($fId) ?>-hint"><?= e($fHint) ?></p>
    <?php endif; ?>
    <p class="field-error" id="<?= e($fId) ?>-error" aria-live="polite"><?= e($fError) ?></p>
</div>
