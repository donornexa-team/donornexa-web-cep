<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Core\Session;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\MatchRepository;
use DonorNexa\Services\BadgeService;
use DonorNexa\Services\ExpiryService;
use DonorNexa\Services\ListQuery;
use DonorNexa\Services\MatchResponseService;

/**
 * The donor's side of blood requests: the inbox of requests matched to the donor,
 * accept / decline, and the donation history with badges.
 * Every action starts with requireRole('donor') and only ever uses the
 * logged-in donor's own id, so one donor can never answer for another.
 */
class DonorRequestController extends BaseController
{
    use ReadsPostData;

    private const STATUSES = ['notified', 'accepted', 'declined', 'no_response', 'donated'];

    /** GET /donor/requests */
    public function index(array $params = []): void
    {
        $user    = $this->requireRole('donor');
        $matches = new MatchRepository();

        (new ExpiryService())->sweep();   // expired requests and unanswered notifications first

        $list = new ListQuery($_GET, '/donor/requests', [
            'received'  => 'm.notified_at',
            'needed_by' => 'r.required_by',
            'blood'     => 'r.blood_group',
            'urgency'   => "FIELD(r.urgency, 'critical', 'urgent', 'normal')",
            'status'    => 'm.status',
        ], 'received', 'desc', 10);

        $status = $list->filter('status', self::STATUSES);
        $list->applyTotal($matches->countInbox((int) $user['id'], $list, $status));

        $rows = $matches->inbox((int) $user['id'], $list, $status);
        $matches->markNotificationsRead((int) $user['id']);

        $this->render('donor/requests', [
            'title'    => 'Requests for Me',
            'list'     => $list,
            'rows'     => $rows,
            'statuses' => self::STATUSES,
            'status'   => $status,
        ]);
    }

    /** POST /donor/requests/{id}/accept */
    public function accept(array $params = []): void
    {
        $user      = $this->requireRole('donor');
        $requestId = $this->requestId($params);

        try {
            (new MatchResponseService())->accept((int) $user['id'], $requestId, $this->ip());
            Session::flash('success', 'Thank you! You accepted request #' . $requestId . '. The hospital can now see your contact details and will get in touch.');
        } catch (ValidationException $e) {
            Session::flash('error', (string) reset($e->getErrors()));
        }

        $this->redirect('/donor/requests');
    }

    /** POST /donor/requests/{id}/decline */
    public function decline(array $params = []): void
    {
        $user      = $this->requireRole('donor');
        $requestId = $this->requestId($params);

        try {
            (new MatchResponseService())->decline((int) $user['id'], $requestId, $this->postString('reason'), $this->ip());
            Session::flash('info', 'You declined request #' . $requestId . '. Thank you for answering.');
        } catch (ValidationException $e) {
            Session::flash('error', (string) reset($e->getErrors()));
        }

        $this->redirect('/donor/requests');
    }

    /** GET /donor/history : completed donations and badges. */
    public function history(array $params = []): void
    {
        $user      = $this->requireRole('donor');
        $donations = (new MatchRepository())->historyFor((int) $user['id']);
        $badges    = new BadgeService();

        $this->render('donor/history', [
            'title'     => 'Donation History',
            'donations' => $donations,
            'count'     => count($donations),
            'badges'    => $badges->forCount(count($donations)),
            'untilNext' => $badges->untilNext(count($donations)),
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function requestId(array $params): int
    {
        $id = (string) ($params['id'] ?? '');
        if (!ctype_digit($id)) {
            throw new NotFoundException('That blood request was not found.');
        }

        return (int) $id;
    }

    private function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
