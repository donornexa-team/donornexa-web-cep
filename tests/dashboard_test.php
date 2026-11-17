<?php
/**
 * Test script for the donor and hospital dashboard queries (read-only, changes nothing).
 * Needs the full seed data; without it the checks are skipped. Run: php tests/dashboard_test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Database;
use DonorNexa\Repositories\DashboardRepository;

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) { $failed++; }
}

try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo "  SKIPPED  no database connection\n";
    exit(0);
}

$dash = new DashboardRepository();
$q = function (string $sql) use ($pdo) { return $pdo->query($sql)->fetchColumn(); };

$donor = (int) $q("SELECT donor_id FROM request_matches GROUP BY donor_id ORDER BY COUNT(*) DESC LIMIT 1");
$hosp  = (int) $q("SELECT hospital_id FROM blood_requests GROUP BY hospital_id ORDER BY COUNT(*) DESC LIMIT 1");
if ($donor === 0 || $hosp === 0) { echo "  SKIPPED  no seed data (import seed_1..3)\n"; exit(0); }

echo PHP_EOL, 'Donor dashboard', PHP_EOL;
$c = $dash->donorCounts($donor);
check('waiting count matches a direct count',
    $c['waiting'] === (int) $q("SELECT COUNT(*) FROM request_matches m JOIN blood_requests r ON r.id = m.request_id
        WHERE m.donor_id = $donor AND m.status = 'notified' AND r.status = 'open' AND r.required_by > NOW()"));
check('donations count matches the donations table',
    $c['donations'] === (int) $q("SELECT COUNT(*) FROM donations dn JOIN request_matches m ON m.id = dn.match_id WHERE m.donor_id = $donor"));
check('a donor with no matches gets zeros', $dash->donorCounts(0) === ['waiting' => 0, 'accepted_open' => 0, 'donations' => 0]);
$w = $dash->donorWaiting($donor, 3);
check('waiting list has at most 3 rows and no request that is closed or past its deadline',
    count($w) <= 3 && array_filter($w, function ($r) { return strtotime($r['required_by']) <= time(); }) === []);
$rank = ['critical' => 0, 'urgent' => 1, 'normal' => 2];
$sorted = true;
for ($i = 1; $i < count($w); $i++) { if ($rank[$w[$i]['urgency']] < $rank[$w[$i - 1]['urgency']]) { $sorted = false; } }
check('most urgent requests come first', $sorted);

echo PHP_EOL, 'Hospital dashboard', PHP_EOL;
$h = $dash->hospitalCounts($hosp);
check('total equals the hospital\'s request count', $h['total'] === (int) $q("SELECT COUNT(*) FROM blood_requests WHERE hospital_id = $hosp"));
check('status groups add up to the total', $h['open_requests'] + $h['fulfilled'] + $h['cancelled_closed'] + $h['expired'] === $h['total']);
$o = $dash->hospitalOpen($hosp, 5);
check('open list only holds this hospital\'s open requests',
    count($o) === min(5, $h['open_requests'])
    && array_filter($o, function ($r) use ($pdo, $hosp) {
        return (int) $pdo->query("SELECT COUNT(*) FROM blood_requests WHERE id = " . (int) $r['id'] . " AND hospital_id = $hosp AND status = 'open'")->fetchColumn() !== 1;
    }) === []);
check('a hospital with no requests gets zeros', array_sum($dash->hospitalCounts(0)) === 0);

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
