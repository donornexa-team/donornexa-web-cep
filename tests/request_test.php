<?php
/**
 * Test script for raising blood requests (RequestValidator, RequestService,
 * RequestRepository). Run from the project folder:
 *
 *     php tests/request_test.php
 *
 * Parts 1 and 2 need no database (the service is tested with stand-in
 * repository and matching classes). Part 3 uses the real database and needs
 * the full seed data (schema.sql and seed_1..3, with a verified hospital and
 * eligible donors); without it, part 3 is skipped. Part 3 creates test
 * requests and deletes them again at the end.
 *
 * Prints PASS/FAIL for each check; the output can be used for the report's
 * test-case table.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\ForbiddenException;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\RequestRepository;
use DonorNexa\Services\MatchingService;
use DonorNexa\Services\RequestService;
use DonorNexa\Services\RequestValidator;

$failed = 0;

function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

/** Stand-in for RequestRepository: no database, remembers what the service asked it to save. */
class FakeRequestRepository extends RequestRepository
{
    public ?array $profile = null;
    public int $openCritical = 0;
    public int $max = 3;
    public ?array $saved = null;

    public function hospitalProfile(int $userId): ?array
    {
        return $this->profile;
    }

    public function maxOpenCritical(): int
    {
        return $this->max;
    }

    public function openCriticalCount(int $hospitalId): int
    {
        return $this->openCritical;
    }

    public function createWithMatches(
        int $hospitalId,
        array $request,
        array $matches,
        string $message,
        int $maxOpenCritical,
        string $ip
    ): int {
        $this->saved = compact('hospitalId', 'request', 'matches', 'message', 'maxOpenCritical', 'ip');
        return 77;
    }
}

/** Stand-in for MatchingService: returns a fixed list and remembers how it was called. */
class FakeMatching extends MatchingService
{
    public array $result = [];
    public array $calls = [];

    public function __construct()
    {
        // no database needed
    }

    public function findMatches(string $bloodGroup, int $hospitalCityId, string $urgency, int $requestId = 0): array
    {
        $this->calls[] = [$bloodGroup, $hospitalCityId, $urgency];
        return $this->result;
    }
}

function rejectsField(RequestValidator $v, array $base, array $changes, string $field, DateTimeImmutable $now): bool
{
    return isset($v->validate(array_merge($base, $changes), $now)[$field]);
}

// ---------------------------------------------------------------- 1
echo PHP_EOL, '1. Request form validation (RequestValidator)', PHP_EOL;
$v    = new RequestValidator();
$now  = new DateTimeImmutable('2026-11-12 10:00:00');
$good = [
    'blood_group'  => 'O+',
    'units_needed' => '2',
    'urgency'      => 'critical',
    'required_by'  => '2026-11-13T18:30',
    'ward_notes'   => 'Ward 3, bed 12',
];

check('a correct request has no errors', $v->validate($good, $now) === []);
check('"2026-11-13 18:30" (space instead of T) is accepted', $v->validate(array_merge($good, ['required_by' => '2026-11-13 18:30']), $now) === []);
check('notes are optional', $v->validate(array_merge($good, ['ward_notes' => '']), $now) === []);

foreach (['X+', '', 'o+', 'A'] as $group) {
    check("blood group '$group' is rejected", rejectsField($v, $good, ['blood_group' => $group], 'blood_group', $now));
}
foreach (['0', '21', 'abc', '', '2.5', '-1', '1e1'] as $units) {
    check("units '$units' are rejected", rejectsField($v, $good, ['units_needed' => $units], 'units_needed', $now));
}
foreach (['1', '20'] as $units) {
    check("units '$units' are accepted", !rejectsField($v, $good, ['units_needed' => $units], 'units_needed', $now));
}
foreach (['high', '', 'CRITICAL'] as $urgency) {
    check("urgency '$urgency' is rejected", rejectsField($v, $good, ['urgency' => $urgency], 'urgency', $now));
}
foreach (['normal', 'urgent', 'critical'] as $urgency) {
    check("urgency '$urgency' is accepted", !rejectsField($v, $good, ['urgency' => $urgency], 'urgency', $now));
}

