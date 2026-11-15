<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\ForbiddenException;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Services\ListQuery;
use DonorNexa\Services\RequestValidator;
use Throwable;

/**
 * All SQL for blood requests raised by hospitals: creating a request together
 * with its matches and notifications, and reading a hospital's own requests.
 * Prepared statements only; user input is never put into the SQL text.
 *
 * Donor phone numbers are NOT selected anywhere in this class. A hospital may
 * only see a phone after the donor accepts, and that reveal (with its audit
 * entry) belongs to the accept/decline feature.
 */
class RequestRepository
{
    // ------------------------------------------------------------- reading

    /** The hospital behind a login (with its city name), or null when the user is not a hospital. */
    public function hospitalProfile(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT h.user_id, h.hospital_name, h.city_id, h.verification_status, h.trust_score,
                    c.city_name, u.status AS account_status
               FROM hospitals h
               JOIN users u  ON u.id = h.user_id
               JOIN cities c ON c.id = h.city_id
              WHERE h.user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return ($row === false) ? null : $row;
    }

    /** Setting max_open_critical: how many open critical requests one hospital may have (default 3). */
    public function maxOpenCritical(): int
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute(['max_open_critical']);
        $value = $stmt->fetchColumn();

        return ($value === false) ? 3 : max(1, (int) $value);
    }

    /**
     * Open critical requests of a hospital. A request whose deadline has
     * passed no longer counts, even if the clean-up job has not marked it
     * 'expired' yet.
     */
    public function openCriticalCount(int $hospitalId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM blood_requests
              WHERE hospital_id = ? AND urgency = 'critical'
                AND status = 'open' AND required_by > NOW()"
        );
        $stmt->execute([$hospitalId]);

        return (int) $stmt->fetchColumn();
    }

    /** How many of the hospital's requests match the search text and status filter. */
    public function countForHospital(int $hospitalId, ListQuery $list, string $status): int
    {
        [$where, $params] = $this->listWhere($hospitalId, $list, $status);

        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM blood_requests r WHERE $where");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** One page of the hospital's requests, sorted as the ListQuery says (whitelisted column). */
    public function listForHospital(int $hospitalId, ListQuery $list, string $status): array
    {
        [$where, $params] = $this->listWhere($hospitalId, $list, $status);

        // orderBy() only returns a whitelisted column; LIMIT/OFFSET are integers
        // made by the helper (emulated prepares are off, so they cannot be bound).
        $sql = sprintf(
            "SELECT r.id, r.blood_group, r.units_needed, r.units_fulfilled, r.urgency,
                    r.required_by, r.status, r.created_at,
                    (r.status = 'open' AND r.required_by <= NOW()) AS past_deadline,
                    (SELECT COUNT(*) FROM request_matches m WHERE m.request_id = r.id) AS donors_matched
               FROM blood_requests r
              WHERE %s
           ORDER BY %s, r.id DESC
              LIMIT %d OFFSET %d",
            $where,
            $list->orderBy(),
            $list->perPage(),
            $list->offset()
        );

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * One request, but only if it belongs to this hospital (a hospital can
     * never open another hospital's request by changing the number in the URL).
     */
    public function findForHospital(int $requestId, int $hospitalId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT r.id, r.blood_group, r.units_needed, r.units_fulfilled, r.urgency,
                    r.required_by, r.ward_notes, r.status, r.created_at,
                    (r.status = 'open' AND r.required_by <= NOW()) AS past_deadline
               FROM blood_requests r
              WHERE r.id = ? AND r.hospital_id = ?"
        );
        $stmt->execute([$requestId, $hospitalId]);
        $row = $stmt->fetch();

        return ($row === false) ? null : $row;
    }

    /**
     * Donors matched to a request, best score first. Name, blood group, city
     * and the answer status. The PHONE is selected only for donors who accepted
     * (or already donated); for everyone else it is NULL, so it can never reach
     * the page before the donor says yes (CEP privacy rule).
     */
    public function matchesFor(int $requestId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT m.id, m.score, m.status, m.decline_reason, m.notified_at, m.responded_at,
                    u.name AS donor_name, d.blood_group, c.city_name,
                    CASE WHEN m.status IN ('accepted', 'donated') THEN d.phone ELSE NULL END AS phone
               FROM request_matches m
               JOIN donors d ON d.user_id = m.donor_id
               JOIN users u  ON u.id = d.user_id
               JOIN cities c ON c.id = d.city_id
              WHERE m.request_id = ?
           ORDER BY m.score DESC, m.id ASC"
        );
        $stmt->execute([$requestId]);

        return $stmt->fetchAll();
    }

    /**
     * Write the "phone number revealed to the hospital" event to the audit log
     * (CEP privacy rule). It is written the FIRST time a hospital sees an
     * accepted donor's phone; opening the page again does not repeat it.
     *
     * @param array[] $matches the rows from matchesFor()
     */
    public function recordPhoneReveals(int $hospitalId, array $matches, string $ip): void
    {
        $pdo   = Database::connection();
        $seen  = $pdo->prepare("SELECT 1 FROM audit_logs WHERE action = 'donor.phone_revealed' AND entity = 'request_matches' AND entity_id = ? LIMIT 1");
        $audit = new AuditRepository();

        foreach ($matches as $match) {
            if ($match['phone'] === null) {
                continue;
            }
            $seen->execute([(int) $match['id']]);
            if ($seen->fetchColumn() === false) {
                $audit->log($hospitalId, 'donor.phone_revealed', 'request_matches', (int) $match['id'], null, 'phone shown to hospital after acceptance', $ip);
            }
        }
    }

    // ------------------------------------------------------------- writing

    /**
     * Save a request, its matches and the donors' notifications in ONE
     * transaction: either everything is saved or nothing is.
     *
     * Steps inside the transaction:
     *  1. lock the hospital's row (SELECT ... FOR UPDATE). Two requests raised
     *     at the same moment now queue up here, so the "max open critical"
     *     rule cannot be beaten by double-clicking or by two browser tabs;
     *  2. re-check that limit for a critical request;
     *  3. insert the request, then one request_matches row (status 'notified')
     *     and one notifications row per matched donor;
     *  4. write the audit log entries.
     *
     * @param array   $request   blood_group, units_needed, urgency, required_by ('Y-m-d H:i:s'), ward_notes (string or null)
     * @param array[] $matches   from MatchingService::findMatches(): each has donor_id and score
     * @param string  $message   text of the notification every matched donor receives
     * @return int the new request id
     * @throws ForbiddenException  the hospital is not verified (any more)
     * @throws ValidationException the hospital is at its critical limit
     */
    public function createWithMatches(
        int $hospitalId,
        array $request,
        array $matches,
        string $message,
        int $maxOpenCritical,
        string $ip
    ): int {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare(
                "SELECT user_id FROM hospitals
                  WHERE user_id = ? AND verification_status = 'verified'
                  FOR UPDATE"
            );
            $lock->execute([$hospitalId]);
            if ($lock->fetchColumn() === false) {
                throw new ForbiddenException('Your hospital must be verified by an administrator before you can raise blood requests.');
            }

            // Counted AFTER the lock is held, so a request committed by a
            // competing transaction is always visible here.
            if ($request['urgency'] === 'critical' && $this->openCriticalCount($hospitalId) >= $maxOpenCritical) {
                throw new ValidationException(['urgency' => RequestValidator::criticalLimitMessage($maxOpenCritical)]);
            }

            $pdo->prepare(
                'INSERT INTO blood_requests (hospital_id, blood_group, units_needed, urgency, required_by, ward_notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $hospitalId,
                $request['blood_group'],
                (int) $request['units_needed'],
                $request['urgency'],
                $request['required_by'],
                $request['ward_notes'],
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $insertMatch  = $pdo->prepare(
                "INSERT INTO request_matches (request_id, donor_id, score, status) VALUES (?, ?, ?, 'notified')"
            );
            $insertNotice = $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)');
            foreach ($matches as $match) {
                $insertMatch->execute([$requestId, (int) $match['donor_id'], $match['score']]);
                $insertNotice->execute([(int) $match['donor_id'], $message]);
            }

            $audit = new AuditRepository();
            $audit->log(
                $hospitalId,
                'request.created',
                'blood_requests',
                $requestId,
                null,
                sprintf('open: %d x %s, %s', (int) $request['units_needed'], $request['blood_group'], $request['urgency']),
                $ip
            );
            $audit->log(
                $hospitalId,
                'request.matched',
                'blood_requests',
                $requestId,
                null,
                count($matches) . ' donor(s) notified',
                $ip
            );

            $pdo->commit();

            return $requestId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();   // nothing is saved: no request, no matches, no notifications, no audit rows
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------- helpers

    /** @return array{0:string,1:array} the WHERE text (with ? marks) and its parameters */
    private function listWhere(int $hospitalId, ListQuery $list, string $status): array
    {
        $where  = ['r.hospital_id = ?'];
        $params = [$hospitalId];

        if ($status !== '') {
            $where[]  = 'r.status = ?';
            $params[] = $status;
        }

        if ($list->search() !== '') {
            $where[] = '(r.blood_group LIKE ? OR r.ward_notes LIKE ?)';
            $like    = $list->likeTerm();
            array_push($params, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }
}
