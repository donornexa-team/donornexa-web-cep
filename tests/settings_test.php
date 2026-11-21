<?php
/**
 * Test script for admin settings and cities. Part 1 needs no database;
 * part 2 needs the seed data and restores everything it changes.
 * Run from the project folder: php tests/settings_test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Contracts\SettingRule;
use DonorNexa\Core\Database;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\SettingsRepository;
use DonorNexa\Services\IntegerRangeRule;
use DonorNexa\Services\SettingsValidator;

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) { $failed++; }
}
function thrown(callable $fn): string
{
    try { $fn(); } catch (Throwable $e) { return get_class($e); }
    return '';
}

echo PHP_EOL, '1. Validation (no database)', PHP_EOL;
$v = new SettingsValidator();
$ok = ['cooldown_days' => '90', 'top_n_notify' => '5', 'critical_pool_multiplier' => '2',
       'max_open_critical' => '3', 'no_response_hours' => '24', 'trust_penalty_cancel' => '5'];
check('the default settings are valid', $v->validateSettings($ok) === []);
check('every rule implements the SettingRule interface', array_filter($v->rules(), function ($r) { return !$r instanceof SettingRule; }) === []);
check('cool-down below 30 is refused', isset($v->validateSettings(['cooldown_days' => '29'] + $ok)['cooldown_days']));
check('cool-down above 365 is refused', isset($v->validateSettings(['cooldown_days' => '366'] + $ok)['cooldown_days']));
check('text instead of a number is refused', isset($v->validateSettings(['top_n_notify' => 'five'] + $ok)['top_n_notify']));
check('a negative number is refused', isset($v->validateSettings(['trust_penalty_cancel' => '-1'] + $ok)['trust_penalty_cancel']));
check('SQL text is refused', isset($v->validateSettings(['max_open_critical' => "3; DROP TABLE users"] + $ok)['max_open_critical']));
check('an empty value is refused', isset($v->validateSettings(['no_response_hours' => ''] + $ok)['no_response_hours']));
check('0 penalty is allowed', !isset($v->validateSettings(['trust_penalty_cancel' => '0'] + $ok)['trust_penalty_cancel']));
check('IntegerRangeRule describes itself', (new IntegerRangeRule(1, 9))->describe() === 'whole number from 1 to 9');
check('a normal city name is accepted', $v->cityNameError('Mirpur Khas') === null && $v->cityNameError("Dera Ghazi Khan") === null);
check('a city name with markup or digits is refused', $v->cityNameError('<b>x</b>') !== null && $v->cityNameError('City1') !== null);
check('a too short city name is refused', $v->cityNameError('Ab') !== null);
check('a province outside the list is refused', $v->provinceError('Atlantis') !== null && $v->provinceError('Sindh') === null);

echo PHP_EOL, '2. With the real database', PHP_EOL;
$pdo = null;
try { $pdo = Database::connection(); } catch (Throwable $e) { echo "  SKIPPED  no database connection\n"; }
$admin = $pdo ? $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn() : false;
if ($pdo && $admin === false) { echo "  SKIPPED  no admin user (import the seeds)\n"; $pdo = null; }

if ($pdo) {
    $admin     = (int) $admin;
    $repo      = new SettingsRepository();
    $auditFrom = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
    $original  = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $cityId    = 0;
    $count = function (string $sql, array $p = []) use ($pdo): int {
        $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn();
    };

    try {
        $changes = $repo->saveSettings(['cooldown_days' => '60', 'top_n_notify' => $original['top_n_notify']], $admin, '127.0.0.1');
        check('only the changed setting is saved', count($changes) === 1 && $changes[0]['key'] === 'cooldown_days'
            && $changes[0]['old'] === $original['cooldown_days'] && $changes[0]['new'] === '60');
        check('the database holds the new value', (int) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'cooldown_days'")->fetchColumn() === 60);
        check('the change is in the audit log (old and new value)',
            $count("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'setting.updated' AND user_id = ? AND new_value = 'cooldown_days: 60'", [$auditFrom, $admin]) === 1);
        check('saving the same values again changes nothing and logs nothing',
            $repo->saveSettings(['cooldown_days' => '60'], $admin, '127.0.0.1') === []
            && $count("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'setting.updated'", [$auditFrom]) === 1);
        check('an unknown setting key is ignored', $repo->saveSettings(['evil_key' => '1'], $admin, '127.0.0.1') === []
            && $count("SELECT COUNT(*) FROM settings WHERE setting_key = 'evil_key'") === 0);
        $eligible60 = (int) $pdo->query('SELECT COUNT(*) FROM v_eligible_donors')->fetchColumn();
        $repo->saveSettings(['cooldown_days' => '365'], $admin, '127.0.0.1');
        $eligible365 = (int) $pdo->query('SELECT COUNT(*) FROM v_eligible_donors')->fetchColumn();
        check('the eligibility view follows the setting (a longer cool-down never adds eligible donors)', $eligible365 <= $eligible60);

        $cityId = $repo->addCity('Testville', 'Sindh', $admin, '127.0.0.1');
        check('a city can be added', $count('SELECT COUNT(*) FROM cities WHERE id = ? AND city_name = ?', [$cityId, 'Testville']) === 1);
        check('adding the same city again shows a form error (not a crash)',
            thrown(function () use ($repo, $admin) { $repo->addCity('Testville', 'Punjab', $admin, '127.0.0.1'); }) === ValidationException::class);
        $repo->updateCity($cityId, 'Testville City', 'Punjab', $admin, '127.0.0.1');
        check('a city can be renamed and moved to another province',
            $count('SELECT COUNT(*) FROM cities WHERE id = ? AND city_name = ? AND province = ?', [$cityId, 'Testville City', 'Punjab']) === 1);
        check('renaming to a name that already exists is refused',
            thrown(function () use ($repo, $admin, $cityId, $pdo) {
                $other = (string) $pdo->query("SELECT city_name FROM cities WHERE id <> $cityId LIMIT 1")->fetchColumn();
                $repo->updateCity($cityId, $other, 'Punjab', $admin, '127.0.0.1');
            }) === ValidationException::class);
        check('updating a city that does not exist gives "not found"',
            thrown(function () use ($repo, $admin) { $repo->updateCity(999999, 'Nowhere City', 'Sindh', $admin, '127.0.0.1'); }) === NotFoundException::class);
        check('city create and update are in the audit log',
            $count("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action IN ('city.created', 'city.updated')", [$auditFrom]) === 2);
        check('the city list shows donor and hospital counts',
            array_filter($repo->cities(), function ($c) { return !isset($c['donors'], $c['hospitals']); }) === []);
    } finally {
        $restore = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        foreach ($original as $key => $value) { $restore->execute([$value, $key]); }
        if ($cityId > 0) { $pdo->exec('DELETE FROM cities WHERE id = ' . $cityId); }
        $pdo->prepare('DELETE FROM audit_logs WHERE id > ?')->execute([$auditFrom]);
    }
}

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
