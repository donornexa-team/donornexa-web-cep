<?php
declare(strict_types=1);

namespace DonorNexa\Services;

/**
 * Donor badges from the number of completed donations (CEP: 1st, 5th, 10th).
 * Pure function of a number, so it is tested without a database.
 */
class BadgeService
{
    /** badge name => donations needed */
    public const BADGES = [
        'First donation' => 1,
        'Regular donor (5th donation)' => 5,
        'Hero donor (10th donation)' => 10,
    ];

    /**
     * @return array[] each: ['name' => ..., 'target' => 5, 'earned' => true]
     */
    public function forCount(int $donations): array
    {
        $badges = [];
        foreach (self::BADGES as $name => $target) {
            $badges[] = ['name' => $name, 'target' => $target, 'earned' => $donations >= $target];
        }

        return $badges;
    }

    /** Donations still needed for the next badge, or null when all badges are earned. */
    public function untilNext(int $donations): ?int
    {
        foreach (self::BADGES as $target) {
            if ($donations < $target) {
                return $target - $donations;
            }
        }

        return null;
    }
}
