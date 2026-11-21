<?php
/**
 * Admin: system settings and cities.
 * Variables: $settings (rows), $rules (key => SettingRule), $old, $errors, $cities, $provinces.
 */

use DonorNexa\Core\Csrf;

$labels = [
    'cooldown_days'            => 'Cool-down between donations (days)',
    'top_n_notify'             => 'Donors notified first for a normal request',
    'critical_pool_multiplier' => 'Critical requests: pool multiplier',
    'max_open_critical'        => 'Open Critical requests allowed per hospital',
    'no_response_hours'        => 'Hours before an unanswered notification is "No response"',
    'trust_penalty_cancel'     => 'Trust points lost when a hospital cancels a request',
];
?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/accounts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/account_pages.css')) ?>">

<section aria-labelledby="settings-heading">
    <h1 id="settings-heading">Settings and cities</h1>

    <h2>System settings</h2>
    <p class="muted">The new values apply to the next request or donor check. Every change is written to the audit log.</p>

    <form method="post" action="<?= e(url('/admin/settings')) ?>" class="card" novalidate>
        <?= Csrf::field() ?>
        <?php foreach ($settings as $row): ?>
            <?php
            $key   = $row['setting_key'];
            $value = (string) ($old[$key] ?? $row['setting_value']);
            $error = (string) ($errors[$key] ?? '');
            ?>
            <div class="form-group">
                <label for="s-<?= e($key) ?>"><?= e($labels[$key] ?? $key) ?></label>
                <input type="text" inputmode="numeric" id="s-<?= e($key) ?>" name="<?= e($key) ?>"
                       value="<?= e($value) ?>" maxlength="6"
                       aria-describedby="s-<?= e($key) ?>-hint s-<?= e($key) ?>-error"
                       <?= $error !== '' ? 'class="input-invalid" aria-invalid="true"' : '' ?>>
                <p class="form-hint" id="s-<?= e($key) ?>-hint"><?= e(ucfirst($rules[$key]->describe())) ?></p>
                <p class="field-error" id="s-<?= e($key) ?>-error" aria-live="polite"><?= e($error) ?></p>
            </div>
        <?php endforeach; ?>
        <p class="form-actions"><button type="submit" class="btn">Save settings</button></p>
    </form>

    <h2>Cities</h2>
    <p class="muted">Cities appear in the registration forms. A city cannot be deleted because donors and hospitals refer to it; rename it instead.</p>

    <div class="table-wrap">
        <table class="data-table">
            <caption class="visually-hidden">Cities</caption>
            <thead>
                <tr>
                    <th scope="col">City</th>
                    <th scope="col">Province</th>
                    <th scope="col">Donors</th>
                    <th scope="col">Hospitals</th>
                    <th scope="col">Save</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cities as $city): ?>
                    <?php $id = (int) $city['id']; ?>
                    <tr>
                        <td><input type="text" name="city_name" form="city-<?= $id ?>" value="<?= e($city['city_name']) ?>"
                                   maxlength="80" required aria-label="City name"></td>
                        <td>
                            <select name="province" form="city-<?= $id ?>" aria-label="Province">
                                <?php foreach ($provinces as $province): ?>
                                    <option value="<?= e($province) ?>"<?= $province === $city['province'] ? ' selected' : '' ?>><?= e($province) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><?= (int) $city['donors'] ?></td>
                        <td><?= (int) $city['hospitals'] ?></td>
                        <td><button type="submit" form="city-<?= $id ?>" class="btn btn-small btn-outline">Save</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php /* One small form per city, outside the table (a <form> cannot sit directly inside a <tr>); the inputs join it with form="city-ID". */ ?>
    <?php foreach ($cities as $city): ?>
        <form method="post" action="<?= e(url('/admin/cities/' . (int) $city['id'])) ?>" id="city-<?= (int) $city['id'] ?>"><?= Csrf::field() ?></form>
    <?php endforeach; ?>

    <h3>Add a city</h3>
    <form method="post" action="<?= e(url('/admin/cities')) ?>" class="card">
        <?= Csrf::field() ?>
        <div class="form-group">
            <label for="new-city">City name</label>
            <input type="text" id="new-city" name="city_name" maxlength="80" required>
        </div>
        <div class="form-group">
            <label for="new-province">Province</label>
            <select id="new-province" name="province" required>
                <option value="">Choose...</option>
                <?php foreach ($provinces as $province): ?>
                    <option value="<?= e($province) ?>"><?= e($province) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <p class="form-actions"><button type="submit" class="btn">Add city</button></p>
    </form>
</section>
