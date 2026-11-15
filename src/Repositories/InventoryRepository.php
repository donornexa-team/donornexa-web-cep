<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\ForbiddenException;
use DonorNexa\Services\MatchingService;
use InvalidArgumentException;
use Throwable;

/**
 * SQL for the blood stock a hospital holds (table blood_inventory, one row per
 * hospital and blood group).
 */
class InventoryRepository
{
    /**
     * Units per blood group for a hospital. Always has all eight groups
     * (a group without a row counts as 0 units).
     *
     * @return array<string,array{units:int,updated_at:?string}> in the usual group order
     */
    public function forHospital(int $hospitalId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT blood_group, units_available, updated_at
               FROM blood_inventory
              WHERE hospital_id = ?'
        );
        $stmt->execute([$hospitalId]);

        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[$row['blood_group']] = $row;
        }

        $result = [];
        foreach (MatchingService::BLOOD_GROUPS as $group) {
            $result[$group] = [
                'units'      => isset($found[$group]) ? (int) $found[$group]['units_available'] : 0,
                'updated_at' => $found[$group]['updated_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Save the numbers typed in the inventory form (all eight groups).
     *
     * ONE transaction: the hospital row is locked first (SELECT ... FOR UPDATE)
     * so two browser tabs saving at the same moment cannot mix their numbers;
     * only the groups that really changed are written, and each change gets an
     * audit row ("O+: 5" -> "O+: 8") that is rolled back together with it on error.
     *
     * @param array<string,int> $values blood group => units (already validated)
     * @return array[] the changes: each ['group' => 'O+', 'old' => 5, 'new' => 8]
     * @throws ForbiddenException when the hospital does not exist
     */
    public function saveAll(int $hospitalId, array $values, int $userId, string $ip): array
    {
        $pdo     = Database::connection();
        $changes = [];

        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare('SELECT user_id FROM hospitals WHERE user_id = ? FOR UPDATE');
            $lock->execute([$hospitalId]);
            if ($lock->fetchColumn() === false) {
                throw new ForbiddenException('Only a hospital account has an inventory.');
            }

            $current = $this->forHospital($hospitalId);
            $upsert  = $pdo->prepare(
                'INSERT INTO blood_inventory (hospital_id, blood_group, units_available)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE units_available = VALUES(units_available)'
            );
            $audit = new AuditRepository();

            foreach (MatchingService::BLOOD_GROUPS as $group) {
                if (!isset($values[$group]) || $values[$group] === $current[$group]['units']) {
                    continue;
                }

                $old = $current[$group]['units'];
                $new = (int) $values[$group];

                $upsert->execute([$hospitalId, $group, $new]);
                $audit->log($userId, 'inventory.updated', 'blood_inventory', $hospitalId, $group . ': ' . $old, $group . ': ' . $new, $ip);
                $changes[] = ['group' => $group, 'old' => $old, 'new' => $new];
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $changes;
    }

    /**
     * Add units of one blood group to a hospital's stock (for example when a
     * donation is marked as completed). It does NOT open a transaction itself,
     * so the donation feature can call it inside its own transaction and the
     * stock increase is rolled back with it.
     *
     * @throws InvalidArgumentException for an unknown blood group or fewer than 1 unit
     */
    public function addUnits(int $hospitalId, string $bloodGroup, int $units): void
    {
        if (!in_array($bloodGroup, MatchingService::BLOOD_GROUPS, true) || $units < 1) {
            throw new InvalidArgumentException('A known blood group and at least 1 unit are required.');
        }

        Database::connection()->prepare(
            'INSERT INTO blood_inventory (hospital_id, blood_group, units_available)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE units_available = units_available + VALUES(units_available)'
        )->execute([$hospitalId, $bloodGroup, $units]);
    }
}
