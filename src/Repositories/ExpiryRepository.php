<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use Throwable;

/**
 * SQL of the auto-expiry rule (see ExpiryService): requests past their
 * required-by time become "expired", and donor notifications nobody answered
 * become "no_response". Every change is written to the audit log.
 */
class ExpiryRepository
{
    /**
     * @param int $noResponseHours hours after which an unanswered notification counts as "no response"
     * @return array{requests_expired:int, matches_no_response:int}
     */
    public function sweep(int $noResponseHours): array
    {
        $pdo   = Database::connection();
        $audit = new AuditRepository();

        try {
            $pdo->beginTransaction();

            // 1. open requests whose deadline has passed
            $ids = $pdo->query("SELECT id FROM blood_requests WHERE status = 'open' AND required_by <= NOW() FOR UPDATE")
                ->fetchAll(\PDO::FETCH_COLUMN);
            $expire = $pdo->prepare("UPDATE blood_requests SET status = 'expired' WHERE id = ? AND status = 'open'");
            foreach ($ids as $id) {
                $expire->execute([(int) $id]);
                $audit->log(null, 'request.expired', 'blood_requests', (int) $id, 'open', 'expired', 'system');
            }

            // 2. notifications nobody answered: the request is no longer open, or the waiting time is over
            $stmt = $pdo->prepare(
                "SELECT m.id
                   FROM request_matches m
                   JOIN blood_requests r ON r.id = m.request_id
                  WHERE m.status = 'notified'
                    AND (r.status <> 'open' OR m.notified_at <= (NOW() - INTERVAL " . max(1, $noResponseHours) . " HOUR))
                    FOR UPDATE"
            );
            $stmt->execute();
            $matchIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $mark = $pdo->prepare("UPDATE request_matches SET status = 'no_response' WHERE id = ? AND status = 'notified'");
            foreach ($matchIds as $id) {
                $mark->execute([(int) $id]);
                $audit->log(null, 'match.no_response', 'request_matches', (int) $id, 'notified', 'no_response', 'system');
            }

            $pdo->commit();

            return ['requests_expired' => count($ids), 'matches_no_response' => count($matchIds)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
