<?php
/**
 * Test script for the life of a blood request after matching: donor accept /
 * decline, phone reveal, marking a donation, cancel / close (trust score),
 * auto-expiry and badges. Run from the project folder:
 *
 *     php tests/response_test.php
 *
 * Part 1 needs no database. Part 2 needs the full seed data (schema.sql and
 * seed_1..3); without it, part 2 is skipped. Part 2 creates test requests and
 * puts the database back exactly as it was at the end.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\DonationRepository;
use DonorNexa\Repositories\InventoryRepository;
use DonorNexa\Repositories\MatchRepository;
use DonorNexa\Repositories\RequestRepository;
use DonorNexa\Services\BadgeService;
use DonorNexa\Services\ExpiryService;
use DonorNexa\Services\MatchResponseService;
use DonorNexa\Services\RequestLifecycleService;
use DonorNexa\Services\RequestService;

$failed = 0;

function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

/** Run $fn and report the class of the exception it throws ('' when none). */
function thrown(callable $fn): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return get_class($e);
    }
    return '';
}

echo PHP_EOL, '1. Badges and decline reason (no database)', PHP_EOL;
$badges = new BadgeService();
check('0 donations: no badge earned', array_column($badges->forCount(0), 'earned') === [false, false, false]);
check('1 donation earns the first badge', array_column($badges->forCount(1), 'earned') === [true, false, false]);
check('5 donations earn two badges', array_column($badges->forCount(5), 'earned') === [true, true, false]);
check('10 donations earn all three', array_column($badges->forCount(12), 'earned') === [true, true, true]);
check('donations until the next badge', $badges->untilNext(0) === 1 && $badges->untilNext(3) === 2 && $badges->untilNext(10) === null);

$responses = new MatchResponseService();
check('an empty reason is fine', $responses->reasonError('') === null);
check('a normal reason is fine', $responses->reasonError("I'm travelling this week, sorry.") === null);
check('markup in the reason is rejected', $responses->reasonError('<script>alert(1)</script>') !== null);
check('a reason over 255 characters is rejected', $responses->reasonError(str_repeat('a', 256)) !== null);

echo PHP_EOL, '2. With the real database (full seed data needed)', PHP_EOL;

$pdo = null;
try {
    $pdo = Database::connection();
} catch (Throwable $e) {
    echo '  SKIPPED  no database connection (check MySQL and config/db.local.php)', PHP_EOL;
}
$hospitalId = null;
if ($pdo !== null) {
    $hospitalId = $pdo->query("SELECT user_id FROM hospitals WHERE verification_status = 'verified' ORDER BY user_id LIMIT 1")->fetchColumn();
    if ($hospitalId === false) {
        $hospitalId = null;
        echo '  SKIPPED  no verified hospital found (import schema.sql and seed_1..3)', PHP_EOL;
    }
}

