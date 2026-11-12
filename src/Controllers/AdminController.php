<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Repositories\AdminRepository;
use DonorNexa\Services\ListQuery;

/**
 * Admin dashboard (statistics + charts) and the audit log view.
 * Every action starts with requireRole('admin'): guests are sent to the
 * login page, donors and hospitals get the 403 page.
 */
class AdminController extends BaseController
{
    public function index(array $params = []): void
    {
        $this->requireRole('admin');
        $this->redirect('/admin/dashboard');
    }

    public function dashboard(array $params = []): void
    {
        $this->requireRole('admin');

        $admin = new AdminRepository();

        $this->render('admin/dashboard', [
            'title'    => 'Admin Dashboard',
            'overview' => $admin->overview(),
            'rate'     => $admin->overallFulfilmentRate(),
            'chartUrl' => url('/admin/charts'),
        ]);
    }

    /** JSON for the dashboard charts; public/assets/js/admin.js loads it with fetch(). */
    public function charts(array $params = []): void
    {
        $this->requireRole('admin');

        $admin = new AdminRepository();

        header('Cache-Control: no-store');
        $this->json([
            'requests_per_week' => $admin->requestsPerWeek(12),
            'demand_vs_supply'  => $admin->demandVsSupply(),
            'fulfilment'        => $admin->fulfilmentRates(),
        ]);
    }

    public function audit(array $params = []): void
    {
        $this->requireRole('admin');

        $admin = new AdminRepository();

        $list = new ListQuery($_GET, '/admin/audit', [
            'when'   => 'a.created_at',
            'user'   => 'u.name',
            'action' => 'a.action',
            'entity' => 'a.entity',
            'ip'     => 'a.ip_address',
        ], 'when', 'desc', 15);

        $entities = $admin->auditEntities();
        $entity   = $list->filter('entity', $entities);

        $list->applyTotal($admin->countAuditLogs($list, $entity));

        $this->render('admin/audit', [
            'title'    => 'Audit Log',
            'list'     => $list,
            'rows'     => $admin->auditLogs($list, $entity),
            'entities' => $entities,
            'entity'   => $entity,
        ]);
    }
}
