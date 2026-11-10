<?php
/**
 * JSON endpoint for the landing page statistics.
 * GET /DonorNexa/api/stats.php -> {"active_requests":6,"registered_donors":30,"units_fulfilled":13}
 */
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use DonorNexa\Core\Logger;
use DonorNexa\Repositories\PublicRepository;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    echo json_encode((new PublicRepository())->stats());
} catch (Throwable $e) {
    Logger::exception($e);
    http_response_code(500);
    echo json_encode(['error' => 'Statistics are temporarily unavailable.']);
}
