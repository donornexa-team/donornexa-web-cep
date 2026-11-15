<?php
declare(strict_types=1);

namespace DonorNexa\Repositories;

use DonorNexa\Core\Database;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Exceptions\ValidationException;
use Throwable;

/**
 * SQL of the HOSPITAL side of a request's life after matching: marking a
 * donation as completed, and cancelling or closing the request.
 * Every method is ONE transaction that starts by locking the request row.
 */
class DonationRepository
{
    /**
     * The hospital confirms that an accepted donor has donated.
     *
     *  - inserts the donation (the database TRIGGER trg_donation_after_insert
     *    moves the donor's last_donation_date forward, which starts the cool-down);
     *  - marks the match "donated" and adds one unit to units_fulfilled; when all
     *    units are in, the request becomes "fulfilled";
     *  - adds the unit to the hospital's blood inventory;
     *  - writes the audit rows.
     *
     * @throws NotFoundException   the request or match is not this hospital's
     * @throws ValidationException the request is not open, or the donor has not accepted
     */
    public function markDonated(int $hospitalId, int $requestId, int $matchId, string $ip): void
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $request = $this->lockOwnRequest($pdo, $hospitalId, $requestId);
            if ($request['status'] !== 'open') {
                throw new ValidationException(['request' => 'Donations can only be recorded on an open request.']);
            }

            $stmt = $pdo->prepare('SELECT id, status FROM request_matches WHERE id = ? AND request_id = ? FOR UPDATE');
            $stmt->execute([$matchId, $requestId]);
            $match = $stmt->fetch();
            if ($match === false) {
                throw new NotFoundException('That donor was not found for this request.');
            }
            if ($match['status'] !== 'accepted') {
                throw new ValidationException(['request' => 'Only a donor who accepted the request can be marked as donated.']);
            }

            $pdo->prepare('INSERT INTO donations (match_id, units, donated_on, verified_by) VALUES (?, 1, CURDATE(), ?)')
                ->execute([$matchId, $hospitalId]);
            $donationId = (int) $pdo->lastInsertId();

            $pdo->prepare("UPDATE request_matches SET status = 'donated' WHERE id = ?")->execute([$matchId]);

            $fulfilled = (int) $request['units_fulfilled'] + 1;
            $newStatus = ($fulfilled >= (int) $request['units_needed']) ? 'fulfilled' : 'open';
            $pdo->prepare('UPDATE blood_requests SET units_fulfilled = ?, status = ? WHERE id = ?')
                ->execute([$fulfilled, $newStatus, $requestId]);

            // Same transaction, so the stock increase is undone if anything below fails.
            (new InventoryRepository())->addUnits($hospitalId, $request['blood_group'], 1);

            $audit = new AuditRepository();
            $audit->log($hospitalId, 'donation.completed', 'donations', $donationId, null, '1 unit ' . $request['blood_group'] . ' (match ' . $matchId . ')', $ip);
            $audit->log($hospitalId, 'match.donated', 'request_matches', $matchId, 'accepted', 'donated', $ip);
            if ($newStatus === 'fulfilled') {
                $audit->log($hospitalId, 'request.fulfilled', 'blood_requests', $requestId, 'open', 'fulfilled', $ip);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The hospital cancels its open request. Anti-misuse rule: every
     * cancellation lowers the hospital's trust score by the setting
     * trust_penalty_cancel (never below 0). Donors who were notified or had
     * accepted get a notification.
     */
    public function cancel(int $hospitalId, int $requestId, string $ip): void
    {
        $this->finish($hospitalId, $requestId, 'cancelled', true, $ip);
    }

    /** The hospital closes its open request (for example the blood was found elsewhere): no penalty. */
    public function close(int $hospitalId, int $requestId, string $ip): void
    {
        $this->finish($hospitalId, $requestId, 'closed', false, $ip);
    }

    // ------------------------------------------------------------- helpers

    private function finish(int $hospitalId, int $requestId, string $newStatus, bool $penalty, string $ip): void
    {
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $request = $this->lockOwnRequest($pdo, $hospitalId, $requestId);
            if ($request['status'] !== 'open') {
                throw new ValidationException(['request' => 'Only an open request can be ' . $newStatus . '.']);
            }

            $pdo->prepare('UPDATE blood_requests SET status = ? WHERE id = ?')->execute([$newStatus, $requestId]);

            $audit = new AuditRepository();
            $audit->log($hospitalId, 'request.' . $newStatus, 'blood_requests', $requestId, 'open', $newStatus, $ip);

            if ($penalty) {
                $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'trust_penalty_cancel'");
                $stmt->execute();
                $value   = $stmt->fetchColumn();
                $penalty = ($value === false) ? 5 : max(0, (int) $value);

                $stmt = $pdo->prepare('SELECT trust_score FROM hospitals WHERE user_id = ? FOR UPDATE');
                $stmt->execute([$hospitalId]);
                $old = (int) $stmt->fetchColumn();
                $new = max(0, $old - $penalty);   // computed here: the column is unsigned

                if ($new !== $old) {
                    $pdo->prepare('UPDATE hospitals SET trust_score = ? WHERE user_id = ?')->execute([$new, $hospitalId]);
                    $audit->log($hospitalId, 'hospital.trust_score', 'hospitals', $hospitalId, (string) $old, (string) $new, $ip);
                }
            }

            $pdo->prepare(
                "INSERT INTO notifications (user_id, message)
                 SELECT m.donor_id, ?
                   FROM request_matches m
                  WHERE m.request_id = ? AND m.status IN ('notified', 'accepted')"
            )->execute([
                sprintf('Request #%d (%s) was %s by the hospital. You do not need to donate for it.', $requestId, $request['blood_group'], $newStatus),
                $requestId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Lock a request, but only if it belongs to this hospital (otherwise "not found"). */
    private function lockOwnRequest(\PDO $pdo, int $hospitalId, int $requestId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, blood_group, units_needed, units_fulfilled, status
               FROM blood_requests
              WHERE id = ? AND hospital_id = ?
                FOR UPDATE'
        );
        $stmt->execute([$requestId, $hospitalId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException('That blood request was not found.');
        }

        return $row;
    }
}
