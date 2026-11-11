<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;

/**
 * All SQL needed by MatchingService (read-only).
 * Only the data needed for ranking is selected: never names, phones or emails.
 */
class MatchingRepository
{
    /**
     * Blood groups that may donate to $recipientGroup, read from the
     * blood_compatibility table (a data-driven map, not if/else chains).
     */
    public function compatibleDonorGroups(string $recipientGroup): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT donor_group FROM blood_compatibility WHERE recipient_group = ?'
        );
        $stmt->execute([$recipientGroup]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Eligible donors (view v_eligible_donors) with one of the given blood groups.
     * Donors already matched to $excludeRequestId are left out, because a donor
     * can be matched to a request only once.
     *
     * past_total     = notifications the donor has already finished (not pending)
     * past_responded = how many of them the donor answered (accepted/declined/donated)
     */
    public function findEligibleDonors(array $bloodGroups, int $excludeRequestId = 0): array
    {
        if ($bloodGroups === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($bloodGroups), '?'));

        $sql = "SELECT e.user_id AS donor_id,
                       e.blood_group,
                       e.city_id,
                       DATEDIFF(CURDATE(), e.last_donation_date) AS days_since_donation,
                       (SELECT COUNT(*) FROM request_matches m
                         WHERE m.donor_id = e.user_id
                           AND m.status <> 'notified') AS past_total,
                       (SELECT COUNT(*) FROM request_matches m
                         WHERE m.donor_id = e.user_id
                           AND m.status IN ('accepted', 'declined', 'donated')) AS past_responded
                  FROM v_eligible_donors e
                 WHERE e.blood_group IN ($placeholders)
                   AND NOT EXISTS (SELECT 1 FROM request_matches x
                                    WHERE x.request_id = ? AND x.donor_id = e.user_id)";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(array_merge(array_values($bloodGroups), [$excludeRequestId]));

        return $stmt->fetchAll();
    }

    /** A whole-number admin setting (see the settings table), or $default. */
    public function setting(string $key, int $default): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = ?'
        );
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return ($value === false) ? $default : (int) $value;
    }
}