if ($pdo !== null && $hospitalId !== null) {
    $hospitalId = (int) $hospitalId;
    $otherHospital = (int) $pdo->query("SELECT user_id FROM hospitals WHERE user_id <> $hospitalId ORDER BY user_id LIMIT 1")->fetchColumn();

    // ---- remember everything the test may change
    $auditFrom  = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
    $notifyFrom = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn();
    $lastDonations = $pdo->query('SELECT user_id, last_donation_date FROM donors')->fetchAll(PDO::FETCH_KEY_PAIR);
    $inventory  = $pdo->query('SELECT hospital_id, blood_group, units_available, updated_at FROM blood_inventory')->fetchAll();
    $trust      = $pdo->query('SELECT user_id, trust_score FROM hospitals')->fetchAll(PDO::FETCH_KEY_PAIR);
    $matchStates = $pdo->query('SELECT id, status, responded_at, decline_reason FROM request_matches')->fetchAll();
    $requestStates = $pdo->query('SELECT id, status, units_fulfilled FROM blood_requests')->fetchAll();
    $created = [];

    $service   = new RequestService();
    $requests  = new RequestRepository();
    $lifecycle = new RequestLifecycleService();
    $ip        = '127.0.0.1';
    $when      = (new DateTimeImmutable('now'))->modify('+3 days')->format('Y-m-d\TH:i');

    $raise = function (string $group, int $units, string $urgency = 'normal') use ($service, $hospitalId, $when, $ip, &$created): array {
        $result    = $service->raise($hospitalId, [
            'blood_group' => $group, 'units_needed' => (string) $units, 'urgency' => $urgency,
            'required_by' => $when, 'ward_notes' => 'test',
        ], $ip);
        $created[] = $result['request_id'];
        return $result;
    };
    $count = function (string $sql, array $params = []) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    };

    try {
        // A request for 1 unit, with several matched donors.
        $one = $raise('O+', 1)['request_id'];
        $donors = $pdo->query("SELECT donor_id FROM request_matches WHERE request_id = $one ORDER BY score DESC")->fetchAll(PDO::FETCH_COLUMN);
        check('the test request has at least 3 matched donors', count($donors) >= 3);
        [$a, $b, $c] = array_map('intval', array_slice($donors, 0, 3));

        // ---- accept
        $responses->accept($a, $one, $ip);
        $state = $pdo->query("SELECT status, responded_at FROM request_matches WHERE request_id = $one AND donor_id = $a")->fetch();
        check('accept: the match becomes "accepted" with a response time', $state['status'] === 'accepted' && $state['responded_at'] !== null);
        check('accept is written to the audit log',
            $count("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'match.accepted' AND user_id = ?", [$auditFrom, $a]) === 1);
        check('the hospital gets a notification',
            $count('SELECT COUNT(*) FROM notifications WHERE id > ? AND user_id = ?', [$notifyFrom, $hospitalId]) === 1);
        check('answering twice is refused', thrown(function () use ($responses, $a, $one, $ip) { $responses->accept($a, $one, $ip); }) === ValidationException::class);

        // ---- over-allocation guard (1 unit asked, 1 donor already accepted)
        check('a second donor cannot accept a request that is already full',
            thrown(function () use ($responses, $b, $one, $ip) { $responses->accept($b, $one, $ip); }) === ValidationException::class);
        check('still exactly one accepted donor',
            $count("SELECT COUNT(*) FROM request_matches WHERE request_id = ? AND status = 'accepted'", [$one]) === 1);

        // ---- a donor that was never matched
        $stranger = (int) $pdo->query("SELECT user_id FROM donors WHERE user_id NOT IN (SELECT donor_id FROM request_matches WHERE request_id = $one) LIMIT 1")->fetchColumn();
        check('a donor who was not matched gets "not found"',
            thrown(function () use ($responses, $stranger, $one, $ip) { $responses->accept($stranger, $one, $ip); }) === NotFoundException::class);

        // ---- decline
        $responses->decline($b, $one, 'Travelling this week', $ip);
        $row = $pdo->query("SELECT status, decline_reason FROM request_matches WHERE request_id = $one AND donor_id = $b")->fetch();
        check('decline: status and reason are saved', $row['status'] === 'declined' && $row['decline_reason'] === 'Travelling this week');
        check('decline with markup is refused and changes nothing',
            thrown(function () use ($responses, $c, $one, $ip) { $responses->decline($c, $one, '<b>x</b>', $ip); }) === ValidationException::class
            && $count("SELECT COUNT(*) FROM request_matches WHERE request_id = ? AND donor_id = ? AND status = 'notified'", [$one, $c]) === 1);

        // ---- phone privacy
        $shown = $requests->matchesFor($one);
        $phones = [];
        foreach ($shown as $m) {
            $phones[$m['status']] = ($phones[$m['status']] ?? []) + [count($phones[$m['status']] ?? []) => $m['phone']];
        }
        check('phone is returned for the donor who accepted', in_array(true, array_map(function ($m) { return $m['status'] === 'accepted' && $m['phone'] !== null; }, $shown), true));
        check('phone is NULL for donors who declined or did not answer',
            array_filter($shown, function ($m) { return $m['status'] !== 'accepted' && $m['phone'] !== null; }) === []);
        $requests->recordPhoneReveals($hospitalId, $shown, $ip);
        $requests->recordPhoneReveals($hospitalId, $shown, $ip);
        check('the phone reveal is audited once, not on every page view',
            $count("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'donor.phone_revealed'", [$auditFrom]) === 1);

        // ---- donation
        $matchId = (int) $pdo->query("SELECT id FROM request_matches WHERE request_id = $one AND donor_id = $a")->fetchColumn();
        $stockBefore = (new InventoryRepository())->forHospital($hospitalId)['O+']['units'];
        check('only the owner hospital can mark a donation',
            thrown(function () use ($lifecycle, $otherHospital, $one, $matchId, $ip) { $lifecycle->markDonated($otherHospital, $one, $matchId, $ip); }) === NotFoundException::class);
        check('a donor who did not accept cannot be marked as donated',
            thrown(function () use ($lifecycle, $hospitalId, $one, $pdo, $b, $ip) {
                $m = (int) $pdo->query("SELECT id FROM request_matches WHERE request_id = $one AND donor_id = $b")->fetchColumn();
                $lifecycle->markDonated($hospitalId, $one, $m, $ip);
            }) === ValidationException::class);

        $lifecycle->markDonated($hospitalId, $one, $matchId, $ip);
        $req = $pdo->query("SELECT status, units_fulfilled FROM blood_requests WHERE id = $one")->fetch();
        check('donation: the request is fulfilled (1 of 1 unit)', $req['status'] === 'fulfilled' && (int) $req['units_fulfilled'] === 1);
        check('donation: the match is "donated" and a donation row exists',
            $count("SELECT COUNT(*) FROM request_matches WHERE id = ? AND status = 'donated'", [$matchId]) === 1
            && $count('SELECT COUNT(*) FROM donations WHERE match_id = ?', [$matchId]) === 1);
        check('the TRIGGER moved the donor\'s last donation date to today',
            $count('SELECT COUNT(*) FROM donors WHERE user_id = ? AND last_donation_date = CURDATE()', [$a]) === 1);
        check('one unit was added to the hospital inventory',
            (new InventoryRepository())->forHospital($hospitalId)['O+']['units'] === $stockBefore + 1);
        check('donation and fulfilment are in the audit log',
            $count("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action IN ('donation.completed', 'match.donated', 'request.fulfilled')", [$auditFrom]) === 3);
        check('the donor is no longer eligible (cool-down) and now has 1 donation in the history',
            count((new MatchRepository())->historyFor($a)) >= 1
            && $count('SELECT COUNT(*) FROM v_eligible_donors WHERE user_id = ?', [$a]) === 0);
        check('a fulfilled request cannot take another donation',
            thrown(function () use ($lifecycle, $hospitalId, $one, $matchId, $ip) { $lifecycle->markDonated($hospitalId, $one, $matchId, $ip); }) === ValidationException::class);
        check('a donor cannot answer a request that is fulfilled',
            thrown(function () use ($responses, $c, $one, $ip) { $responses->accept($c, $one, $ip); }) === ValidationException::class);

        // ---- cancel (trust score) and close
        $two = $raise('O+', 1)['request_id'];
        $trustBefore = (int) $pdo->query("SELECT trust_score FROM hospitals WHERE user_id = $hospitalId")->fetchColumn();
        check('another hospital cannot cancel this request',
            thrown(function () use ($lifecycle, $otherHospital, $two, $ip) { $lifecycle->cancel($otherHospital, $two, $ip); }) === NotFoundException::class);
        $lifecycle->cancel($hospitalId, $two, $ip);
        $trustAfter = (int) $pdo->query("SELECT trust_score FROM hospitals WHERE user_id = $hospitalId")->fetchColumn();
        check('cancel: the request is "cancelled"', $count("SELECT COUNT(*) FROM blood_requests WHERE id = ? AND status = 'cancelled'", [$two]) === 1);
        check('cancel: the trust score drops by the penalty setting (5), never below 0',
            $trustAfter === max(0, $trustBefore - 5));
        check('cancel: matched donors are notified',
            $count("SELECT COUNT(*) FROM notifications WHERE id > ? AND message LIKE ?", [$notifyFrom, "Request #$two %cancelled%"]) > 0);
        check('cancelling twice is refused', thrown(function () use ($lifecycle, $hospitalId, $two, $ip) { $lifecycle->cancel($hospitalId, $two, $ip); }) === ValidationException::class);

        $three = $raise('A+', 1)['request_id'];
        $lifecycle->close($hospitalId, $three, $ip);
        check('close: the request is "closed" and the trust score is unchanged',
            $count("SELECT COUNT(*) FROM blood_requests WHERE id = ? AND status = 'closed'", [$three]) === 1
            && (int) $pdo->query("SELECT trust_score FROM hospitals WHERE user_id = $hospitalId")->fetchColumn() === $trustAfter);

        // ---- auto-expiry
        $four = $raise('B+', 1)['request_id'];
        $five = $raise('AB+', 1)['request_id'];
        $pdo->exec("UPDATE blood_requests SET required_by = NOW() - INTERVAL 1 HOUR WHERE id = $four");
        $pdo->exec("UPDATE request_matches SET notified_at = NOW() - INTERVAL 30 HOUR WHERE request_id = $five");
        $report = (new ExpiryService())->sweep();
        check('sweep: the request past its deadline is "expired"', $count("SELECT COUNT(*) FROM blood_requests WHERE id = ? AND status = 'expired'", [$four]) === 1);
        check('sweep: its unanswered notifications are "no_response"',
            $count("SELECT COUNT(*) FROM request_matches WHERE request_id = ? AND status = 'notified'", [$four]) === 0
            && $count("SELECT COUNT(*) FROM request_matches WHERE request_id = ? AND status = 'no_response'", [$four]) > 0);
        check('sweep: notifications older than no_response_hours (24) on an open request are "no_response"',
            $count("SELECT COUNT(*) FROM request_matches WHERE request_id = ? AND status = 'no_response'", [$five]) > 0
            && $count("SELECT COUNT(*) FROM blood_requests WHERE id = ? AND status = 'open'", [$five]) === 1);
        check('sweep: both changes are in the audit log',
            $count("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'request.expired' AND entity_id = ?", [$auditFrom, $four]) === 1
            && $count("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'match.no_response'", [$auditFrom]) > 0);
        check('sweep reports what it changed', $report['requests_expired'] >= 1 && $report['matches_no_response'] >= 1);
        $again = (new ExpiryService())->sweep();
        check('a second sweep finds nothing more to do', $again === ['requests_expired' => 0, 'matches_no_response' => 0]);
        check('a donor cannot accept an expired request',
            thrown(function () use ($pdo, $responses, $four, $ip) {
                $d = (int) $pdo->query("SELECT donor_id FROM request_matches WHERE request_id = $four LIMIT 1")->fetchColumn();
                $responses->accept($d, $four, $ip);
            }) === ValidationException::class);
    } finally {
        // ---- put everything back exactly as it was
        foreach ($created as $id) {
            $pdo->exec("DELETE FROM blood_requests WHERE id = " . (int) $id);   // matches and donations go with it
        }
        foreach ($lastDonations as $id => $date) {
            $pdo->prepare('UPDATE donors SET last_donation_date = ? WHERE user_id = ?')->execute([$date, $id]);
        }
        $pdo->exec('DELETE FROM blood_inventory');
        $insert = $pdo->prepare('INSERT INTO blood_inventory (hospital_id, blood_group, units_available, updated_at) VALUES (?, ?, ?, ?)');
        foreach ($inventory as $row) {
            $insert->execute([$row['hospital_id'], $row['blood_group'], $row['units_available'], $row['updated_at']]);
        }
        foreach ($trust as $id => $score) {
            $pdo->prepare('UPDATE hospitals SET trust_score = ? WHERE user_id = ?')->execute([$score, $id]);
        }
        $restoreMatch = $pdo->prepare('UPDATE request_matches SET status = ?, responded_at = ?, decline_reason = ? WHERE id = ?');
        foreach ($matchStates as $row) {
            $restoreMatch->execute([$row['status'], $row['responded_at'], $row['decline_reason'], $row['id']]);
        }
        $restoreRequest = $pdo->prepare('UPDATE blood_requests SET status = ?, units_fulfilled = ? WHERE id = ?');
        foreach ($requestStates as $row) {
            $restoreRequest->execute([$row['status'], $row['units_fulfilled'], $row['id']]);
        }
        $pdo->prepare('DELETE FROM audit_logs WHERE id > ?')->execute([$auditFrom]);
        $pdo->prepare('DELETE FROM notifications WHERE id > ?')->execute([$notifyFrom]);
    }
}

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
