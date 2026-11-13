<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DateInterval;
use DateTimeImmutable;

/**
 * Tells a donor, in plain words, whether they can be matched with blood
 * requests right now and, if they just donated, when they are eligible again.
 *
 * It applies the SAME rules as the database view v_eligible_donors (account
 * active, available, weight >= 50 kg, age 18-60, last donation at least
 * cooldown_days ago), but it also explains WHICH rule fails. It uses no
 * database, so it can be tested from the command line.
 */
class EligibilityService
{
    public const MIN_WEIGHT = 50;
    public const MIN_AGE    = 18;
    public const MAX_AGE    = 60;

    /**
     * @param array $donor keys: account_status, is_available, weight_kg, dob, last_donation_date
     * @return array{eligible:bool, reasons:string[], eligible_on:?DateTimeImmutable, age:int}
     *         eligible_on is the first day the donor may donate again (null if never donated)
     */
    public function status(array $donor, int $cooldownDays, ?DateTimeImmutable $today = null): array
    {
        $today   = $today ?? new DateTimeImmutable('today');   // Asia/Karachi (set in bootstrap)
        $reasons = [];

        if (($donor['account_status'] ?? 'active') !== 'active') {
            $reasons[] = 'Your account is blocked.';
        }
        if ((int) ($donor['is_available'] ?? 0) !== 1) {
            $reasons[] = 'You have switched your availability off.';
        }
        if ((float) ($donor['weight_kg'] ?? 0) < self::MIN_WEIGHT) {
            $reasons[] = 'Donors must weigh at least ' . self::MIN_WEIGHT . ' kg.';
        }

        $dob = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($donor['dob'] ?? ''));
        $age = ($dob === false) ? 0 : $dob->diff($today)->y;
        if ($age < self::MIN_AGE || $age > self::MAX_AGE) {
            $reasons[] = 'Donors must be between ' . self::MIN_AGE . ' and ' . self::MAX_AGE . ' years old.';
        }

        $eligibleOn = $this->eligibleOn($donor['last_donation_date'] ?? null, $cooldownDays);
        if ($eligibleOn !== null && $eligibleOn > $today) {
            $reasons[] = 'You donated recently. You can donate again on ' . $eligibleOn->format('d M Y') . '.';
        }

        return [
            'eligible'    => $reasons === [],
            'reasons'     => $reasons,
            'eligible_on' => $eligibleOn,
            'age'         => $age,
        ];
    }

    /** Last donation date + cool-down days: the first day the donor may donate again. */
    public function eligibleOn(?string $lastDonation, int $cooldownDays): ?DateTimeImmutable
    {
        if ($lastDonation === null || $lastDonation === '') {
            return null;
        }

        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $lastDonation);
        if ($last === false) {
            return null;
        }

        return $last->add(new DateInterval('P' . max(0, $cooldownDays) . 'D'));
    }
}
