<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DateInterval;
use DateTimeImmutable;
use DonorNexa\Core\Database;
use DonorNexa\Services\ListQuery;

/**
 * Read-only queries for the admin dashboard and the audit log view.
 * Uses JOINs, aggregates (COUNT, SUM, GROUP BY), subqueries and the view
 * v_demand_vs_supply (see the report's list of JOIN/aggregate queries).
 */
class AdminRepository
{
    private const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** The numbers in the tiles at the top of the dashboard. */
    public function overview(): array
    {
        $sql = "SELECT
                    (SELECT COUNT(*) FROM donors)                                          AS donors,
                    (SELECT COUNT(*) FROM hospitals WHERE verification_status = 'verified') AS verified_hospitals,
                    (SELECT COUNT(*) FROM hospitals WHERE verification_status = 'pending')  AS pending_hospitals,
                    (SELECT COUNT(*) FROM users WHERE status = 'blocked')                   AS blocked_users,
                    (SELECT COUNT(*) FROM blood_requests
                      WHERE status = 'open' AND required_by > NOW())                        AS open_requests,
                    (SELECT COUNT(*) FROM blood_requests)                                   AS total_requests,
                    (SELECT COALESCE(SUM(units), 0) FROM donations)                         AS units_donated";

        return array_map('intval', Database::connection()->query($sql)->fetch());
    }