check('required-by in the past is rejected', rejectsField($v, $good, ['required_by' => '2026-11-12T09:59'], 'required_by', $now));
check('required-by equal to now is rejected', rejectsField($v, $good, ['required_by' => '2026-11-12T10:00'], 'required_by', $now));
check('required-by one minute from now is accepted', !rejectsField($v, $good, ['required_by' => '2026-11-12T10:01'], 'required_by', $now));
check('required-by exactly 30 days ahead is accepted', !rejectsField($v, $good, ['required_by' => '2026-12-12T10:00'], 'required_by', $now));
check('required-by more than 30 days ahead is rejected', rejectsField($v, $good, ['required_by' => '2026-12-12T10:01'], 'required_by', $now));
foreach (['', 'tomorrow', '2026-02-31T10:00', '13/11/2026 18:30', '2026-11-13'] as $date) {
    check("required-by '$date' is rejected", rejectsField($v, $good, ['required_by' => $date], 'required_by', $now));
}

check('notes with letters, digits and simple punctuation are accepted', !rejectsField($v, $good, ['ward_notes' => 'ICU (Block A) / bed #4, urgent'], 'ward_notes', $now));
check('notes in Urdu letters are accepted', !rejectsField($v, $good, ['ward_notes' => 'وارڈ 3'], 'ward_notes', $now));
check('notes with HTML are rejected', rejectsField($v, $good, ['ward_notes' => '<script>alert(1)</script>'], 'ward_notes', $now));
check('notes with a double quote are rejected', rejectsField($v, $good, ['ward_notes' => 'bed "5"'], 'ward_notes', $now));
check('notes of 255 characters are accepted', !rejectsField($v, $good, ['ward_notes' => str_repeat('a', 255)], 'ward_notes', $now));
check('notes of 256 characters are rejected', rejectsField($v, $good, ['ward_notes' => str_repeat('a', 256)], 'ward_notes', $now));
check('a missing field counts as empty (no PHP warning)', count($v->validate([], $now)) === 4);

// ---------------------------------------------------------------- 2
echo PHP_EOL, '2. Raising a request (RequestService, no database)', PHP_EOL;
$when  = (new DateTimeImmutable('now'))->modify('+2 days')->format('Y-m-d\TH:i');
$input = array_merge($good, ['required_by' => $when]);
$hospital = [
    'user_id' => 5, 'hospital_name' => 'City General Hospital', 'city_id' => 2, 'city_name' => 'Hyderabad',
    'verification_status' => 'verified', 'trust_score' => 100, 'account_status' => 'active',
];
$matches = [
    ['donor_id' => 11, 'score' => 88.5],
    ['donor_id' => 12, 'score' => 71.0],
    ['donor_id' => 13, 'score' => 64.25],
];

function makeService(?array $profile, array $matches, int $openCritical = 0, int $max = 3): array
{
    $repo               = new FakeRequestRepository();
    $repo->profile      = $profile;
    $repo->openCritical = $openCritical;
    $repo->max          = $max;
    $matching           = new FakeMatching();
    $matching->result   = $matches;

    return [new RequestService($repo, $matching), $repo, $matching];
}

[$service, $repo, $matching] = makeService($hospital, $matches);
$result = $service->raise(5, $input, '10.0.0.7');
check('a valid request returns the new request id', $result['request_id'] === 77);
check('it reports how many donors were notified', $result['donors_notified'] === 3);
check('the matching engine is asked with the hospital\'s city and the urgency',
    $matching->calls === [['O+', 2, 'critical']]);
check('all matches are handed to the repository', $repo->saved['matches'] === $matches);
check('units are saved as a number', $repo->saved['request']['units_needed'] === 2);
check('required_by is saved in MySQL format', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $repo->saved['request']['required_by']) === 1);
check('the client IP is passed on for the audit log', $repo->saved['ip'] === '10.0.0.7');
check('the hospital id is the logged-in hospital', $repo->saved['hospitalId'] === 5);

[$service, $repo] = makeService($hospital, $matches);
$service->raise(5, array_merge($input, ['ward_notes' => '']), '10.0.0.7');
check('empty notes are saved as NULL', $repo->saved['request']['ward_notes'] === null);

[$service, $repo] = makeService($hospital, []);
$result = $service->raise(5, $input, '10.0.0.7');
check('a request with no eligible donors is still created (0 notified)', $result['donors_notified'] === 0 && $repo->saved !== null);

