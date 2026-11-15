<?php
/**
 * JSON endpoint for the admin's live donor search.
 * GET /DonorNexa/api/donors-search.php?q=ali&blood_group=O%2B&city_id=2&eligible=1&sort=name&dir=asc&page=1
 *   -> {"rows":[...],"total":12,"page":1,"pages":2,"per_page":10}
 *
 * Admin only (401 for guests, 403 for other roles). No phone, CNIC or email is ever returned.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Logger;
use DonorNexa\Core\Session;
use DonorNexa\Repositories\DonorSearchRepository;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = Session::user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in.']);
    exit;
}
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Administrators only.']);
    exit;
}

try {
    echo json_encode((new DonorSearchRepository())->run($_GET));
} catch (Throwable $e) {
    Logger::exception($e);
    http_response_code(500);
    echo json_encode(['error' => 'The search is temporarily unavailable.']);
}
