<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\NotFoundException;
use Throwable;

/**
 * All SQL for the donor's own profile page: read the profile, edit it,
 * switch availability and replace the photo. Prepared statements only.
 */
class DonorRepository
{
    /** The donor's profile (user + donor + city), or null when the user is not a donor. */
    public function findProfile(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.name, u.email, u.status AS account_status,
                    d.blood_group, d.dob, d.weight_kg, d.cnic, d.city_id, d.phone,
                    d.last_donation_date, d.is_available, d.photo, c.city_name
               FROM donors d
               JOIN users u  ON u.id = d.user_id
               JOIN cities c ON c.id = d.city_id
              WHERE d.user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return ($row === false) ? null : $row;
    }

    /** The admin setting "cooldown_days" (90 when it is missing). */
    public function cooldownDays(): int
    {
        $stmt = Database::connection()->query(
            "SELECT setting_value FROM settings WHERE setting_key = 'cooldown_days'"
        );
        $value = $stmt->fetchColumn();

        return ($value === false) ? 90 : (int) $value;
    }

    /**
     * Save the editable fields. The name lives in users, the rest in donors,
     * so both UPDATEs run in ONE transaction: either both change or neither.
     * $d keys: name, weight_kg, city_id, phone, last_donation_date (string or null)
     */
    public function updateProfile(int $userId, array $d): void
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')
                ->execute([$d['name'], $userId]);

            $pdo->prepare(
                'UPDATE donors
                    SET weight_kg = ?, city_id = ?, phone = ?, last_donation_date = ?
                  WHERE user_id = ?'
            )->execute([$d['weight_kg'], $d['city_id'], $d['phone'], $d['last_donation_date'], $userId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function setAvailability(int $userId, bool $available): void
    {
        Database::connection()
            ->prepare('UPDATE donors SET is_available = ? WHERE user_id = ?')
            ->execute([$available ? 1 : 0, $userId]);
    }

    /**
     * Store the new photo file name and return the OLD one (null if there was none),
     * so the caller can delete the old file after the commit.
     */
    public function replacePhoto(int $userId, string $newName): ?string
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT photo FROM donors WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $old = $stmt->fetchColumn();
            if ($old === false) {
                throw new NotFoundException('Donor profile not found.');
            }

            $pdo->prepare('UPDATE donors SET photo = ? WHERE user_id = ?')
                ->execute([$newName, $userId]);

            $pdo->commit();

            return ($old === null || $old === '') ? null : (string) $old;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