// Who may raise a request
foreach ([
    'a pending hospital'      => array_merge($hospital, ['verification_status' => 'pending']),
    'a rejected hospital'     => array_merge($hospital, ['verification_status' => 'rejected']),
    'a blocked hospital'      => array_merge($hospital, ['account_status' => 'blocked']),
    'a user with no hospital' => null,
] as $label => $profile) {
    [$service, $repo, $matching] = makeService($profile, $matches);
    try {
        $service->raise(5, $input, '10.0.0.7');
        check("$label is refused", false);
    } catch (ForbiddenException $e) {
        check("$label is refused (403) and nothing is saved", $repo->saved === null && $matching->calls === []);
    }
}

// Invalid input
[$service, $repo, $matching] = makeService($hospital, $matches);
try {
    $service->raise(5, array_merge($input, ['units_needed' => '99', 'blood_group' => 'Z']), '10.0.0.7');
    check('invalid input is rejected', false);
} catch (ValidationException $e) {
    check('invalid input is rejected with the field errors', isset($e->getErrors()['units_needed'], $e->getErrors()['blood_group']));
    check('nothing is matched or saved for invalid input', $repo->saved === null && $matching->calls === []);
}

// Anti-misuse: max 3 open critical requests
[$service, $repo, $matching] = makeService($hospital, $matches, 3, 3);
try {
    $service->raise(5, $input, '10.0.0.7');
    check('a 4th open critical request is refused', false);
} catch (ValidationException $e) {
    check('a 4th open critical request is refused', isset($e->getErrors()['urgency']));
    check('nothing is matched or saved when the limit is reached', $repo->saved === null && $matching->calls === []);
}
[$service, $repo] = makeService($hospital, $matches, 2, 3);
$service->raise(5, $input, '10.0.0.7');
check('a 3rd open critical request is allowed', $repo->saved !== null);
[$service, $repo] = makeService($hospital, $matches, 3, 3);
$service->raise(5, array_merge($input, ['urgency' => 'urgent']), '10.0.0.7');
check('an urgent request is allowed even at the critical limit', $repo->saved !== null);
[$service, $repo] = makeService($hospital, $matches, 5, 5);
try {
    $service->raise(5, $input, '10.0.0.7');
    check('the limit follows the max_open_critical setting', false);
} catch (ValidationException $e) {
    check('the limit follows the max_open_critical setting', strpos($e->getErrors()['urgency'], '5') !== false);
}

// Notification text
$required = new DateTimeImmutable('2026-11-13 18:30:00');
$service  = new RequestService(new FakeRequestRepository(), new FakeMatching());
$text     = $service->notificationMessage($hospital, $input, $required);
check('notification names the group, units, hospital and city',
    strpos($text, 'O+') !== false && strpos($text, '2 unit(s)') !== false
    && strpos($text, 'City General Hospital') !== false && strpos($text, 'Hyderabad') !== false);
check('notification shows the deadline', strpos($text, '13 Nov 2026, 6:30 PM') !== false);
check('notification contains no phone number', preg_match('/03\d{2}-?\d{7}/', $text) !== 1);
$long = $service->notificationMessage(
    array_merge($hospital, ['hospital_name' => str_repeat('H', 150), 'city_name' => str_repeat('C', 80)]),
    $input,
    $required
);
check('a very long notification is cut to 255 characters (the column size)', mb_strlen($long) <= 255);

