<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Core\Session;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Repositories\AccountAdminRepository;
use DonorNexa\Services\ListQuery;

/**
 * Admin screens for accounts: verify or reject hospitals, view their licence,
 * and block or unblock users. Every action starts with requireRole('admin').
 * Changes are POST forms (so the Router's CSRF check protects them) and every
 * change is written to audit_logs inside the same transaction.
 */
class AdminAccountsController extends BaseController
{
    // ------------------------------------------------------------- hospitals

    public function hospitals(array $params = []): void
    {
        $this->requireRole('admin');

        $repo = new AccountAdminRepository();
        $list = new ListQuery($_GET, '/admin/hospitals', [
            'name'       => 'h.hospital_name',
            'city'       => 'c.city_name',
            'licence'    => 'h.licence_no',
            'status'     => 'h.verification_status',
            'trust'      => 'h.trust_score',
            'registered' => 'u.created_at',
        ], 'registered', 'desc', 10);

        $status = $list->filter('status', AccountAdminRepository::HOSPITAL_STATUSES);
        $list->applyTotal($repo->countHospitals($list, $status));

        $this->render('admin/hospitals', [
            'title'    => 'Hospitals',
            'list'     => $list,
            'rows'     => $repo->listHospitals($list, $status),
            'statuses' => AccountAdminRepository::HOSPITAL_STATUSES,
            'status'   => $status,
        ]);
    }

    public function verifyHospital(array $params = []): void
    {
        $this->changeVerification($params, 'verified');
    }

    public function rejectHospital(array $params = []): void
    {
        $this->changeVerification($params, 'rejected');
    }

    /** Show the hospital's licence PDF. Only an admin can get here; the file itself is blocked by public/uploads/.htaccess. */
    public function licence(array $params = []): void
    {
        $this->requireRole('admin');

        $file = (new AccountAdminRepository())->licenceFile($this->idFrom($params));
        if ($file === null) {
            throw new NotFoundException('This hospital has no licence file.');
        }

        $path = ROOT_PATH . '/public/uploads/licences/' . basename($file);
        if (!is_file($path)) {
            throw new NotFoundException('The licence file is missing on the server.');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="licence-' . $this->idFrom($params) . '.pdf"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    // ----------------------------------------------------------------- users

    public function users(array $params = []): void
    {
        $this->requireRole('admin');

        $repo = new AccountAdminRepository();
        $list = new ListQuery($_GET, '/admin/users', [
            'name'   => 'u.name',
            'email'  => 'u.email',
            'role'   => 'u.role',
            'status' => 'u.status',
            'joined' => 'u.created_at',
        ], 'joined', 'desc', 10);

        $role   = $list->filter('role', AccountAdminRepository::ROLES);
        $status = $list->filter('status', AccountAdminRepository::USER_STATUSES);
        $list->applyTotal($repo->countUsers($list, $role, $status));

        $this->render('admin/users', [
            'title'    => 'Users',
            'list'     => $list,
            'rows'     => $repo->listUsers($list, $role, $status),
            'roles'    => AccountAdminRepository::ROLES,
            'statuses' => AccountAdminRepository::USER_STATUSES,
            'role'     => $role,
            'status'   => $status,
        ]);
    }

    public function blockUser(array $params = []): void
    {
        $this->changeUserStatus($params, 'blocked');
    }

    public function unblockUser(array $params = []): void
    {
        $this->changeUserStatus($params, 'active');
    }

    // --------------------------------------------------------------- helpers

    private function changeVerification(array $params, string $newStatus): void
    {
        $admin  = $this->requireRole('admin');
        $result = (new AccountAdminRepository())->setVerification(
            $this->idFrom($params),
            $newStatus,
            (int) $admin['id'],
            $this->clientIp()
        );

        if ($result['changed']) {
            Session::flash('success', $result['name'] . ' is now ' . $newStatus . '.');
        } else {
            Session::flash('info', $result['name'] . ' was already ' . $newStatus . '.');
        }
        $this->redirect('/admin/hospitals');
    }

    private function changeUserStatus(array $params, string $newStatus): void
    {
        $admin  = $this->requireRole('admin');
        $result = (new AccountAdminRepository())->setUserStatus(
            $this->idFrom($params),
            $newStatus,
            (int) $admin['id'],
            $this->clientIp()
        );

        if ($result['changed']) {
            Session::flash('success', $result['name'] . ' is now ' . ($newStatus === 'blocked' ? 'blocked.' : 'unblocked.'));
        } else {
            Session::flash('info', $result['name'] . ' was already ' . ($newStatus === 'blocked' ? 'blocked.' : 'active.'));
        }
        $this->redirect('/admin/users');
    }

    /** The {id} of the route as an integer; anything that is not digits is a 404. */
    private function idFrom(array $params): int
    {
        $id = $params['id'] ?? '';
        if (!is_string($id) || !ctype_digit($id)) {
            throw new NotFoundException();
        }

        return (int) $id;
    }

    private function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
