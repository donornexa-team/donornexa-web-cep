<?php
/**
 * Donor profile. Variables: $profile (database row), $eligibility (EligibilityService result),
 * $cooldownDays, $cities, $old (form values), $errors.
 */

use DonorNexa\Core\Csrf;

$cityOptions = [];
foreach ($cities as $city) {
    $cityOptions[$city['id']] = $city['city_name'];
}

$isAvailable = ((int) $profile['is_available'] === 1);
$eligibleOn  = $eligibility['eligible_on'];
$stillWaiting = ($eligibleOn !== null && $eligibleOn > new DateTimeImmutable('today'));
?>
<link rel="stylesheet" href="<?= e(asset('css/accounts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/account_pages.css')) ?>">

<section aria-labelledby="profile-heading">
    <h1 id="profile-heading">My profile</h1>

    <div class="profile-grid">

        <article class="card profile-card" aria-labelledby="photo-heading">
            <h2 id="photo-heading">Photo</h2>
            <?php if (!empty($profile['photo'])): ?>
                <img class="avatar" src="<?= e(url('/uploads/photos/' . $profile['photo'])) ?>"
                     alt="Profile photo of <?= e($profile['name']) ?>" width="128" height="128">
            <?php else: ?>
                <div class="avatar avatar-empty" role="img" aria-label="No profile photo yet">No photo</div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/donor/photo')) ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="f-photo">Choose a photo</label>
                    <input type="file" id="f-photo" name="photo" accept="image/jpeg,image/png" required
                           aria-describedby="f-photo-hint">
                    <p class="form-hint" id="f-photo-hint">JPG or PNG, 2 MB at most.</p>
                </div>
                <button type="submit" class="btn btn-small">Upload photo</button>
            </form>
        </article>

        <article class="card profile-card" aria-labelledby="eligibility-heading">
            <h2 id="eligibility-heading">Donation eligibility</h2>

            <p>
                <?php if ($eligibility['eligible']): ?>
                    <span class="badge badge-ok">Eligible to donate</span>
                <?php else: ?>
                    <span class="badge badge-bad">Not eligible right now</span>
                <?php endif; ?>
                <?php if ($isAvailable): ?>
                    <span class="badge badge-ok">Availability on</span>
                <?php else: ?>
                    <span class="badge badge-neutral">Availability off</span>
                <?php endif; ?>
            </p>

            <?php if (!empty($eligibility['reasons'])): ?>
                <ul class="reason-list">
                    <?php foreach ($eligibility['reasons'] as $reason): ?>
                        <li><?= e($reason) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <dl class="fact-list">
                <div>
                    <dt>Last donation</dt>
                    <dd><?= !empty($profile['last_donation_date'])
                        ? e(date('d M Y', strtotime((string) $profile['last_donation_date'])))
                        : 'No donation recorded' ?></dd>
                </div>
                <?php if ($eligibleOn !== null): ?>
                    <div>
                        <dt>Eligible again on</dt>
                        <dd>
                            <?= e($eligibleOn->format('d M Y')) ?>
                            <?= $stillWaiting ? '' : '<span class="muted">(cool-down finished)</span>' ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <div>
                    <dt>Cool-down</dt>
                    <dd><?= (int) $cooldownDays ?> days between donations</dd>
                </div>
            </dl>

            <form method="post" action="<?= e(url('/donor/availability')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="is_available" value="<?= $isAvailable ? '0' : '1' ?>">
                <button type="submit" class="btn btn-small btn-outline">
                    <?= $isAvailable ? 'Switch availability off' : 'Switch availability on' ?>
                </button>
            </form>
        </article>

        <article class="card profile-card" aria-labelledby="details-heading">
            <h2 id="details-heading">Your details</h2>
            <p class="muted">These identify you and cannot be changed here. Ask an administrator if one is wrong.</p>
            <dl class="fact-list">
                <div><dt>Email</dt><dd><?= e($profile['email']) ?></dd></div>
                <div><dt>Blood group</dt><dd><?= e($profile['blood_group']) ?></dd></div>
                <div><dt>Date of birth</dt><dd><?= e(date('d M Y', strtotime((string) $profile['dob']))) ?> (age <?= (int) $eligibility['age'] ?>)</dd></div>
                <div><dt>CNIC</dt><dd><?= e($profile['cnic'] ?? '') ?></dd></div>
            </dl>
        </article>

        <article class="card profile-card profile-wide" aria-labelledby="edit-heading">
            <h2 id="edit-heading">Edit profile</h2>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" role="alert">Please correct the highlighted fields.</div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/donor/profile')) ?>" novalidate data-validate>
                <?= Csrf::field() ?>

                <?php
                $field = ['name' => 'name', 'label' => 'Full name', 'required' => true, 'rule' => 'name',
                          'attrs' => ['autocomplete' => 'name', 'maxlength' => 100]];
                require TEMPLATE_PATH . '/partials/form_field.php';

                $field = ['name' => 'phone', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true, 'rule' => 'phone',
                          'hint' => 'Hospitals only see it after you accept a request. Format: 0300-1234567',
                          'attrs' => ['placeholder' => '0300-1234567', 'maxlength' => 12, 'autocomplete' => 'tel']];
                require TEMPLATE_PATH . '/partials/form_field.php';

                $field = ['name' => 'weight_kg', 'label' => 'Weight (kg)', 'type' => 'number', 'required' => true, 'rule' => 'weight',
                          'hint' => 'Donors need to weigh at least 50 kg to be matched with requests.',
                          'attrs' => ['min' => 30, 'max' => 250, 'step' => '0.1', 'inputmode' => 'decimal']];
                require TEMPLATE_PATH . '/partials/form_field.php';

                $field = ['name' => 'city_id', 'label' => 'City', 'required' => true,
                          'options' => $cityOptions, 'placeholder' => 'Choose your city'];
                require TEMPLATE_PATH . '/partials/form_select.php';

                $field = ['name' => 'last_donation_date', 'label' => 'Last donation date', 'type' => 'date',
                          'hint' => 'Only change this if you donated somewhere else. It can only be moved forward, never back.',
                          'attrs' => ['max' => date('Y-m-d')]];
                require TEMPLATE_PATH . '/partials/form_field.php';
                ?>

                <p class="form-actions"><button type="submit" class="btn">Save changes</button></p>
            </form>
        </article>

    </div>
</section>

<script src="<?= e(asset('js/register.js')) ?>" defer></script>
