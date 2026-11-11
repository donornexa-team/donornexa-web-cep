<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DateTimeImmutable;

/**
 * Server-side validation for the registration forms.
 *
 * It only looks at the values it is given (no database, no $_POST), so it can
 * be tested from the command line. Every check returns an error message, or
 * null when the value is fine. The public methods collect the messages into
 * an array such as ['email' => 'Enter a valid email address.'].
 *
 * The same rules are repeated in public/assets/js/register.js to give instant
 * feedback, but this class is the one that counts: JavaScript can be switched off.
 *
 * Regular expressions used (course requirement):
 *   mobile  03XX-XXXXXXX     CNIC  #####-#######-#
 */
class AccountValidator
{
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private const NAME_PATTERN     = '/^[\p{L}][\p{L} .\'-]{1,99}$/u';
    private const HOSPITAL_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} .,&()\'\/-]{2,149}$/u';
    private const PHONE_PATTERN    = '/^03\d{2}-\d{7}$/';
    private const CNIC_PATTERN     = '/^\d{5}-\d{7}-\d$/';
    private const LICENCE_PATTERN  = '/^[A-Za-z0-9\/-]{4,50}$/';

    /** @return array<string,string> field => message (empty array = valid) */
    public function validateDonor(array $in, array $cityIds): array
    {
        return $this->onlyErrors([
            'name'             => $this->nameError($in['name'] ?? ''),
            'email'            => $this->emailError($in['email'] ?? ''),
            'password'         => $this->passwordError($in['password'] ?? ''),
            'password_confirm' => $this->confirmError($in['password'] ?? '', $in['password_confirm'] ?? ''),
            'blood_group'      => $this->bloodGroupError($in['blood_group'] ?? ''),
            'dob'              => $this->dobError($in['dob'] ?? ''),
            'weight_kg'        => $this->weightError($in['weight_kg'] ?? ''),
            'cnic'             => $this->cnicError($in['cnic'] ?? ''),
            'city_id'          => $this->cityError($in['city_id'] ?? '', $cityIds),
            'phone'            => $this->phoneError($in['phone'] ?? ''),
        ]);
    }

    /**
     * Text fields of the hospital form. The licence PDF is checked by
     * FileUploader because it is a file, not text.
     *
     * @return array<string,string>
     */
    public function validateHospital(array $in, array $cityIds): array
    {
        return $this->onlyErrors([
            'hospital_name'    => $this->hospitalNameError($in['hospital_name'] ?? ''),
            'email'            => $this->emailError($in['email'] ?? ''),
            'password'         => $this->passwordError($in['password'] ?? ''),
            'password_confirm' => $this->confirmError($in['password'] ?? '', $in['password_confirm'] ?? ''),
            'licence_no'       => $this->licenceError($in['licence_no'] ?? ''),
            'city_id'          => $this->cityError($in['city_id'] ?? '', $cityIds),
            'address'          => $this->addressError($in['address'] ?? ''),
            'phone'            => $this->phoneError($in['phone'] ?? ''),
        ]);
    }

    // ------------------------------------------------------------ single checks

    public function nameError(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return 'Enter your full name (letters, spaces, . \' and - only).';
        }
        return null;
    }

    public function hospitalNameError(string $name): ?string
    {
        if (preg_match(self::HOSPITAL_PATTERN, $name) !== 1) {
            return 'Enter the hospital name (3 to 150 characters).';
        }
        return null;
    }

    public function emailError(string $email): ?string
    {
        if (strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter a valid email address.';
        }
        return null;
    }

    public function passwordError(string $password): ?string
    {
        // bcrypt only reads the first 72 bytes, so longer passwords are refused.
        if (strlen($password) < 8 || strlen($password) > 72
            || preg_match('/[A-Za-z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1) {
            return 'Use 8 to 72 characters with at least one letter and one number.';
        }
        return null;
    }

    public function confirmError(string $password, string $confirm): ?string
    {
        return ($password === $confirm) ? null : 'The two passwords do not match.';
    }

    public function phoneError(string $phone): ?string
    {
        if (preg_match(self::PHONE_PATTERN, $phone) !== 1) {
            return 'Enter a mobile number like 0300-1234567.';
        }
        return null;
    }

    public function cnicError(string $cnic): ?string
    {
        if (preg_match(self::CNIC_PATTERN, $cnic) !== 1) {
            return 'Enter your CNIC like 12345-1234567-1.';
        }
        return null;
    }

    public function bloodGroupError(string $group): ?string
    {
        return in_array($group, self::BLOOD_GROUPS, true) ? null : 'Choose your blood group.';
    }

    public function cityError(string $cityId, array $cityIds): ?string
    {
        if (ctype_digit($cityId) && in_array((int) $cityId, $cityIds, true)) {
            return null;
        }
        return 'Choose your city.';
    }

    /** Must be a real date (YYYY-MM-DD), not in the future, and the donor at least 18. */
    public function dobError(string $dob): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        if ($date === false || $date->format('Y-m-d') !== $dob) {
            return 'Enter your date of birth.';
        }

        $today = new DateTimeImmutable('today');   // uses the Asia/Karachi default time zone
        if ($date > $today) {
            return 'Date of birth cannot be in the future.';
        }

        $age = $date->diff($today)->y;
        if ($age < 18) {
            return 'You must be at least 18 years old to register as a donor.';
        }
        if ($age > 100) {
            return 'Enter a valid date of birth.';
        }
        return null;
    }

    /** The database accepts 30-250 kg; matching later requires 50 kg (see v_eligible_donors). */
    public function weightError(string $weight): ?string
    {
        $value = filter_var($weight, FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < 30 || $value > 250) {
            return 'Enter your weight in kg (between 30 and 250).';
        }
        return null;
    }

    public function licenceError(string $licenceNo): ?string
    {
        if (preg_match(self::LICENCE_PATTERN, $licenceNo) !== 1) {
            return 'Enter the licence number (4 to 50 letters, numbers, / or -).';
        }
        return null;
    }

    /** The address is optional. */
    public function addressError(string $address): ?string
    {
        return (strlen($address) <= 255) ? null : 'The address is too long (255 characters at most).';
    }

    /** Drop the fields that passed (null) and keep only the messages. */
    private function onlyErrors(array $checks): array
    {
        return array_filter($checks, static function ($message): bool {
            return $message !== null;
        });
    }
}
