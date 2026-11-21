<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Core\Session;
use DonorNexa\Exceptions\ForbiddenException;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\RequestRepository;
use DonorNexa\Services\ExpiryService;
use DonorNexa\Services\ListQuery;
use DonorNexa\Services\MatchingService;
use DonorNexa\Services\RequestLifecycleService;
use DonorNexa\Services\RequestService;

/**
 * Blood requests, hospital side: raise a request, list your requests, see one
 * request with its matched donors.
 *
 * Every action starts with requireRole('hospital'): guests are sent to the
 * login page, donors and admins get the 403 page. Business rules live in
 * RequestService and SQL in RequestRepository; this class only reads the form,
 * calls them and chooses the page to show.
 */
class RequestController extends BaseController
{
    use ReadsPostData;

    private const FIELDS   = ['blood_group', 'units_needed', 'urgency', 'required_by', 'ward_notes'];
    private const STATUSES = ['open', 'fulfilled', 'expired', 'cancelled', 'closed'];

    /** GET /hospital/requests : the hospital's own requests (search, filter, sort, pages). */
    public function index(array $params = []): void
    {
        $user     = $this->requireRole('hospital');
        $requests = new RequestRepository();

        (new ExpiryService())->sweep();   // mark expired requests before listing them

        $list = new ListQuery($_GET, '/hospital/requests', [
            'created'   => 'r.created_at',
            'needed_by' => 'r.required_by',
            'blood'     => 'r.blood_group',
            'units'     => 'r.units_needed',
            'urgency'   => "FIELD(r.urgency, 'critical', 'urgent', 'normal')",
            'status'    => 'r.status',
        ], 'created', 'desc', 10);

        $status = $list->filter('status', self::STATUSES);
        $list->applyTotal($requests->countForHospital((int) $user['id'], $list, $status));

        $this->render('hospital/requests', [
            'title'    => 'My Blood Requests',
            'list'     => $list,
            'rows'     => $requests->listForHospital((int) $user['id'], $list, $status),
            'statuses' => self::STATUSES,
            'status'   => $status,
        ]);
    }

    /** GET /hospital/requests/new : the form. */
    public function create(array $params = []): void
    {
        $user = $this->requireRole('hospital');

        $this->renderForm((int) $user['id'], [], []);
    }

    /** POST /hospital/requests : validate, match donors, notify them, then show the new request. */
    public function store(array $params = []): void
    {
        $user  = $this->requireRole('hospital');
        $input = $this->postFields(self::FIELDS);
        $ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        try {
            $result = (new RequestService())->raise((int) $user['id'], $input, $ip);
        } catch (ValidationException $e) {
            $this->renderForm((int) $user['id'], $input, $e->getErrors(), 422);
            return;
        }

        $id    = $result['request_id'];
        $count = $result['donors_notified'];

        if ($count > 0) {
            Session::flash('success', 'Request #' . $id . ' was created and ' . $count . ' matching donor(s) were notified.');
        } else {
            Session::flash('warning', 'Request #' . $id . ' was created, but no eligible donor matches right now. It is shown on the public emergencies board.');
        }

        $this->redirect('/hospital/requests/' . $id);
    }

    /** GET /hospital/requests/{id} : one request and its ranked donors. */
    public function show(array $params = []): void
    {
        $user = $this->requireRole('hospital');

        $id = (string) ($params['id'] ?? '');
        if (!ctype_digit($id)) {
            throw new NotFoundException('That blood request was not found.');
        }

        (new ExpiryService())->sweep();

        $requests = new RequestRepository();
        $request  = $requests->findForHospital((int) $id, (int) $user['id']);
        if ($request === null) {
            // Same answer for "does not exist" and "belongs to another hospital".
            throw new NotFoundException('That blood request was not found.');
        }

        $matches = $requests->matchesFor($request->id);
        // Accepted donors' phones are in $matches now: record that the hospital saw them.
        $requests->recordPhoneReveals((int) $user['id'], $matches, $this->ip());

        $this->render('hospital/request_show', [
            'title'   => 'Request #' . $request->id,
            'request' => $request,
            'matches' => $matches,
        ]);
    }

    /** POST /hospital/requests/{id}/matches/{match}/donated : the donor gave blood. */
    public function markDonated(array $params = []): void
    {
        $user = $this->requireRole('hospital');
        $id   = $this->number($params, 'id');
        $match = $this->number($params, 'match');

        try {
            (new RequestLifecycleService())->markDonated((int) $user['id'], $id, $match, $this->ip());
            Session::flash('success', 'Donation recorded. One unit was added to your inventory.');
        } catch (ValidationException $e) {
            Session::flash('error', (string) reset($e->getErrors()));
        }

        $this->redirect('/hospital/requests/' . $id);
    }

    /** POST /hospital/requests/{id}/cancel : cancelling lowers the hospital's trust score. */
    public function cancel(array $params = []): void
    {
        $user = $this->requireRole('hospital');
        $id   = $this->number($params, 'id');

        try {
            (new RequestLifecycleService())->cancel((int) $user['id'], $id, $this->ip());
            Session::flash('warning', 'Request #' . $id . ' was cancelled. Your hospital\'s trust score was lowered.');
        } catch (ValidationException $e) {
            Session::flash('error', (string) reset($e->getErrors()));
        }

        $this->redirect('/hospital/requests/' . $id);
    }

    /** POST /hospital/requests/{id}/close : no penalty. */
    public function close(array $params = []): void
    {
        $user = $this->requireRole('hospital');
        $id   = $this->number($params, 'id');

        try {
            (new RequestLifecycleService())->close((int) $user['id'], $id, $this->ip());
            Session::flash('success', 'Request #' . $id . ' was closed.');
        } catch (ValidationException $e) {
            Session::flash('error', (string) reset($e->getErrors()));
        }

        $this->redirect('/hospital/requests/' . $id);
    }

    // ---------------------------------------------------------------- helpers

    /** A numeric route parameter, or a 404 page. */
    private function number(array $params, string $name): int
    {
        $value = (string) ($params[$name] ?? '');
        if (!ctype_digit($value)) {
            throw new NotFoundException('That blood request was not found.');
        }

        return (int) $value;
    }

    private function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Show the form (again), with the old values and any error messages. */
    private function renderForm(int $hospitalId, array $old, array $errors, int $status = 200): void
    {
        $requests = new RequestRepository();
        $hospital = $requests->hospitalProfile($hospitalId);
        if ($hospital === null || $hospital['account_status'] !== 'active') {
            throw new ForbiddenException('Only an active hospital account can raise blood requests.');
        }

        $this->render('hospital/request_new', [
            'title'        => 'New Blood Request',
            'hospital'     => $hospital,
            'verified'     => $hospital['verification_status'] === 'verified',
            'openCritical' => $requests->openCriticalCount($hospitalId),
            'maxCritical'  => $requests->maxOpenCritical(),
            'bloodGroups'  => MatchingService::BLOOD_GROUPS,
            'old'          => $old,
            'errors'       => $errors,
        ], $status);
    }
}
