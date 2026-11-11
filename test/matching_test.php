<?php
/**
 * Test script for MatchingService. Run from the project folder:
 *
 *     php tests/matching_test.php
 *
 * Needs the database imported with the full seed data (schema.sql and
 * seed_1..3) and config/db.local.php. Prints PASS/FAIL for each check and a
 * sample ranking you can screenshot for the report.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Services\MatchingService;

$service = new MatchingService();
$pdo     = Database::connection();
$failed  = 0;

function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

function sameSet(array $a, array $b): bool
{
    sort($a);
    sort($b);
    return $a === $b;
}

// ---------------------------------------------------------------- 1
echo PHP_EOL, '1. Blood compatibility map (read from the database)', PHP_EOL;
$expected = [
    'A+'  => ['A+', 'A-', 'O+', 'O-'],
    'A-'  => ['A-', 'O-'],
    'B+'  => ['B+', 'B-', 'O+', 'O-'],
    'B-'  => ['B-', 'O-'],
    'AB+' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
    'AB-' => ['A-', 'B-', 'AB-', 'O-'],
    'O+'  => ['O+', 'O-'],
    'O-'  => ['O-'],
];
foreach ($expected as $recipient => $donors) {
    check("$recipient can receive from " . implode(', ', $donors),
        sameSet($service->compatibleGroups($recipient), $donors));
}

// ---------------------------------------------------------------- 2
echo PHP_EOL, '2. Scoring (no database needed)', PHP_EOL;
$base = ['donor_id' => 1, 'blood_group' => 'O+', 'city_id' => 2,
         'days_since_donation' => null, 'past_total' => 4, 'past_responded' => 4];

check('best case = 100 (exact group, same city, never donated, always responds)',
    $service->scoreDonor($base, 'O+', 2)['score'] === 100.0);
check('worst case = 0 (other group, other city, donated today, never responds)',
    $service->scoreDonor(['donor_id' => 1, 'blood_group' => 'O-', 'city_id' => 9,
        'days_since_donation' => 0, 'past_total' => 4, 'past_responded' => 0], 'O+', 2)['score'] === 0.0);
check('exact 30 + city 20 + 90 days 15 + 50% response 10 = 75',
    $service->scoreDonor(['donor_id' => 1, 'blood_group' => 'O+', 'city_id' => 2,
        'days_since_donation' => 90, 'past_total' => 4, 'past_responded' => 2], 'O+', 2)['score'] === 75.0);
check('days since last donation are capped at 180',
    $service->scoreDonor(array_merge($base, ['days_since_donation' => 900]), 'O+', 2)['score']
    === $service->scoreDonor(array_merge($base, ['days_since_donation' => 180]), 'O+', 2)['score']);
check('donor with no history gets a neutral 50% response rate (10 points)',
    $service->scoreDonor(array_merge($base, ['past_total' => 0, 'past_responded' => 0]), 'O+', 2)['breakdown']['response_rate'] === 10.0);
check('same-city donor outranks an otherwise identical donor from another city',
    $service->scoreDonor($base, 'O+', 2)['score'] > $service->scoreDonor(array_merge($base, ['city_id' => 5]), 'O+', 2)['score']);

// ---------------------------------------------------------------- 3
echo PHP_EOL, '3. Eligibility (only donors from v_eligible_donors)', PHP_EOL;
$eligibleIds = $pdo->query('SELECT user_id FROM v_eligible_donors')->fetchAll(PDO::FETCH_COLUMN);
$returned    = [];
foreach (MatchingService::BLOOD_GROUPS as $group) {
    foreach ($service->findMatches($group, 2, 'critical') as $m) {
        $returned[$m['donor_id']] = true;
    }
}
check('every returned donor is in v_eligible_donors', array_diff(array_keys($returned), $eligibleIds) === []);

$ineligible = $pdo->query(
    "SELECT d.user_id FROM donors d JOIN users u ON u.id = d.user_id
      WHERE u.status <> 'active' OR d.is_available = 0 OR d.weight_kg < 50
         OR TIMESTAMPDIFF(YEAR, d.dob, CURDATE()) NOT BETWEEN 18 AND 60
         OR (d.last_donation_date IS NOT NULL AND DATEDIFF(CURDATE(), d.last_donation_date) < 90)"
)->fetchAll(PDO::FETCH_COLUMN);
check('blocked, unavailable, underweight, over-60 and cooling-down donors are never returned',
    array_intersect(array_keys($returned), $ineligible) === []);

// ---------------------------------------------------------------- 4
echo PHP_EOL, '4. Ranking and pool size', PHP_EOL;
$normal   = $service->findMatches('AB+', 2, 'normal');
$critical = $service->findMatches('AB+', 2, 'critical');
check('normal request returns the top 5 donors (setting top_n_notify)', count($normal) === 5);
check('critical request widens the pool to 10 (5 x critical_pool_multiplier)', count($critical) === 10);
$scores = array_column($critical, 'score');
$sorted = $scores;
rsort($sorted);
check('results are sorted from highest to lowest score', $scores === $sorted);
check('the normal top 5 are the first 5 of the critical list',
    array_column($normal, 'donor_id') === array_slice(array_column($critical, 'donor_id'), 0, 5));

// ---------------------------------------------------------------- 5
echo PHP_EOL, '5. Other rules', PHP_EOL;
$alreadyMatched = $pdo->query('SELECT donor_id FROM request_matches WHERE request_id = 15')->fetchAll(PDO::FETCH_COLUMN);
$forRequest15   = array_column($service->findMatches('O+', 3, 'critical', 15), 'donor_id');
check('donors already matched to the request are skipped',
    $alreadyMatched !== [] && array_intersect($forRequest15, $alreadyMatched) === []);

$privateKeys = ['name', 'email', 'phone', 'cnic', 'password_hash'];
check('no personal data (name, email, phone, CNIC) is returned',
    array_intersect($privateKeys, array_keys($critical[0])) === []);

foreach ([['X+', 'critical'], ['O+', 'extreme']] as [$group, $urgency]) {
    try {
        $service->findMatches($group, 2, $urgency);
        check("invalid input ($group / $urgency) is rejected", false);
    } catch (ValidationException $e) {
        check("invalid input ($group / $urgency) is rejected", true);
    }
}

// ---------------------------------------------------------------- sample
echo PHP_EOL, 'Sample: critical AB+ request, hospital in Hyderabad (city 2)', PHP_EOL;
printf("  %-4s %-8s %-6s %-6s %-6s %-6s %-6s %-6s%s", '#', 'Donor', 'Group', 'Score', 'Exact', 'City', 'Days', 'Resp', PHP_EOL);
foreach ($critical as $i => $m) {
    $b = $m['breakdown'];
    printf("  %-4d %-8d %-6s %-6.2f %-6d %-6d %-6.2f %-6.2f%s", $i + 1, $m['donor_id'], $m['blood_group'],
        $m['score'], $b['exact_group'], $b['same_city'], $b['days_since'], $b['response_rate'], PHP_EOL);
}

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
