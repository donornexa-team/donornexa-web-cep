<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\ForbiddenException;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Services\ListQuery;
use Throwable;

/**
 * SQL for the admin screens "Hospitals" (verify / reject) and "Users" (block / unblock).
 *
 * Every status change runs in ONE transaction: the row is locked with
 * SELECT ... FOR UPDATE, changed, and an audit_logs row is written with
 * AuditRepository (rolled back together with the change if anything fails).
 */
class AccountAdminRepository
{
    public const HOSPITAL_STATUSES = ['pending', 'verified', 'rejected'];
    public const ROLES             = ['donor', 'hospital', 'admin'];
    public const USER_STATUSES     = ['active', 'blocked'];

    // -------------------------------------------------------------- hospitals

    public function countHospitals(ListQuery $list, string $status): int
    {
        [$where, $params] = $this->hospitalWhere($list, $status);

        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*)
               FROM hospitals h
               JOIN users u  ON u.id = h.user_id
               JOIN cities c ON c.id = h.city_id
              WHERE $where"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** One page of hospitals. orderBy() is a whitelisted column; LIMIT/OFFSET are integers. */
    public function listHospitals(ListQuery $list, string $status): array
    {
        [$where, $params] = $this->hospitalWhere($list, $status);

        $sql = sprintf(
            "SELECT h.user_id, h.hospital_name, h.licence_no, h.verification_status, h.trust_score,
                    u.email, u.status AS account_status, u.created_at, c.city_name
               FROM hospitals h
               JOIN users u  ON u.id = h.user_id
               JOIN cities c ON c.id = h.city_id
              WHERE %s
           ORDER BY %s, h.user_id DESC
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

    /** File name of a hospital's licence PDF, or null. */
    public function licenceFile(int $hospitalId): ?string
    {
        $stmt = Database::connection()->prepare('SELECT licence_file FROM hospitals WHERE user_id = ?');
        $stmt->execute([$hospitalId]);
        $file = $stmt->fetchColumn();

        return ($file === false || $file === '') ? null : (string) $file;
    }

    /**
     * Verify or reject a hospital.
     *
     * @return array{changed:bool, name:string, old:string}
     * @throws NotFoundException when there is no such hospital
     */
    public function setVerification(int $hospitalId, string $newStatus, int $adminId, string $ip): array
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT hospital_name, verification_status FROM hospitals WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$hospitalId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new NotFoundException('That hospital was not found.');
            }

            $old = (string) $row['verification_status'];
            if ($old === $newStatus) {
                $pdo->rollBack();   // nothing to change
                return ['changed' => false, 'name' => (string) $row['hospital_name'], 'old' => $old];
            }

            $pdo->prepare('UPDATE hospitals SET verification_status = ? WHERE user_id = ?')
                ->execute([$newStatus, $hospitalId]);

            (new AuditRepository())->log(
                $adminId,
                ($newStatus === 'verified') ? 'hospital.verified' : 'hospital.rejected',
                'hospitals',
                $hospitalId,
                $old,
                $newStatus,
                $ip
            );

            $pdo->commit();

            return ['changed' => true, 'name' => (string) $row['hospital_name'], 'old' => $old];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function hospitalWhere(ListQuery $list, string $status): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($status !== '') {
            $where[]  = 'h.verification_status = ?';
            $params[] = $status;
        }

        if ($list->search() !== '') {
            $where[] = '(h.hospital_name LIKE ? OR h.licence_no LIKE ? OR u.email LIKE ? OR c.city_name LIKE ?)';
            $like    = $list->likeTerm();
            array_push($params, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    // ------------------------------------------------------------------ users

    public function countUsers(ListQuery $list, string $role, string $status): int
    {
        [$where, $params] = $this->userWhere($list, $role, $status);

        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM users u WHERE $where");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function listUsers(ListQuery $list, string $role, string $status): array
    {
        [$where, $params] = $this->userWhere($list, $role, $status);

        $sql = sprintf(
            "SELECT u.id, u.name, u.email, u.role, u.status, u.created_at
               FROM users u
              WHERE %s
           ORDER BY %s, u.id DESC
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

    /**
     * Block or unblock a user.
     *
     * @return array{changed:bool, name:string}
     * @throws NotFoundException  when there is no such user
     * @throws ForbiddenException when the user is an administrator
     */
    public function setUserStatus(int $userId, string $newStatus, int $adminId, string $ip): array
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT name, role, status FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new NotFoundException('That user was not found.');
            }
            if ($row['role'] === 'admin') {
                // Blocking an administrator could lock everybody out of the admin pages.
                throw new ForbiddenException('Administrator accounts cannot be blocked.');
            }

            $old = (string) $row['status'];
            if ($old === $newStatus) {
                $pdo->rollBack();
                return ['changed' => false, 'name' => (string) $row['name']];
            }

            $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$newStatus, $userId]);

            (new AuditRepository())->log(
                $adminId,
                ($newStatus === 'blocked') ? 'user.blocked' : 'user.unblocked',
                'users',
                $userId,
                $old,
                $newStatus,
                $ip
            );

            $pdo->commit();

            return ['changed' => true, 'name' => (string) $row['name']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function userWhere(ListQuery $list, string $role, string $status): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($role !== '') {
            $where[]  = 'u.role = ?';
            $params[] = $role;
        }
        if ($status !== '') {
            $where[]  = 'u.status = ?';
            $params[] = $status;
        }
        if ($list->search() !== '') {
            $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
            $like    = $list->likeTerm();
            array_push($params, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }
}
