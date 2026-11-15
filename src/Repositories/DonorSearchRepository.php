<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use DonorNexa\Services\ListQuery;
use DonorNexa\Services\MatchingService;

/**
 * Admin donor search (used by api/donors-search.php and the page /admin/donors).
 *
 * Privacy: only name, blood group, city, status, availability, last donation
 * date and "eligible now" are selected. Never the phone, CNIC or email.
 */
class DonorSearchRepository
{
    /** Cities for the filter drop-down: [['id' => 1, 'city_name' => 'Karachi'], ...]. */
    public function cities(): array
    {
        return Database::connection()
            ->query('SELECT id, city_name FROM cities ORDER BY city_name')
            ->fetchAll();
    }

    /**
     * Run a search from the query string (?q=ali&blood_group=O%2B&city_id=2&eligible=1&sort=name&dir=asc&page=2).
     *
     * @param array $get normally $_GET
     * @return array{rows: array[], total: int, page: int, pages: int, per_page: int}
     */
    public function run(array $get): array
    {
        $list = new ListQuery($get, '/admin/donors', [
            'name'  => 'u.name',
            'blood' => 'd.blood_group',
            'city'  => 'c.city_name',
            'last'  => 'd.last_donation_date',
        ], 'name', 'asc', 10);

        $group    = $list->filter('blood_group', MatchingService::BLOOD_GROUPS);
        $cityIds  = array_map('strval', array_column($this->cities(), 'id'));
        $city     = $list->filter('city_id', $cityIds);
        $eligible = $list->filter('eligible', ['1']) === '1';

        [$where, $params] = $this->where($list, $group, $city, $eligible);
        $from = 'FROM donors d
                 JOIN users u  ON u.id = d.user_id
                 JOIN cities c ON c.id = d.city_id
                 LEFT JOIN v_eligible_donors e ON e.user_id = d.user_id';

        $pdo  = Database::connection();
        $stmt = $pdo->prepare("SELECT COUNT(*) $from WHERE $where");
        $stmt->execute($params);
        $list->applyTotal((int) $stmt->fetchColumn());

        // orderBy() is a whitelisted column; LIMIT/OFFSET are integers made by the helper.
        $stmt = $pdo->prepare(sprintf(
            "SELECT d.user_id AS id, u.name, d.blood_group, c.city_name, u.status,
                    d.is_available, d.last_donation_date,
                    (e.user_id IS NOT NULL) AS eligible
             $from
             WHERE %s
             ORDER BY %s, d.user_id ASC
             LIMIT %d OFFSET %d",
            $where,
            $list->orderBy(),
            $list->perPage(),
            $list->offset()
        ));
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id'                 => (int) $row['id'],
                'name'               => $row['name'],
                'blood_group'        => $row['blood_group'],
                'city'               => $row['city_name'],
                'status'             => $row['status'],
                'available'          => (bool) $row['is_available'],
                'last_donation_date' => $row['last_donation_date'],
                'eligible'           => (bool) $row['eligible'],
            ];
        }

        return [
            'rows'     => $rows,
            'total'    => $list->total(),
            'page'     => $list->page(),
            'pages'    => $list->totalPages(),
            'per_page' => $list->perPage(),
        ];
    }

    /** @return array{0:string,1:array} the WHERE text (with ? marks) and its parameters */
    private function where(ListQuery $list, string $group, string $cityId, bool $eligibleOnly): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($list->search() !== '') {
            $where[]  = 'u.name LIKE ?';
            $params[] = $list->likeTerm();
        }
        if ($group !== '') {
            $where[]  = 'd.blood_group = ?';
            $params[] = $group;
        }
        if ($cityId !== '') {
            $where[]  = 'd.city_id = ?';
            $params[] = (int) $cityId;
        }
        if ($eligibleOnly) {
            $where[] = 'e.user_id IS NOT NULL';
        }

        return [implode(' AND ', $where), $params];
    }
}
