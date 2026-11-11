<?php
/**
 * One labelled drop-down list with its error message.
 *
 *   $field = ['name' => 'city_id', 'label' => 'City', 'required' => true,
 *             'options' => [1 => 'Karachi', 2 => 'Hyderabad'], 'placeholder' => 'Choose your city'];
 *   require TEMPLATE_PATH . '/partials/form_select.php';
 */
$fName  = $field['name'];
$fId    = 'f-' . $fName;
$fValue = (string) ($old[$fName] ?? '');
$fError = (string) ($errors[$fName] ?? '');
?>
<div class="form-group">
    <label for="<?= e($fId) ?>">
        <?= e($field['label']) ?><?php if (!empty($field['required'])): ?> <span class="required-mark" aria-hidden="true">*</span><?php endif; ?>
    </label>
    <select id="<?= e($fId) ?>" name="<?= e($fName) ?>"
            data-rule=""
            aria-describedby="<?= e($fId) ?>-error"
            <?= !empty($field['required']) ? 'required' : '' ?>
            <?= $fError !== '' ? 'class="input-invalid" aria-invalid="true"' : '' ?>>
        <option value=""><?= e($field['placeholder'] ?? 'Choose...') ?></option>
        <?php foreach ($field['options'] as $optValue => $optLabel): ?>
            <option value="<?= e($optValue) ?>"<?= ((string) $optValue === $fValue) ? ' selected' : '' ?>><?= e($optLabel) ?></option>
        <?php endforeach; ?>
    </select>
    <p class="field-error" id="<?= e($fId) ?>-error" aria-live="polite"><?= e($fError) ?></p>
</div>
