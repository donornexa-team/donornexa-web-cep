<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Models\User;
use PDOException;

/**
 * All SQL for accounts: finding a user at login and creating donors and
 * hospitals. Prepared statements only; user input is never put into the SQL text.
 */
class UserRepository
{
    /** The account with this email (plus the hospital status, if it is a hospital), or null. */
    public function findByEmail(string $email): ?User
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.name, u.email, u.password_hash, u.role, u.status,
                    h.verification_status
               FROM users u
               LEFT JOIN hospitals h ON h.user_id = u.id
              WHERE u.email = ?'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return ($row === false) ? null : User::fromRow($row);    }

    /** The account with this id (for the "keep me logged in" cookie), or null. */
    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, name, email, password_hash, role, status FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return ($row === false) ? null : $row;
    }

    /**
     * Login attempts are written to audit_logs (no extra table needed):
     * action 'login.failed' / 'login.success', new_value = the email typed.
     */
    public function recordLoginAttempt(string $email, string $ip, bool $success, ?int $userId = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity, entity_id, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $success ? 'login.success' : 'login.failed',
            'users',
            $userId,
            substr($email, 0, 255),
            substr($ip, 0, 45),
        ]);
    }

    /**
     * Failed logins in the last $minutes minutes since the last successful
     * login, counted for this email from this IP address.
     */
    public function failedLoginsForEmail(string $email, string $ip, int $minutes): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM audit_logs
              WHERE action = 'login.failed' AND new_value = ? AND ip_address = ?
                AND created_at > (NOW() - INTERVAL $minutes MINUTE)
                AND id > COALESCE((SELECT MAX(s.id) FROM audit_logs s
                                    WHERE s.action = 'login.success'
                                      AND s.new_value = ? AND s.ip_address = ?), 0)"
        );
        $stmt->execute([$email, $ip, $email, $ip]);

        return (int) $stmt->fetchColumn();
    }

    /** Failed logins from this IP address (any email) in the last $minutes minutes. */
    public function failedLoginsForIp(string $ip, int $minutes): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM audit_logs
              WHERE action = 'login.failed' AND ip_address = ?
                AND created_at > (NOW() - INTERVAL $minutes MINUTE)"
        );
        $stmt->execute([$ip]);

        return (int) $stmt->fetchColumn();
    }

    public function emailExists(string $email): bool
    {
        return $this->exists('SELECT 1 FROM users WHERE email = ?', $email);
    }

    public function cnicExists(string $cnic): bool
    {
        return $this->exists('SELECT 1 FROM donors WHERE cnic = ?', $cnic);
    }

    public function licenceExists(string $licenceNo): bool
    {
        return $this->exists('SELECT 1 FROM hospitals WHERE licence_no = ?', $licenceNo);
    }

    /** Cities for the drop-down lists: [['id' => 1, 'city_name' => 'Karachi'], ...]. */
    public function cities(): array
    {
        return Database::connection()
            ->query('SELECT id, city_name FROM cities ORDER BY city_name')
            ->fetchAll();
    }

    /**
     * Create a donor: one row in users and one in donors, inside ONE transaction,
     * so we never end up with a login that has no donor profile.
     *
     * @return int the new user id
     * @throws ValidationException when the email or CNIC was taken in the meantime
     */
    public function createDonor(array $d): int
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$d['name'], $d['email'], $d['password_hash'], 'donor']);
            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO donors (user_id, blood_group, dob, weight_kg, cnic, city_id, phone)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId, $d['blood_group'], $d['dob'], $d['weight_kg'],
                $d['cnic'], $d['city_id'], $d['phone'],
            ]);

            $pdo->commit();
            return $userId;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->throwIfDuplicate($e);
            throw $e;
        }
    }

    /**
     * Create a hospital (users + hospitals in one transaction). The account
     * starts as 'pending' (column default) until an admin verifies it.
     *
     * @return int the new user id
     * @throws ValidationException when the email or licence number was taken in the meantime
     */
    public function createHospital(array $h): int
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$h['hospital_name'], $h['email'], $h['password_hash'], 'hospital']);
            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO hospitals (user_id, hospital_name, licence_no, licence_file, city_id, address, phone)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId, $h['hospital_name'], $h['licence_no'], $h['licence_file'],
                $h['city_id'], ($h['address'] === '' ? null : $h['address']), $h['phone'],
            ]);

            $pdo->commit();
            return $userId;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->throwIfDuplicate($e);
            throw $e;
        }
    }

    private function exists(string $sql, string $value): bool
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$value]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Two people can submit the same email at the same moment: both pass the
     * "already exists?" check and one INSERT then hits the UNIQUE key. Turn
     * that database error into a normal form error instead of a 500 page.
     */
    private function throwIfDuplicate(PDOException $e): void
    {
        if ((string) $e->getCode() !== '23000') {
            return;
        }

        $message = $e->getMessage();
        if (strpos($message, 'uq_users_email') !== false) {
            throw new ValidationException(['email' => 'This email is already registered.']);
        }
        if (strpos($message, 'uq_donors_cnic') !== false) {
            throw new ValidationException(['cnic' => 'This CNIC is already registered.']);
        }
        if (strpos($message, 'uq_hospitals_licence') !== false) {
            throw new ValidationException(['licence_no' => 'This licence number is already registered.']);
        }
    }
}
