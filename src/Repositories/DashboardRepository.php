<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;

/**
 * Read-only numbers for the donor and hospital home pages (dashboards).
 * Every query is limited to the logged-in user's own id.
 */
class DashboardRepository
{
    /**
     * Counts for one donor: requests waiting for an answer, accepted requests
     * still open, completed donations and the date of the last one.
     */
    public function donorCounts(int $donorId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT
                SUM(m.status = 'notified' AND r.status = 'open' AND r.required_by > NOW()) AS waiting,
                SUM(m.status = 'accepted' AND r.status = 'open')                           AS accepted_open,
                SUM(m.status = 'donated')                                                  AS donations
               FROM request_matches m
               JOIN blood_requests r ON r.id = m.request_id
              WHERE m.donor_id = ?"
        );
        $stmt->execute([$donorId]);
        $row = $stmt->fetch() ?: [];

        return [
            'waiting'       => (int) ($row['waiting'] ?? 0),
            'accepted_open' => (int) ($row['accepted_open'] ?? 0),
            'donations'     => (int) ($row['donations'] ?? 0),
        ];
    }

    /** The newest open, unanswered requests matched to this donor (at most $limit). */
    public function donorWaiting(int $donorId, int $limit = 3): array
    {
        $stmt = Database::connection()->prepare(
            sprintf(
                "SELECT r.id, r.blood_group, r.units_needed, r.urgency, r.required_by,
                        h.hospital_name, c.city_name
                   FROM request_matches m
                   JOIN blood_requests r ON r.id = m.request_id
                   JOIN hospitals h      ON h.user_id = r.hospital_id
                   JOIN cities c         ON c.id = h.city_id
                  WHERE m.donor_id = ? AND m.status = 'notified'
                    AND r.status = 'open' AND r.required_by > NOW()
               ORDER BY FIELD(r.urgency, 'critical', 'urgent', 'normal'), r.required_by
                  LIMIT %d",
                max(1, $limit)
            )
        );
        $stmt->execute([$donorId]);

        return $stmt->fetchAll();
    }

    /** Request counts of one hospital by status, plus the open ones past their deadline. */
    public function hospitalCounts(int $hospitalId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT
                SUM(status = 'open' AND required_by > NOW())  AS open_requests,
                SUM(status = 'fulfilled')                     AS fulfilled,
                SUM(status IN ('cancelled', 'closed'))        AS cancelled_closed,
                SUM(status = 'expired' OR (status = 'open' AND required_by <= NOW())) AS expired,
                COUNT(*)                                      AS total
               FROM blood_requests
              WHERE hospital_id = ?"
        );
        $stmt->execute([$hospitalId]);
        $row = $stmt->fetch() ?: [];

        $out = [];
        foreach (['open_requests', 'fulfilled', 'cancelled_closed', 'expired', 'total'] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }

        // Donors who accepted and have not donated yet, on this hospital's open requests.
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*)
               FROM request_matches m
               JOIN blood_requests r ON r.id = m.request_id
              WHERE r.hospital_id = ? AND r.status = 'open' AND m.status = 'accepted'"
        );
        $stmt->execute([$hospitalId]);
        $out['donors_coming'] = (int) $stmt->fetchColumn();

        return $out;
    }

    /** The hospital's open requests, most urgent first. */
    public function hospitalOpen(int $hospitalId, int $limit = 5): array
    {
        $stmt = Database::connection()->prepare(
            sprintf(
                "SELECT r.id, r.blood_group, r.units_needed, r.units_fulfilled, r.urgency, r.required_by,
                        (SELECT COUNT(*) FROM request_matches m
                          WHERE m.request_id = r.id AND m.status = 'accepted') AS accepted
                   FROM blood_requests r
                  WHERE r.hospital_id = ? AND r.status = 'open' AND r.required_by > NOW()
               ORDER BY FIELD(r.urgency, 'critical', 'urgent', 'normal'), r.required_by
                  LIMIT %d",
                max(1, $limit)
            )
        );
        $stmt->execute([$hospitalId]);

        return $stmt->fetchAll();
    }
}
