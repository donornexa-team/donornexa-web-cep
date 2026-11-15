<?php
/**
 * Test script for the hospital blood inventory (InventoryValidator and
 * InventoryRepository). Run from the project folder:
 *
 *     php tests/inventory_test.php
 *
 * Part 1 needs no database. Part 2 uses the real database (schema.sql and the
 * seed data, with a hospital); without it, part 2 is skipped. Part 2 changes
 * one hospital's stock and puts it back exactly as it was at the end.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Database;
use DonorNexa\Repositories\InventoryRepository;
use DonorNexa\Services\InventoryValidator;
use DonorNexa\Services\MatchingService;

$failed = 0;

function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

function allGroups(string $units): array
{
    return array_fill_keys(MatchingService::BLOOD_GROUPS, $units);
}

echo PHP_EOL, '1. Inventory form validation (no database)', PHP_EOL;
$validator = new InventoryValidator();

$result = $validator->validate(allGroups('5'));
check('eight valid numbers are accepted', $result['errors'] === [] && count($result['values']) === 8 && $result['values']['O+'] === 5);
check('0 is allowed (out of stock)', $validator->validate(allGroups('0'))['errors'] === []);
check('5000 is allowed, 5001 is not',
    $validator->validate(allGroups('5000'))['errors'] === [] && count($validator->validate(array_merge(allGroups('1'), ['A+' => '5001']))['errors']) === 1);
foreach (['-1', '1.5', 'abc', '', ' ', '1e3', '12345678'] as $bad) {
    $errors = $validator->validate(array_merge(allGroups('1'), ['B-' => $bad]))['errors'];
    check('"' . $bad . '" is rejected for one group only', array_keys($errors) === ['B-']);
}
check('a missing group is an error', array_keys($validator->validate(['O+' => '1'])['errors']) === array_values(array_diff(MatchingService::BLOOD_GROUPS, ['O+'])));
check('a non-array input gives eight errors', count($validator->validate('x')['errors']) === 8);
check('an array value (units[O+][]=1) is rejected', isset($validator->validate(array_merge(allGroups('1'), ['O+' => ['1']]))['errors']['O+']));
check('unknown groups in the input are ignored', count($validator->validate(array_merge(allGroups('1'), ['X+' => '9']))['values']) === 8);

echo PHP_EOL, '2. With the real database (seed data needed)', PHP_EOL;

$pdo = null;
try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo '  SKIPPED  no database connection (check MySQL and config/db.local.php)', PHP_EOL;
}

$hospitalId = null;
if ($pdo !== null) {
    $hospitalId = $pdo->query('SELECT user_id FROM hospitals ORDER BY user_id LIMIT 1')->fetchColumn();
    if ($hospitalId === false) {
        $hospitalId = null;
        echo '  SKIPPED  no hospital found (import schema.sql and the seed data)', PHP_EOL;
    }
}

if ($pdo !== null && $hospitalId !== null) {
    $hospitalId = (int) $hospitalId;
    $repo       = new InventoryRepository();

    $backup = $pdo->prepare('SELECT hospital_id, blood_group, units_available, updated_at FROM blood_inventory WHERE hospital_id = ?');
    $backup->execute([$hospitalId]);
    $backupRows = $backup->fetchAll();
    $auditFrom  = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();

    try {
        $before = $repo->forHospital($hospitalId);
        check('forHospital() always returns all eight groups', array_keys($before) === MatchingService::BLOOD_GROUPS);

        $values = [];
        foreach ($before as $group => $row) {
            $values[$group] = $row['units'];
        }
        $values['O+'] = $before['O+']['units'] + 7;
        $values['AB-'] = $before['AB-']['units'] + 1;

        $changes = $repo->saveAll($hospitalId, $values, $hospitalId, '127.0.0.1');
        check('only the two changed groups are reported', array_column($changes, 'group') === ['AB-', 'O+']);

        $after = $repo->forHospital($hospitalId);
        check('the new numbers are saved', $after['O+']['units'] === $values['O+'] && $after['AB-']['units'] === $values['AB-']);
        check('other groups are untouched', $after['A+']['units'] === $before['A+']['units']);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'inventory.updated' AND entity_id = ?");
        $stmt->execute([$auditFrom, $hospitalId]);
        check('one audit row per changed group', (int) $stmt->fetchColumn() === 2);

        $stmt = $pdo->prepare("SELECT old_value, new_value FROM audit_logs WHERE id > ? AND action = 'inventory.updated' AND new_value LIKE 'O+:%'");
        $stmt->execute([$auditFrom]);
        $audit = $stmt->fetch();
        check('the audit row shows the old and the new number', $audit !== false
            && $audit['old_value'] === 'O+: ' . $before['O+']['units'] && $audit['new_value'] === 'O+: ' . $values['O+']);

        check('saving the same numbers again changes nothing', $repo->saveAll($hospitalId, $values, $hospitalId, '127.0.0.1') === []);

        $repo->addUnits($hospitalId, 'B+', 3);
        check('addUnits() adds to the stock', $repo->forHospital($hospitalId)['B+']['units'] === $before['B+']['units'] + 3);

        foreach ([['X+', 1], ['O+', 0], ['O+', -2]] as [$group, $units]) {
            try {
                $repo->addUnits($hospitalId, $group, $units);
                check("addUnits($group, $units) is rejected", false);
            } catch (InvalidArgumentException $e) {
                check("addUnits($group, $units) is rejected", true);
            }
        }

        try {
            $repo->saveAll($hospitalId + 1000000, $values, $hospitalId, '127.0.0.1');
            check('a user that is not a hospital cannot save an inventory', false);
        } catch (DonorNexa\Exceptions\ForbiddenException $e) {
            check('a user that is not a hospital cannot save an inventory', !$pdo->inTransaction());
        }
    } finally {
        // Put everything back exactly as it was.
        $pdo->prepare('DELETE FROM blood_inventory WHERE hospital_id = ?')->execute([$hospitalId]);
        $insert = $pdo->prepare('INSERT INTO blood_inventory (hospital_id, blood_group, units_available, updated_at) VALUES (?, ?, ?, ?)');
        foreach ($backupRows as $row) {
            $insert->execute([$row['hospital_id'], $row['blood_group'], $row['units_available'], $row['updated_at']]);
        }
        $pdo->prepare('DELETE FROM audit_logs WHERE id > ? AND action = ?')->execute([$auditFrom, 'inventory.updated']);
    }
}

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
