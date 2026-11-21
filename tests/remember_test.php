<?php
/**
 * Test script for the "keep me logged in" cookie. No database needed.
 * Run from the project folder: php tests/remember_test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\RememberMe;

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? '  PASS  ' : '  FAIL  '), $name, PHP_EOL;
    if (!$ok) { $failed++; }
}

$secret = str_repeat('k', 40);
$now    = 1_800_000_000;
$hash   = password_hash('Password@123', PASSWORD_DEFAULT);
$user   = ['id' => 7, 'name' => 'Test', 'role' => 'donor', 'status' => 'active', 'password_hash' => $hash];
$lookup = function (int $id) use (&$user): ?array { return $id === 7 ? $user : null; };
$token  = RememberMe::makeToken(7, $hash, $now + 86400, $secret);

check('a fresh cookie is accepted and returns the user', (RememberMe::verifyToken($token, $lookup, $secret, $now)['id'] ?? 0) === 7);
check('an expired cookie is refused', RememberMe::verifyToken(RememberMe::makeToken(7, $hash, $now - 1, $secret), $lookup, $secret, $now) === null);
check('an expiry date far in the future is refused', RememberMe::verifyToken(RememberMe::makeToken(7, $hash, $now + 90 * 86400, $secret), $lookup, $secret, $now) === null);

[$id, $exp, $sig] = explode('.', $token);
check('a changed user id is refused (same signature)', RememberMe::verifyToken('8.' . $exp . '.' . $sig, $lookup, $secret, $now) === null);
check('a changed expiry is refused', RememberMe::verifyToken($id . '.' . ($now + 2 * 86400) . '.' . $sig, $lookup, $secret, $now) === null);
check('a forged signature is refused', RememberMe::verifyToken($id . '.' . $exp . '.' . str_repeat('0', 64), $lookup, $secret, $now) === null);
check('a cookie signed with another key is refused', RememberMe::verifyToken($token, $lookup, str_repeat('x', 40), $now) === null);
foreach (['', 'abc', '7.123', '7.abc.def', '7..sig', '-7.1.2'] as $junk) {
    check('junk cookie "' . $junk . '" is refused', RememberMe::verifyToken($junk, $lookup, $secret, $now) === null);
}

$user['password_hash'] = password_hash('NewPassword@1', PASSWORD_DEFAULT);
check('changing the password invalidates old cookies', RememberMe::verifyToken($token, $lookup, $secret, $now) === null);
$user['password_hash'] = $hash;
$user['status'] = 'blocked';
check('a blocked user is not restored', RememberMe::verifyToken($token, $lookup, $secret, $now) === null);
check('an unknown user is not restored', RememberMe::verifyToken(RememberMe::makeToken(9, $hash, $now + 86400, $secret), $lookup, $secret, $now) === null);

echo PHP_EOL, ($failed === 0 ? 'ALL CHECKS PASSED' : "$failed CHECK(S) FAILED"), PHP_EOL;
exit($failed === 0 ? 0 : 1);
