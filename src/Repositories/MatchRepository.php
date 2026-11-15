<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Services\ListQuery;
use Throwable;

/**
 * SQL of the DONOR side of a blood request: the inbox of matched requests, the
 * accept / decline answers, and the donation history.
 *
 * accept() is the "concurrency safety" rule of the CEP: when several donors
 * press Accept at the same moment, the number of accepted donors must never go
 * above the units the hospital asked for.
 */
class MatchRepository
{
    // ------------------------------------------------------------- reading

    /** How many of the donor's matched requests fit the search text and status filter. */
    public function countInbox(int $donorId, ListQuery $list, string $status): int
    {
        [$where, $params] = $this->inboxWhere($donorId, $list, $status);

        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*)
               FROM request_matches m
               JOIN blood_requests r ON r.id = m.request_id
               JOIN hospitals h      ON h.user_id = r.hospital_id
              WHERE $where"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of the donor's matched requests. Shows the hospital name and
     * city, never the hospital's or a patient's phone. "committed" is the number
     * of donors who already accepted, so a full request can be shown as full.
     */
    public function inbox(int $donorId, ListQuery $list, string $status): array
    {
        [$where, $params] = $this->inboxWhere($donorId, $list, $status);

        // orderBy() only returns a whitelisted column; LIMIT/OFFSET are integers from the helper.
        $sql = sprintf(
            "SELECT m.id AS match_id, m.request_id, m.status AS match_status, m.decline_reason,
                    m.notified_at, m.responded_at,
                    r.blood_group, r.units_needed, r.units_fulfilled, r.urgency, r.required_by,
                    r.ward_notes, r.status AS request_status,
                    (r.required_by <= NOW()) AS past_deadline,
                    h.hospital_name, c.city_name,
                    (SELECT COUNT(*) FROM request_matches x
                      WHERE x.request_id = r.id AND x.status IN ('accepted', 'donated')) AS committed
               FROM request_matches m
               JOIN blood_requests r ON r.id = m.request_id
               JOIN hospitals h      ON h.user_id = r.hospital_id
               JOIN cities c         ON c.id = h.city_id
              WHERE %s
           ORDER BY %s, m.id DESC
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

    /** The donor opened the inbox: their notifications count as read. */
    public function markNotificationsRead(int $donorId): void
    {
        Database::connection()
            ->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
            ->execute([$donorId]);
    }

    /** Completed donations of this donor, newest first. */
    public function historyFor(int $donorId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT dn.id, dn.donated_on, dn.units, r.blood_group, h.hospital_name, c.city_name
               FROM donations dn
               JOIN request_matches m ON m.id = dn.match_id
               JOIN blood_requests r  ON r.id = m.request_id
               JOIN hospitals h       ON h.user_id = r.hospital_id
               JOIN cities c          ON c.id = h.city_id
              WHERE m.donor_id = ?
           ORDER BY dn.donated_on DESC, dn.id DESC
              LIMIT 200'
        );
        $stmt->execute([$donorId]);

        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------- writing

    /**
     * The donor accepts a request. ONE transaction:
     *
     *  1. lock the request row (SELECT ... FOR UPDATE). Donors who accept at the
     *     same moment queue up here, one after the other;
     *  2. lock the donor's own match row and check it is still "notified";
     *  3. check the request is still open, the donor is still eligible, and that
     *     fewer donors than units_needed have accepted so far. The count is read
     *     AFTER the lock is held, so an acceptance committed by the donor before
     *     us is always seen: units can never be over-allocated;
     *  4. mark the match "accepted", write the audit row and notify the hospital.
     *
     * Any failure rolls everything back.
     *
     * @throws NotFoundException   the request does not exist or was never matched to this donor
     * @throws ValidationException a rule above is not met (the message says which)
     */
    public function accept(int $donorId, int $requestId, string $ip): void
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $request = $this->lockRequest($pdo, $requestId);
            $match   = $this->lockMatch($pdo, $requestId, $donorId);

            if ($match['status'] !== 'notified') {
                throw new ValidationException(['response' => 'You have already answered this request.']);
            }
            if ($request['status'] !== 'open' || (int) $request['past_deadline'] === 1) {
                throw new ValidationException(['response' => 'This request is no longer open.']);
            }

            $eligible = $pdo->prepare('SELECT 1 FROM v_eligible_donors WHERE user_id = ?');
            $eligible->execute([$donorId]);
            if ($eligible->fetchColumn() === false) {
                throw new ValidationException(['response' => 'You cannot accept right now: your profile does not meet the donation rules (availability, weight, age or the waiting time since your last donation).']);
            }

            $count = $pdo->prepare("SELECT COUNT(*) FROM request_matches WHERE request_id = ? AND status IN ('accepted', 'donated')");
            $count->execute([$requestId]);
            if ((int) $count->fetchColumn() >= (int) $request['units_needed']) {
                throw new ValidationException(['response' => 'Enough donors have already accepted this request. Thank you for responding.']);
            }

            $pdo->prepare("UPDATE request_matches SET status = 'accepted', responded_at = NOW() WHERE id = ?")
                ->execute([$match['id']]);

            (new AuditRepository())->log($donorId, 'match.accepted', 'request_matches', (int) $match['id'], 'notified', 'accepted', $ip);

            $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)')->execute([
                (int) $request['hospital_id'],
                sprintf('A donor accepted your request #%d (%s). Open the request to see the contact details.', $requestId, $request['blood_group']),
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The donor declines a request, with an optional reason (already validated).
     *
     * @throws NotFoundException   the request does not exist or was never matched to this donor
     * @throws ValidationException already answered, or the request is no longer open
     */
    public function decline(int $donorId, int $requestId, string $reason, string $ip): void
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $request = $this->lockRequest($pdo, $requestId);
            $match   = $this->lockMatch($pdo, $requestId, $donorId);

            if ($match['status'] !== 'notified') {
                throw new ValidationException(['response' => 'You have already answered this request.']);
            }
            if ($request['status'] !== 'open' || (int) $request['past_deadline'] === 1) {
                throw new ValidationException(['response' => 'This request is no longer open.']);
            }

            $pdo->prepare("UPDATE request_matches SET status = 'declined', decline_reason = ?, responded_at = NOW() WHERE id = ?")
                ->execute([($reason === '') ? null : $reason, $match['id']]);

            (new AuditRepository())->log(
                $donorId,
                'match.declined',
                'request_matches',
                (int) $match['id'],
                'notified',
                ($reason === '') ? 'declined' : 'declined: ' . $reason,
                $ip
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------- helpers

    /** Lock one request row; its status and whether the deadline has passed. */
    private function lockRequest(\PDO $pdo, int $requestId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, hospital_id, blood_group, units_needed, status, (required_by <= NOW()) AS past_deadline
               FROM blood_requests
              WHERE id = ?
                FOR UPDATE'
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException('That blood request was not found.');
        }

        return $row;
    }

    /** Lock the donor's own match row. A request never matched to this donor answers "not found". */
    private function lockMatch(\PDO $pdo, int $requestId, int $donorId): array
    {
        $stmt = $pdo->prepare('SELECT id, status FROM request_matches WHERE request_id = ? AND donor_id = ? FOR UPDATE');
        $stmt->execute([$requestId, $donorId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException('That blood request was not found.');
        }

        return $row;
    }

    /** @return array{0:string,1:array} the WHERE text (with ? marks) and its parameters */
    private function inboxWhere(int $donorId, ListQuery $list, string $status): array
    {
        $where  = ['m.donor_id = ?'];
        $params = [$donorId];

        if ($status !== '') {
            $where[]  = 'm.status = ?';
            $params[] = $status;
        }

        if ($list->search() !== '') {
            $where[] = '(r.blood_group LIKE ? OR h.hospital_name LIKE ?)';
            $like    = $list->likeTerm();
            array_push($params, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }
}