// ---------------------------------------------------------------- 3
echo PHP_EOL, '3. With the real database (full seed data needed)', PHP_EOL;

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
    $repo       = new RequestRepository();
    $service    = new RequestService();
    $created    = [];
    $notifyFrom = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn();
    $auditFrom  = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
    $realWhen   = (new DateTimeImmutable('now'))->modify('+3 days')->format('Y-m-d\TH:i');

    try {
        // A normal request: one request, matches, notifications and audit rows.
        $result = $service->raise($hospitalId, [
            'blood_group' => 'O+', 'units_needed' => '1', 'urgency' => 'normal',
            'required_by' => $realWhen, 'ward_notes' => 'test request',
        ], '127.0.0.1');
        $created[] = $id = $result['request_id'];

        $row = $repo->findForHospital($id, $hospitalId);
        check('the request is saved as open for this hospital', $row !== null && $row->isOpen() && $row->unitsNeeded === 1);
        check('another hospital cannot open this request', $repo->findForHospital($id, $hospitalId + 100000) === null);

        $saved = $repo->matchesFor($id);
        check('at least one donor was matched', $result['donors_notified'] > 0);
        check('one request_matches row per notified donor', count($saved) === $result['donors_notified']);
        check('every match starts as "notified"', array_unique(array_column($saved, 'status')) === ['notified']);
        check('matches are sorted from best to worst score', array_column($saved, 'score') === (function (array $s): array {
            rsort($s);
            return $s;
        })(array_column($saved, 'score')));
        check('only compatible blood groups were matched (O+ can receive O+ and O-)',
            array_diff(array_column($saved, 'blood_group'), ['O+', 'O-']) === []);
        check('the hospital view shows no phone number before a donor accepts', $saved === [] || ($saved[0]['status'] === 'notified' && $saved[0]['phone'] === null));

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE id > ? AND message LIKE 'Normal: 1 unit(s) of O+ blood%'");
        $stmt->execute([$notifyFrom]);
        check('every matched donor received one notification', (int) $stmt->fetchColumn() === $result['donors_notified']);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND entity_id = ? AND action IN ('request.created', 'request.matched')");
        $stmt->execute([$auditFrom, $id]);
        check('the creation and the matching are in the audit log', (int) $stmt->fetchColumn() === 2);

        // The critical limit, with real counts.
        $max = $repo->maxOpenCritical();
        for ($open = $repo->openCriticalCount($hospitalId); $open < $max; $open++) {
            $r = $service->raise($hospitalId, [
                'blood_group' => 'A+', 'units_needed' => '1', 'urgency' => 'critical',
                'required_by' => $realWhen, 'ward_notes' => '',
            ], '127.0.0.1');
            $created[] = $r['request_id'];
        }
        check("the hospital is now at its limit of $max open critical requests", $repo->openCriticalCount($hospitalId) >= $max);

        $countRequests = static function () use ($pdo): int {
            return (int) $pdo->query('SELECT COUNT(*) FROM blood_requests')->fetchColumn();
        };
        $countNotices = static function () use ($pdo): int {
            return (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
        };
        $countAudit = static function () use ($pdo): int {
            return (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        };

        $before = [$countRequests(), $countNotices(), $countAudit()];
        try {
            $service->raise($hospitalId, [
                'blood_group' => 'A+', 'units_needed' => '1', 'urgency' => 'critical',
                'required_by' => $realWhen, 'ward_notes' => '',
            ], '127.0.0.1');
            check('one more critical request is refused by the service', false);
        } catch (ValidationException $e) {
            check('one more critical request is refused by the service', isset($e->getErrors()['urgency']));
        }

        // The repository re-checks the limit inside its transaction. Calling it directly
        // (as if the service check had been beaten by a second browser tab) must roll back everything.
        $anyDonor = (int) $pdo->query('SELECT user_id FROM donors ORDER BY user_id LIMIT 1')->fetchColumn();
        try {
            $repo->createWithMatches(
                $hospitalId,
                ['blood_group' => 'A+', 'units_needed' => 1, 'urgency' => 'critical',
                 'required_by' => (new DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s'), 'ward_notes' => null],
                [['donor_id' => $anyDonor, 'score' => 50]],
                'rollback test',
                $max,
                '127.0.0.1'
            );
            check('the repository refuses a request over the limit', false);
        } catch (ValidationException $e) {
            check('the repository refuses a request over the limit', true);
        }
        check('the refused request left nothing behind (rolled back)', $before === [$countRequests(), $countNotices(), $countAudit()]);
    } finally {
        // Remove everything this test created (matches go with their request: ON DELETE CASCADE).
        if ($created !== []) {
            $marks = implode(',', array_fill(0, count($created), '?'));
            $pdo->prepare("DELETE FROM blood_requests WHERE id IN ($marks)")->execute($created);
        }
        $pdo->prepare("DELETE FROM notifications WHERE id > ? AND message LIKE '%unit(s) of%'")->execute([$notifyFrom]);
        $pdo->prepare("DELETE FROM audit_logs WHERE id > ? AND action IN ('request.created', 'request.matched')")->execute([$auditFrom]);
    }
}

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
