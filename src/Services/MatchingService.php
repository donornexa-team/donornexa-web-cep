<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\MatchingRepository;

/**
 * The matching engine: who may donate, who is eligible, and in which order
 * they should be notified.
 *
 * 1. Compatibility  - read from the blood_compatibility table.
 * 2. Eligibility    - only donors in the view v_eligible_donors (age 18-60,
 *                     weight >= 50 kg, available, active, cool-down passed).
 * 3. Ranking score  - out of 100:
 *        exact blood-group match   30 points
 *        same city as the hospital 20 points
 *        days since last donation  up to 30 points (capped at 180 days;
 *                                  a donor who never donated gets the maximum)
 *        response rate             up to 20 points (donors with no history
 *                                  get a neutral 50%)
 * 4. Pool size      - top N donors are notified first (setting top_n_notify).
 *                     Critical requests widen the pool: N x critical_pool_multiplier.
 *
 * This class only READS and RANKS. Saving matches and sending notifications is
 * done by the code that calls it.
 */
class MatchingService
{
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    public const URGENCIES    = ['critical', 'urgent', 'normal'];

    private const POINTS_EXACT_GROUP  = 30;
    private const POINTS_SAME_CITY    = 20;
    private const POINTS_DAYS_MAX     = 30;
    private const DAYS_CAP            = 180;
    private const POINTS_RESPONSE_MAX = 20;
    private const NEUTRAL_RESPONSE    = 0.5;

    private MatchingRepository $repository;

    public function __construct(?MatchingRepository $repository = null)
    {
        $this->repository = $repository ?? new MatchingRepository();
    }

    /** Blood groups that can donate to $recipientGroup, e.g. 'AB+' => all eight. */
    public function compatibleGroups(string $recipientGroup): array
    {
        $this->assertBloodGroup($recipientGroup);

        return $this->repository->compatibleDonorGroups($recipientGroup);
    }

    /** How many donors to notify first for this urgency. */
    public function poolSize(string $urgency): int
    {
        $this->assertUrgency($urgency);

        $size = $this->repository->setting('top_n_notify', 5);
        if ($urgency === 'critical') {
            $size *= $this->repository->setting('critical_pool_multiplier', 2);
        }

        return $size;
    }

    /**
     * Ranked donors to notify for a request (best first, already cut to the pool size).
     *
     * @param string $bloodGroup     blood group the patient needs
     * @param int    $hospitalCityId city of the hospital (for the same-city bonus)
     * @param string $urgency        critical, urgent or normal
     * @param int    $requestId      pass it when the request already exists, so donors
     *                               that are already matched to it are skipped
     * @return array[] each: donor_id, blood_group, city_id, score, breakdown
     */
    public function findMatches(string $bloodGroup, int $hospitalCityId, string $urgency, int $requestId = 0): array
    {
        $this->assertBloodGroup($bloodGroup);
        $this->assertUrgency($urgency);

        $groups     = $this->repository->compatibleDonorGroups($bloodGroup);
        $candidates = $this->repository->findEligibleDonors($groups, $requestId);

        $scored = [];
        foreach ($candidates as $donor) {
            $scored[] = $this->scoreDonor($donor, $bloodGroup, $hospitalCityId);
        }

        // Highest score first; ties: longer since last donation, then lower id (stable order).
        usort($scored, function (array $a, array $b): int {
            return [$b['score'], $b['breakdown']['days_counted'], $a['donor_id']]
               <=> [$a['score'], $a['breakdown']['days_counted'], $b['donor_id']];
        });

        return array_slice($scored, 0, $this->poolSize($urgency));
    }

    /**
     * Score one donor. $donor needs: donor_id, blood_group, city_id,
     * days_since_donation (null = never donated), past_total, past_responded.
     * Public so it can be tested without a database.
     */
    public function scoreDonor(array $donor, string $requestGroup, int $hospitalCityId): array
    {
        $exact = ($donor['blood_group'] === $requestGroup) ? self::POINTS_EXACT_GROUP : 0;
        $city  = ((int) $donor['city_id'] === $hospitalCityId) ? self::POINTS_SAME_CITY : 0;

        $days = ($donor['days_since_donation'] === null)
            ? self::DAYS_CAP
            : min((int) $donor['days_since_donation'], self::DAYS_CAP);
        $daysPoints = $days / self::DAYS_CAP * self::POINTS_DAYS_MAX;

        $pastTotal = (int) $donor['past_total'];
        $rate = ($pastTotal === 0)
            ? self::NEUTRAL_RESPONSE
            : (int) $donor['past_responded'] / $pastTotal;
        $responsePoints = $rate * self::POINTS_RESPONSE_MAX;

        return [
            'donor_id'    => (int) $donor['donor_id'],
            'blood_group' => $donor['blood_group'],
            'city_id'     => (int) $donor['city_id'],
            'score'       => round($exact + $city + $daysPoints + $responsePoints, 2),
            'breakdown'   => [
                'exact_group'   => $exact,
                'same_city'     => $city,
                'days_since'    => round($daysPoints, 2),
                'response_rate' => round($responsePoints, 2),
                'days_counted'  => $days,
            ],
        ];
    }

    private function assertBloodGroup(string $group): void
    {
        if (!in_array($group, self::BLOOD_GROUPS, true)) {
            throw new ValidationException(['blood_group' => 'Unknown blood group.']);
        }
    }

    private function assertUrgency(string $urgency): void
    {
        if (!in_array($urgency, self::URGENCIES, true)) {
            throw new ValidationException(['urgency' => 'Urgency must be critical, urgent or normal.']);
        }
    }
}
