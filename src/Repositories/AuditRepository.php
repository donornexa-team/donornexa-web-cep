<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;

/**
 * Shared helper that writes one row to audit_logs: who did what, to which
 * record, from which IP address, and the old and new value.
 *
 * Every slice should call this instead of writing its own INSERT, so the
 * audit trail looks the same everywhere. Database::connection() is ONE shared
 * PDO, so a call made between beginTransaction() and commit() is part of that
 * transaction: if the transaction is rolled back, the audit row disappears
 * with it (no log entry for something that never happened).
 *
 *   (new AuditRepository())->log($userId, 'request.created', 'blood_requests', $id, null, 'open', $ip);
 */
class AuditRepository
{
    /**
     * @param int|null    $userId   who did it (null for a guest / the system)
     * @param string      $action   e.g. 'request.created'
     * @param string      $entity   table the record lives in, e.g. 'blood_requests'
     * @param int|null    $entityId id of that record
     * @param string|null $oldValue value before the change
     * @param string|null $newValue value after the change
     * @param string      $ip       the client's IP address
     */
    public function log(
        ?int $userId,
        string $action,
        string $entity,
        ?int $entityId,
        ?string $oldValue,
        ?string $newValue,
        string $ip
    ): void {
        Database::connection()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity, entity_id, old_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            substr($action, 0, 50),
            substr($entity, 0, 50),
            $entityId,
            ($oldValue === null) ? null : mb_substr($oldValue, 0, 255),
            ($newValue === null) ? null : mb_substr($newValue, 0, 255),
            substr($ip, 0, 45),
        ]);
    }
}