    /**
     * Requests created per week for the last $weeks weeks (Monday-based),
     * weeks without requests included as 0 so the chart has no gaps.
     *
     * @return array[] each: ['label' => '03 Nov', 'count' => 4], oldest first
     */
    public function requestsPerWeek(int $weeks = 12): array
    {
        $sql = "SELECT DATE(created_at - INTERVAL WEEKDAY(created_at) DAY) AS week_start,
                       COUNT(*) AS total
                  FROM blood_requests
                 WHERE created_at >= (CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY) - INTERVAL ? WEEK
              GROUP BY week_start";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$weeks - 1]);
        $counts = array_column($stmt->fetchAll(), 'total', 'week_start');

        $monday = new DateTimeImmutable('monday this week');   // Asia/Karachi (set in bootstrap)
        $result = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start    = $monday->sub(new DateInterval('P' . ($i * 7) . 'D'));
            $result[] = [
                'label' => $start->format('d M'),
                'count' => (int) ($counts[$start->format('Y-m-d')] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Blood needed on open requests vs eligible donors, per blood group
     * (reads the view v_demand_vs_supply).
     *
     * @return array[] each: ['group' => 'O+', 'demand' => 6, 'supply' => 3]
     */
    public function demandVsSupply(): array
    {
        $rows = Database::connection()
            ->query('SELECT blood_group, units_demanded, eligible_donors FROM v_demand_vs_supply')
            ->fetchAll();

        $byGroup = [];
        foreach ($rows as $row) {
            $byGroup[$row['blood_group']] = [
                'group'  => $row['blood_group'],
                'demand' => (int) $row['units_demanded'],
                'supply' => (int) $row['eligible_donors'],
            ];
        }

        $result = [];
        foreach (self::BLOOD_GROUPS as $group) {
            $result[] = $byGroup[$group] ?? ['group' => $group, 'demand' => 0, 'supply' => 0];
        }

        return $result;
    }

    /**
     * Fulfilment rate = units given / units asked for, per urgency, for
     * requests that were not cancelled.
     *
     * @return array[] each: ['urgency' => 'critical', 'needed' => 40, 'fulfilled' => 30, 'rate' => 75.0]
     */
    public function fulfilmentRates(): array
    {
        $sql = "SELECT urgency,
                       SUM(units_needed)    AS needed,
                       SUM(units_fulfilled) AS fulfilled
                  FROM blood_requests
                 WHERE status <> 'cancelled'
              GROUP BY urgency";

        $rows = [];
        foreach (Database::connection()->query($sql)->fetchAll() as $row) {
            $rows[$row['urgency']] = $row;
        }

        $result = [];
        foreach (['critical', 'urgent', 'normal'] as $urgency) {
            $needed    = (int) ($rows[$urgency]['needed'] ?? 0);
            $fulfilled = (int) ($rows[$urgency]['fulfilled'] ?? 0);
            $result[]  = [
                'urgency'   => $urgency,
                'needed'    => $needed,
                'fulfilled' => $fulfilled,
                'rate'      => $needed > 0 ? round($fulfilled / $needed * 100, 1) : 0.0,
            ];
        }

        return $result;
    }

    /** Overall fulfilment rate (all urgencies together), in percent. */
    public function overallFulfilmentRate(): float
    {
        $needed = $fulfilled = 0;
        foreach ($this->fulfilmentRates() as $row) {
            $needed    += $row['needed'];
            $fulfilled += $row['fulfilled'];
        }

        return $needed > 0 ? round($fulfilled / $needed * 100, 1) : 0.0;
    }

    // ------------------------------------------------------------- audit log

    /** Distinct entity names in the log, for the filter drop-down. */
    public function auditEntities(): array
    {
        return Database::connection()
            ->query('SELECT DISTINCT entity FROM audit_logs ORDER BY entity')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** How many audit rows match the search text and entity filter. */
    public function countAuditLogs(ListQuery $list, string $entity): int
    {
        [$where, $params] = $this->auditWhere($list, $entity);

        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*)
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE $where"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** One page of audit rows, sorted as the ListQuery says (whitelisted column). */
    public function auditLogs(ListQuery $list, string $entity): array
    {
        [$where, $params] = $this->auditWhere($list, $entity);

        // orderBy() only ever returns a whitelisted column; LIMIT/OFFSET are
        // integers produced by the helper. (Emulated prepares are off, so
        // they cannot be bound parameters.)
        $sql = sprintf(
            "SELECT a.id, a.created_at, a.action, a.entity, a.entity_id,
                    a.old_value, a.new_value, a.ip_address,
                    u.name AS user_name, u.role AS user_role
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE %s
           ORDER BY %s, a.id DESC
              LIMIT %d OFFSET %d",
            $where,
            $list->orderBy(),
            $list->perPage(),
            $list->offset()
        );

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    // ---------------------------------------------------------- CSV exports

    /**
     * Audit rows for the CSV export: the same search, entity filter and sorting
     * as the audit page, but ALL matching rows (at most $limit) instead of one page.
     *
     * @return array[] flat rows, ready for CsvExporter
     */
    public function exportAuditLogs(ListQuery $list, string $entity, int $limit = 10000): array
    {
        [$where, $params] = $this->auditWhere($list, $entity);

        $sql = sprintf(
            "SELECT a.created_at, COALESCE(u.name, 'System / guest') AS user_name,
                    COALESCE(u.role, '') AS user_role, a.action, a.entity, a.entity_id,
                    a.old_value, a.new_value, a.ip_address
               FROM audit_logs a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE %s
           ORDER BY %s, a.id DESC
              LIMIT %d",
            $where,
            $list->orderBy(),
            $limit
        );

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Every blood request with its hospital, city and number of matched donors. */
    public function exportRequests(int $limit = 10000): array
    {
        $sql = sprintf(
            "SELECT r.id, h.hospital_name, c.city_name, r.blood_group, r.units_needed,
                    r.units_fulfilled, r.urgency, r.required_by, r.status, r.created_at,
                    (SELECT COUNT(*) FROM request_matches m WHERE m.request_id = r.id) AS donors_matched
               FROM blood_requests r
               JOIN hospitals h ON h.user_id = r.hospital_id
               JOIN cities c    ON c.id = h.city_id
           ORDER BY r.id
              LIMIT %d",
            $limit
        );

        return Database::connection()->query($sql)->fetchAll();
    }

    /**
     * Donor summary. Privacy: NO phone, CNIC or email, only what an overview needs.
     */
    public function exportDonors(int $limit = 10000): array
    {
        $sql = sprintf(
            "SELECT d.user_id AS id, u.name, d.blood_group, c.city_name, u.status,
                    IF(d.is_available = 1, 'yes', 'no') AS available,
                    d.last_donation_date,
                    IF(e.user_id IS NULL, 'no', 'yes') AS eligible_now
               FROM donors d
               JOIN users u  ON u.id = d.user_id
               JOIN cities c ON c.id = d.city_id
               LEFT JOIN v_eligible_donors e ON e.user_id = d.user_id
           ORDER BY d.user_id
              LIMIT %d",
            $limit
        );

        return Database::connection()->query($sql)->fetchAll();
    }

    /** @return array{0:string,1:array} the WHERE text (with ? marks) and its parameters */
    private function auditWhere(ListQuery $list, string $entity): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($entity !== '') {
            $where[]  = 'a.entity = ?';
            $params[] = $entity;
        }

        if ($list->search() !== '') {
            $where[] = '(a.action LIKE ? OR a.old_value LIKE ? OR a.new_value LIKE ?
                         OR u.name LIKE ? OR a.ip_address LIKE ?)';
            $like    = $list->likeTerm();
            array_push($params, $like, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }
}
