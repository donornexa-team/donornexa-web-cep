<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;

/**
 * Read-only queries for the public pages (landing page and emergencies board).
 * Only data that is safe to show to anyone is returned: no patient names,
 * no phone numbers, no hospital contact details.
 */
class PublicRepository
{
    /** Numbers for the landing page. */
    public function stats(): array
    {
        $sql = "SELECT
                    (SELECT COUNT(*)
                       FROM blood_requests
                      WHERE status = 'open' AND required_by > NOW())   AS active_requests,
                    (SELECT COUNT(*)
                       FROM donors d
                       JOIN users u ON u.id = d.user_id
                      WHERE u.status = 'active')                        AS registered_donors,
                    (SELECT COALESCE(SUM(units), 0) FROM donations)      AS units_fulfilled";

        $row = Database::connection()->query($sql)->fetch();

        return [
            'active_requests'  => (int) $row['active_requests'],
            'registered_donors' => (int) $row['registered_donors'],
            'units_fulfilled'  => (int) $row['units_fulfilled'],
        ];
    }

    /**
     * Open requests of verified hospitals, most urgent first.
     * seconds_left is calculated by the database so the countdown does not
     * depend on the visitor's clock or time zone.
     */
    public function activeEmergencies(): array
    {
        $sql = "SELECT r.blood_group,
                       r.urgency,
                       c.city_name,
                       TIMESTAMPDIFF(SECOND, NOW(), r.required_by) AS seconds_left
                  FROM blood_requests r
                  JOIN hospitals h ON h.user_id = r.hospital_id
                  JOIN cities c    ON c.id = h.city_id
                 WHERE r.status = 'open'
                   AND r.required_by > NOW()
                   AND h.verification_status = 'verified'
                 ORDER BY FIELD(r.urgency, 'critical', 'urgent', 'normal'),
                          r.required_by ASC";

        return Database::connection()->query($sql)->fetchAll();
    }
}
