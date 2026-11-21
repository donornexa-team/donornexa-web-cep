<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Exceptions\ValidationException;
use PDOException;
use Throwable;

/**
 * Admin: system settings (cool-down days and the other rules) and the list of cities.
 * Every change runs in ONE transaction and writes audit_logs in that same transaction.
 */
class SettingsRepository
{
    /** @return array[] setting_key, setting_value, description, updated_at */
    public function allSettings(): array
    {
        return Database::connection()
            ->query('SELECT setting_key, setting_value, description, updated_at FROM settings ORDER BY setting_key')
            ->fetchAll();
    }

    /**
     * Save the settings that really changed.
     * The rows are locked first (SELECT ... FOR UPDATE) so two admins saving at the
     * same moment cannot overwrite each other unseen; each change gets an audit row.
     *
     * @param array<string,string> $values key => new value (already validated)
     * @return array[] the changes: ['key' => ..., 'old' => ..., 'new' => ...]
     */
    public function saveSettings(array $values, int $adminId, string $ip): array
    {
        $pdo     = Database::connection();
        $audit   = new AuditRepository();
        $changes = [];

        $pdo->beginTransaction();
        try {
            $current = $pdo->query('SELECT setting_key, setting_value FROM settings FOR UPDATE')
                ->fetchAll(\PDO::FETCH_KEY_PAIR);

            $update = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
            foreach ($values as $key => $new) {
                if (!array_key_exists($key, $current) || (string) $current[$key] === (string) $new) {
                    continue;
                }
                $update->execute([(string) $new, $key]);
                $audit->log($adminId, 'setting.updated', 'settings', null, $key . ': ' . $current[$key], $key . ': ' . $new, $ip);
                $changes[] = ['key' => $key, 'old' => (string) $current[$key], 'new' => (string) $new];
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

    /** @return array[] id, city_name, province, donors, hospitals */
    public function cities(): array
    {
        return Database::connection()->query(
            'SELECT c.id, c.city_name, c.province,
                    (SELECT COUNT(*) FROM donors d    WHERE d.city_id = c.id) AS donors,
                    (SELECT COUNT(*) FROM hospitals h WHERE h.city_id = c.id) AS hospitals
               FROM cities c
           ORDER BY c.city_name'
        )->fetchAll();
    }

    public function addCity(string $name, string $province, int $adminId, string $ip): int
    {
        $pdo = Database::connection();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO cities (city_name, province) VALUES (?, ?)')->execute([$name, $province]);
            $id = (int) $pdo->lastInsertId();
            (new AuditRepository())->log($adminId, 'city.created', 'cities', $id, null, $name . ' (' . $province . ')', $ip);
            $pdo->commit();

            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->throwIfDuplicate($e);
            throw $e;
        }
    }

    public function updateCity(int $id, string $name, string $province, int $adminId, string $ip): void
    {
        $pdo = Database::connection();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT city_name, province FROM cities WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if ($old === false) {
                throw new NotFoundException('City not found.');
            }

            if ($old['city_name'] !== $name || $old['province'] !== $province) {
                $pdo->prepare('UPDATE cities SET city_name = ?, province = ? WHERE id = ?')->execute([$name, $province, $id]);
                (new AuditRepository())->log(
                    $adminId, 'city.updated', 'cities', $id,
                    $old['city_name'] . ' (' . $old['province'] . ')', $name . ' (' . $province . ')', $ip
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->throwIfDuplicate($e);
            throw $e;
        }
    }

    /** A second city with the same name hits the UNIQUE key: show it as a form error, not a 500 page. */
    private function throwIfDuplicate(Throwable $e): void
    {
        if ($e instanceof PDOException && (string) $e->getCode() === '23000' && strpos($e->getMessage(), 'uq_cities_name') !== false) {
            throw new ValidationException(['city_name' => 'A city with this name already exists.']);
        }
    }
}
