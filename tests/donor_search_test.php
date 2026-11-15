<?php
/**
 * Test script for the admin donor search (DonorSearchRepository, used by
 * api/donors-search.php). Run from the project folder:
 *
 *     php tests/donor_search_test.php
 *
 * Needs the database with the full seed data (schema.sql and seed_1..3);
 * without a database the script is skipped. It only reads data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Database;
use DonorNexa\Repositories\DonorSearchRepository;

$failed = 0;

function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo '  SKIPPED  no database connection (check MySQL and config/db.local.php)', PHP_EOL;
    exit(0);
}

$donors = (int) $pdo->query('SELECT COUNT(*) FROM donors')->fetchColumn();
if ($donors === 0) {
    echo '  SKIPPED  no donors found (import schema.sql and seed_1..3)', PHP_EOL;
    exit(0);
}

$search = new DonorSearchRepository();

echo PHP_EOL, 'Donor search', PHP_EOL;
$all = $search->run([]);
check('no filter finds every donor', $all['total'] === $donors);
check('a page has at most 10 rows', count($all['rows']) <= 10 && $all['per_page'] === 10);
check('pages = total / 10 rounded up', $all['pages'] === (int) max(1, ceil($donors / 10)));
check('rows contain no phone, CNIC or email',
    array_intersect(['phone', 'cnic', 'email', 'password_hash'], array_keys($all['rows'][0])) === []);

$names = array_column($all['rows'], 'name');
$sorted = $names;
sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
check('default order is by name, A to Z', $names === $sorted || count($names) < 2);
$desc = $search->run(['sort' => 'name', 'dir' => 'desc']);
check('dir=desc reverses the order', $desc['rows'][0]['name'] >= end($desc['rows'])['name']);

$o = $search->run(['blood_group' => 'O+']);
check('blood group filter: only O+ donors', $o['total'] > 0 && array_unique(array_column($o['rows'], 'blood_group')) === ['O+']);
check('blood group filter count matches the database',
    $o['total'] === (int) $pdo->query("SELECT COUNT(*) FROM donors WHERE blood_group = 'O+'")->fetchColumn());

$first = $all['rows'][0];
$byName = $search->run(['q' => substr($first['name'], 1, 4)]);
check('name search finds the donor (part of the name)', in_array($first['name'], array_column($byName['rows'], 'name'), true));
check('search with no match returns an empty list', $search->run(['q' => 'zzzzzzzzz'])['total'] === 0);
check('% in the search is treated as a plain character', $search->run(['q' => '%'])['total'] === 0);

$eligible = $search->run(['eligible' => '1']);
$eligibleDb = (int) $pdo->query('SELECT COUNT(*) FROM donors d JOIN v_eligible_donors e ON e.user_id = d.user_id')->fetchColumn();
check('eligible filter equals the view v_eligible_donors', $eligible['total'] === $eligibleDb);
check('every row of the eligible filter is marked eligible', array_unique(array_column($eligible['rows'], 'eligible')) === [true]);

$cityId = (string) $pdo->query('SELECT city_id FROM donors LIMIT 1')->fetchColumn();
$city = $search->run(['city_id' => $cityId]);
check('city filter matches the database',
    $city['total'] === (int) $pdo->query('SELECT COUNT(*) FROM donors WHERE city_id = ' . (int) $cityId)->fetchColumn());

check('combined filters: O+ and eligible and a city never return more than O+ alone',
    $search->run(['blood_group' => 'O+', 'eligible' => '1', 'city_id' => $cityId])['total'] <= $o['total']);

$last = $search->run(['page' => '999']);
check('a page number that is too high is moved to the last page', $last['page'] === $last['pages']);

foreach (['sort' => 'password_hash; DROP TABLE users', 'dir' => "'", 'blood_group' => "O+' OR '1'='1", 'city_id' => '1 OR 1=1', 'page' => '-4'] as $key => $value) {
    $result = $search->run([$key => $value]);
    check("hostile $key is ignored safely", $result['total'] === $donors);
}
check('the users table still exists after the hostile input', (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0);

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
