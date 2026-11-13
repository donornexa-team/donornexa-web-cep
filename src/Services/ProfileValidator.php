<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DateInterval;
use DateTimeImmutable;

/**
 * Server-side validation of the donor "edit profile" form.
 *
 * It reuses the single checks of AccountValidator (name, phone, weight, city)
 * and adds the rules for the last donation date. Blood group, date of birth,
 * CNIC and email cannot be edited here (they identify the donor).
 *
 * SAFETY RULE for the last donation date: a donor may only move it FORWARD.
 * If the donor could set an older date, or clear it, the 90-day cool-down
 * could be switched off by hand and a donor could be matched too early.
 */
class ProfileValidator
{
    private AccountValidator $accounts;

    public function __construct(?AccountValidator $accounts = null)
    {
        $this->accounts = $accounts ?? new AccountValidator();
    }

    /**
     * @param array                  $in          name, phone, weight_kg, city_id, last_donation_date
     * @param int[]                  $cityIds     ids of the existing cities
     * @param string                 $dob         the donor's date of birth (Y-m-d), from the database
     * @param string|null            $currentLast the last donation date already stored (Y-m-d) or null
     * @param DateTimeImmutable|null $today       only tests pass it
     * @return array<string,string> field => message (empty array = valid)
     */
    public function validate(array $in, array $cityIds, string $dob, ?string $currentLast, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');

        $checks = [
            'name'               => $this->accounts->nameError($in['name'] ?? ''),
            'phone'              => $this->accounts->phoneError($in['phone'] ?? ''),
            'weight_kg'          => $this->accounts->weightError($in['weight_kg'] ?? ''),
            'city_id'            => $this->accounts->cityError($in['city_id'] ?? '', $cityIds),
            'last_donation_date' => $this->lastDonationError($in['last_donation_date'] ?? '', $dob, $currentLast, $today),
        ];

        return array_filter($checks, static function ($message): bool {
            return $message !== null;
        });
    }

    public function lastDonationError(string $value, string $dob, ?string $currentLast, DateTimeImmutable $today): ?string
    {
        $hasCurrent = ($currentLast !== null && $currentLast !== '');

        if ($value === '') {
            return $hasCurrent ? 'A recorded donation date cannot be removed.' : null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return 'Enter a valid date (YYYY-MM-DD).';
        }
        if ($date > $today) {
            return 'The last donation date cannot be in the future.';
        }

        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        if ($birth !== false && $date < $birth->add(new DateInterval('P18Y'))) {
            return 'The date cannot be before you turned 18.';
        }

        if ($hasCurrent) {
            $current = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $currentLast);
            if ($current !== false && $date < $current) {
                return 'You cannot set a date earlier than the one already recorded (' . $current->format('d M Y') . ').';
            }
        }

        return null;
    }
}
