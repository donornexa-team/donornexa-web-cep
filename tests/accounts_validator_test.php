<?php
/**
 * Test script for the registration rules (AccountValidator and FileUploader).
 * Run from the project folder:
 *
 *     php test/accounts_validator_test.php
 *
 * It needs no database. Prints PASS/FAIL for each check; the output can be
 * used for the test-case table of the report.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Services\AccountValidator;
use DonorNexa\Services\FileUploader;

$validator = new AccountValidator();
$failed    = 0;

function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

/** True when validating $changes on top of $base reports an error for $field. */
function rejects(AccountValidator $v, array $base, array $changes, string $field, array $cityIds): bool
{
    $errors = $v->validateDonor(array_merge($base, $changes), $cityIds);
    return isset($errors[$field]);
}

$cityIds = [1, 2, 3];
$donor = [
    'name'             => 'Ayesha Khan',
    'email'            => 'ayesha.khan@mail.test',
    'password'         => 'Secret123',
    'password_confirm' => 'Secret123',
    'blood_group'      => 'O-',
    'dob'              => '1995-06-15',
    'weight_kg'        => '62.5',
    'cnic'             => '42101-1234567-1',
    'city_id'          => '1',
    'phone'            => '0300-1234567',
];

echo PHP_EOL, '1. Donor form: valid input', PHP_EOL;
check('a fully valid donor form has no errors', $validator->validateDonor($donor, $cityIds) === []);
check('a donor who is exactly 18 today is accepted',
    $validator->validateDonor(array_merge($donor, ['dob' => (new DateTimeImmutable('-18 years'))->format('Y-m-d')]), $cityIds) === []);

echo PHP_EOL, '2. Donor form: invalid input is rejected', PHP_EOL;
check('mobile without dash (03001234567)', rejects($validator, $donor, ['phone' => '03001234567'], 'phone', $cityIds));
check('mobile not starting with 03 (0400-1234567)', rejects($validator, $donor, ['phone' => '0400-1234567'], 'phone', $cityIds));
check('CNIC without dashes', rejects($validator, $donor, ['cnic' => '4210112345671'], 'cnic', $cityIds));
check('CNIC with letters', rejects($validator, $donor, ['cnic' => '4210A-1234567-1'], 'cnic', $cityIds));
check('email without @', rejects($validator, $donor, ['email' => 'not-an-email'], 'email', $cityIds));
check('name with digits', rejects($validator, $donor, ['name' => 'Ali123'], 'name', $cityIds));
check('password without a number', rejects($validator, $donor, ['password' => 'abcdefgh', 'password_confirm' => 'abcdefgh'], 'password', $cityIds));
check('password without a letter', rejects($validator, $donor, ['password' => '12345678', 'password_confirm' => '12345678'], 'password', $cityIds));
check('password shorter than 8 characters', rejects($validator, $donor, ['password' => 'Sh0rt', 'password_confirm' => 'Sh0rt'], 'password', $cityIds));
check('password longer than 72 characters', rejects($validator, $donor, ['password' => str_repeat('a1', 40), 'password_confirm' => str_repeat('a1', 40)], 'password', $cityIds));
check('password confirmation that differs', rejects($validator, $donor, ['password_confirm' => 'Secret124'], 'password_confirm', $cityIds));
check('unknown blood group (C+)', rejects($validator, $donor, ['blood_group' => 'C+'], 'blood_group', $cityIds));
check('city that does not exist', rejects($validator, $donor, ['city_id' => '99'], 'city_id', $cityIds));
check('city id that is not a number', rejects($validator, $donor, ['city_id' => 'abc'], 'city_id', $cityIds));
check('weight below 30 kg', rejects($validator, $donor, ['weight_kg' => '20'], 'weight_kg', $cityIds));
check('weight above 250 kg', rejects($validator, $donor, ['weight_kg' => '300'], 'weight_kg', $cityIds));
check('weight that is not a number', rejects($validator, $donor, ['weight_kg' => 'heavy'], 'weight_kg', $cityIds));

echo PHP_EOL, '3. Date of birth', PHP_EOL;
check('17 years old is rejected', rejects($validator, $donor, ['dob' => (new DateTimeImmutable('-17 years'))->format('Y-m-d')], 'dob', $cityIds));
check('a date in the future is rejected', rejects($validator, $donor, ['dob' => (new DateTimeImmutable('+1 day'))->format('Y-m-d')], 'dob', $cityIds));
check('an impossible date (2000-02-30) is rejected', rejects($validator, $donor, ['dob' => '2000-02-30'], 'dob', $cityIds));
check('a wrong format (15/06/1995) is rejected', rejects($validator, $donor, ['dob' => '15/06/1995'], 'dob', $cityIds));

echo PHP_EOL, '4. Hospital form', PHP_EOL;
$hospital = [
    'hospital_name'    => 'City General Hospital',
    'email'            => 'admin@citygeneral.test',
    'password'         => 'Secret123',
    'password_confirm' => 'Secret123',
    'licence_no'       => 'SHC/2024-0147',
    'city_id'          => '2',
    'address'          => '',
    'phone'            => '0321-7654321',
];
check('a fully valid hospital form has no errors (address is optional)', $validator->validateHospital($hospital, $cityIds) === []);
check('licence number with spaces is rejected',
    isset($validator->validateHospital(array_merge($hospital, ['licence_no' => 'AB 12']), $cityIds)['licence_no']));
check('empty hospital name is rejected',
    isset($validator->validateHospital(array_merge($hospital, ['hospital_name' => '']), $cityIds)['hospital_name']));
check('address longer than 255 characters is rejected',
    isset($validator->validateHospital(array_merge($hospital, ['address' => str_repeat('x', 256)]), $cityIds)['address']));

echo PHP_EOL, '5. Licence upload (FileUploader)', PHP_EOL;
$uploader = new FileUploader();
check('no file selected is rejected', $uploader->pdfError(null) !== null);
check('"no file" upload error code is rejected', $uploader->pdfError(['error' => UPLOAD_ERR_NO_FILE]) !== null);
check('a file that did not come through an HTTP upload is rejected',
    $uploader->pdfError(['error' => UPLOAD_ERR_OK, 'size' => 100, 'tmp_name' => __FILE__, 'name' => 'licence.pdf']) !== null);

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
