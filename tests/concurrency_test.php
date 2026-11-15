<?php
/**
 * Real concurrency test: several PHP processes try to accept the SAME 1-unit
 * request at the same moment. Exactly one must win. Run: php tests/concurrency_test.php
 * Needs the full seed data. Puts the database back as it was.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Database;
use DonorNexa\Services\MatchResponseService;
use DonorNexa\Services\RequestService;

// Worker mode: php concurrency_test.php worker <donorId> <requestId> <startAtMicrotime>
if (($argv[1] ?? '') === 'worker') {
    $donor = (int) $argv[2]; $request = (int) $argv[3]; $start = (float) $argv[4];
    Database::connection();
    while (microtime(true) < $start) { usleep(200); }
    try {
        (new MatchResponseService())->accept($donor, $request, '127.0.0.1');
        echo 'WIN';
    } catch (Throwable $e) {
        echo 'LOSE';
    }
    exit(0);
}

$pdo = Database::connection();
$hospitalId = (int) $pdo->query("SELECT user_id FROM hospitals WHERE verification_status = 'verified' ORDER BY user_id LIMIT 1")->fetchColumn();
if ($hospitalId === 0) { echo "SKIPPED  no verified hospital (import the seeds)\n"; exit(0); }

$auditFrom  = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn();
$notifyFrom = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM notifications')->fetchColumn();
$id = 0; $failed = 0;
try {
    $res = (new RequestService())->raise($hospitalId, [
        'blood_group' => 'O+', 'units_needed' => '1', 'urgency' => 'normal',
        'required_by' => (new DateTimeImmutable('+3 days'))->format('Y-m-d\TH:i'), 'ward_notes' => 'race',
    ], '127.0.0.1');
    $id = (int) $res['request_id'];
    $donors = $pdo->query("SELECT donor_id FROM request_matches WHERE request_id = $id ORDER BY score DESC")->fetchAll(PDO::FETCH_COLUMN);
    $donors = array_slice($donors, 0, 5);
    echo count($donors) . " donors racing for 1 unit\n";

    $start = microtime(true) + 1.0;
    $procs = [];
    foreach ($donors as $d) {
        $cmd = [PHP_BINARY, __FILE__, 'worker', (string) $d, (string) $id, sprintf('%.6f', $start)];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $wins = 0; $loses = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($p);
        if ($out === 'WIN') { $wins++; } elseif ($out === 'LOSE') { $loses++; }
    }
    $accepted = (int) $pdo->query("SELECT COUNT(*) FROM request_matches WHERE request_id = $id AND status = 'accepted'")->fetchColumn();
    foreach ([['exactly one worker won', $wins === 1], ['the others were refused', $loses === count($donors) - 1],
              ['the database holds exactly one accepted donor', $accepted === 1]] as [$n, $ok]) {
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $n . "\n";
        if (!$ok) { $failed++; }
    }
} finally {
    if ($id) { $pdo->exec("DELETE FROM blood_requests WHERE id = $id"); }
    $pdo->prepare('DELETE FROM audit_logs WHERE id > ?')->execute([$auditFrom]);
    $pdo->prepare('DELETE FROM notifications WHERE id > ?')->execute([$notifyFrom]);
}
echo $failed === 0 ? "ALL CHECKS PASSED\n" : "$failed CHECK(S) FAILED\n";
exit($failed === 0 ? 0 : 1);
