<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DonorNexa\Contracts\SettingRule;

/**
 * Checks the admin's system settings and city names.
 *
 * Only the settings listed in rules() can be changed: a posted key that is not
 * in the list is ignored, so the form cannot be used to create or edit other rows.
 */
class SettingsValidator
{
    public const PROVINCES = [
        'Sindh', 'Punjab', 'Khyber Pakhtunkhwa', 'Balochistan',
        'Islamabad Capital Territory', 'Gilgit-Baltistan', 'Azad Jammu & Kashmir',
    ];

    /** @return array<string,SettingRule> setting key => rule */
    public function rules(): array
    {
        return [
            'cooldown_days'            => new IntegerRangeRule(30, 365),
            'top_n_notify'             => new IntegerRangeRule(1, 50),
            'critical_pool_multiplier' => new IntegerRangeRule(1, 10),
            'max_open_critical'        => new IntegerRangeRule(1, 10),
            'no_response_hours'        => new IntegerRangeRule(1, 168),
            'trust_penalty_cancel'     => new IntegerRangeRule(0, 50),
        ];
    }

    /**
     * @param array<string,string> $input setting key => typed value
     * @return array<string,string> setting key => error message (empty when all is well)
     */
    public function validateSettings(array $input): array
    {
        $errors = [];
        foreach ($this->rules() as $key => $rule) {
            $error = $rule->error(trim((string) ($input[$key] ?? '')));
            if ($error !== null) {
                $errors[$key] = $error;
            }
        }

        return $errors;
    }

    public function cityNameError(string $name): ?string
    {
        if (preg_match("/^[\\p{L}][\\p{L} .'\\-]{1,78}[\\p{L}.]$/u", $name) !== 1) {
            return 'Enter a city name of 3 to 80 letters (spaces, dots, apostrophes and hyphens are allowed).';
        }

        return null;
    }

    public function provinceError(string $province): ?string
    {
        return in_array($province, self::PROVINCES, true) ? null : 'Choose a province from the list.';
    }
}
