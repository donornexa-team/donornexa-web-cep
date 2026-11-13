<?php
/**
 * Test script for commit #5 (donor profile rules). Run from the project folder:
 *
 *     php tests/profile_test.php
 *
 * Needs no database. It tests EligibilityService, ProfileValidator and the
 * photo checks of FileUploader. Prints PASS/FAIL per check; the output can be
 * used for the test-case table of the report.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Services\EligibilityService;
use DonorNexa\Services\FileUploader;
use DonorNexa\Services\ProfileValidator;

$failed = 0;

function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

$today = new DateTimeImmutable('2026-11-13');

// ---------------------------------------------------------------- eligibility
echo PHP_EOL, '1. Eligibility (EligibilityService, same rules as v_eligible_donors)', PHP_EOL;

$service = new EligibilityService();
$donor = [
    'account_status'     => 'active',
    'is_available'       => 1,
    'weight_kg'          => '65.00',
    'dob'                => '1998-04-12',
    'last_donation_date' => null,
];
$status = static function (array $changes, int $cooldown = 90) use ($service, $donor, $today): array {
    return $service->status(array_merge($donor, $changes), $cooldown, $today);
};

$result = $status([]);
check('a healthy adult who never donated is eligible', $result['eligible'] === true && $result['reasons'] === []);
check('no "eligible again on" date when there is no donation', $result['eligible_on'] === null);
check('availability off is not eligible', $status(['is_available' => 0])['eligible'] === false);
check('weight 49.9 kg is not eligible', $status(['weight_kg' => '49.9'])['eligible'] === false);
check('weight 50 kg is eligible', $status(['weight_kg' => '50.00'])['eligible'] === true);
check('17 years old (turns 18 tomorrow) is not eligible', $status(['dob' => '2008-11-14'])['eligible'] === false);
check('exactly 18 today is eligible', $status(['dob' => '2008-11-13'])['eligible'] === true);
check('exactly 60 today is eligible', $status(['dob' => '1966-11-13'])['eligible'] === true);
check('61 years old is not eligible', $status(['dob' => '1965-11-13'])['eligible'] === false);
check('a blocked account is not eligible', $status(['account_status' => 'blocked'])['eligible'] === false);
check('several failing rules give several reasons', count($status(['is_available' => 0, 'weight_kg' => '40'])['reasons']) === 2);

$recent = $status(['last_donation_date' => '2026-10-01']);
check('donated on 2026-10-01: not eligible during the cool-down', $recent['eligible'] === false);
check('90 days after 2026-10-01 is 2026-12-30', $recent['eligible_on'] !== null && $recent['eligible_on']->format('Y-m-d') === '2026-12-30');
check('the reason names the date it ends', strpos(implode(' ', $recent['reasons']), '30 Dec 2026') !== false);

$ninety = $today->sub(new DateInterval('P90D'))->format('Y-m-d');
$eightyNine = $today->sub(new DateInterval('P89D'))->format('Y-m-d');
check('exactly 90 days after the last donation is eligible', $status(['last_donation_date' => $ninety])['eligible'] === true);
check('89 days after the last donation is not eligible', $status(['last_donation_date' => $eightyNine])['eligible'] === false);
check('the cool-down follows the admin setting (30 days)', $status(['last_donation_date' => $eightyNine], 30)['eligible'] === true);
check('an empty last donation date counts as never donated', $status(['last_donation_date' => ''])['eligible'] === true);
check('eligibleOn(null) is null', $service->eligibleOn(null, 90) === null);
check('eligibleOn with a 0-day cool-down is the same day', $service->eligibleOn('2026-01-01', 0)->format('Y-m-d') === '2026-01-01');

// ----------------------------------------------------------- profile validator
echo PHP_EOL, '2. Edit profile (ProfileValidator)', PHP_EOL;

$validator = new ProfileValidator();
$cityIds   = [1, 2];
$dob       = '1998-04-12';
$input = [
    'name'               => 'Ali Raza',
    'phone'              => '0300-1111111',
    'weight_kg'          => '65',
    'city_id'            => '1',
    'last_donation_date' => '',
];
$errorsFor = static function (array $changes, ?string $currentLast = null) use ($validator, $input, $cityIds, $dob, $today): array {
    return $validator->validate(array_merge($input, $changes), $cityIds, $dob, $currentLast, $today);
};

check('a valid form has no errors', $errorsFor([]) === []);
check('invalid phone is rejected', isset($errorsFor(['phone' => '03001111111'])['phone']));
check('weight 20 kg is rejected', isset($errorsFor(['weight_kg' => '20'])['weight_kg']));
check('unknown city is rejected', isset($errorsFor(['city_id' => '99'])['city_id']));
check('a name with digits is rejected', isset($errorsFor(['name' => 'Ali123'])['name']));

echo PHP_EOL, '3. Last donation date (it can only move forward)', PHP_EOL;
check('a past date is accepted when nothing is recorded yet', $errorsFor(['last_donation_date' => '2026-10-01']) === []);
check('a date in the future is rejected', isset($errorsFor(['last_donation_date' => '2026-11-14'])['last_donation_date']));
check('a date before the 18th birthday is rejected', isset($errorsFor(['last_donation_date' => '2016-01-01'])['last_donation_date']));
check('an impossible date (2026-02-30) is rejected', isset($errorsFor(['last_donation_date' => '2026-02-30'])['last_donation_date']));
check('text instead of a date is rejected', isset($errorsFor(['last_donation_date' => 'abc'])['last_donation_date']));
check('an earlier date than the recorded one is rejected', isset($errorsFor(['last_donation_date' => '2026-09-01'], '2026-10-01')['last_donation_date']));
check('clearing a recorded date is rejected', isset($errorsFor(['last_donation_date' => ''], '2026-10-01')['last_donation_date']));
check('the same date as recorded is accepted', $errorsFor(['last_donation_date' => '2026-10-01'], '2026-10-01') === []);
check('a later date than the recorded one is accepted', $errorsFor(['last_donation_date' => '2026-10-15'], '2026-10-01') === []);
check('leaving the date empty is fine when none is recorded', $errorsFor(['last_donation_date' => ''], null) === []);

// ------------------------------------------------------------------- photo upload
echo PHP_EOL, '4. Photo upload checks (FileUploader)', PHP_EOL;

$uploader = new FileUploader();
check('no file is rejected', $uploader->imageError(null) !== null);
check('"no file" upload code is rejected', $uploader->imageError(['error' => UPLOAD_ERR_NO_FILE]) !== null);
check('a file over the PHP upload limit says it is too large',
    strpos((string) $uploader->imageError(['error' => UPLOAD_ERR_INI_SIZE]), 'too large') !== false);
check('a 3 MB photo is rejected as too large',
    strpos((string) $uploader->imageError(['error' => UPLOAD_ERR_OK, 'size' => 3 * 1024 * 1024, 'tmp_name' => __FILE__, 'name' => 'a.jpg']), 'too large') !== false);
check('a file that did not come through an HTTP upload is rejected',
    $uploader->imageError(['error' => UPLOAD_ERR_OK, 'size' => 100, 'tmp_name' => __FILE__, 'name' => 'a.png']) !== null);

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
