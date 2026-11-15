<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Repositories\AdminRepository;
use DonorNexa\Repositories\AuditRepository;
use DonorNexa\Repositories\DonorSearchRepository;
use DonorNexa\Services\CsvExporter;
use DonorNexa\Services\ListQuery;
use DonorNexa\Services\MatchingService;

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

        $list     = $this->auditList();
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

    /** GET /admin/donors : live donor search page (the data comes from api/donors-search.php). */
    public function donors(array $params = []): void
    {
        $this->requireRole('admin');

        // The api folder is next to public/ (same trick as the landing page).
        $projectUrl = rtrim(str_replace('\\', '/', dirname(BASE_PATH)), '/');

        $this->render('admin/donors', [
            'title'       => 'Donor Search',
            'cities'      => (new DonorSearchRepository())->cities(),
            'bloodGroups' => MatchingService::BLOOD_GROUPS,
            'apiUrl'      => $projectUrl . '/api/donors-search.php',
        ]);
    }

    /**
     * GET /admin/export/{report} : download a CSV report.
     * report = audit (current search/filter/sort of the audit page), requests or donors.
     * Every export is written to the audit log.
     */
    public function export(array $params = []): void
    {
        $user   = $this->requireRole('admin');
        $report = (string) ($params['report'] ?? '');
        $admin  = new AdminRepository();

        switch ($report) {
            case 'audit':
                $list     = $this->auditList();
                $entity   = $list->filter('entity', $admin->auditEntities());
                $headings = ['Time', 'User', 'Role', 'Action', 'Entity', 'Entity ID', 'Old value', 'New value', 'IP address'];
                $rows     = $admin->exportAuditLogs($list, $entity);
                break;
            case 'requests':
                $headings = ['ID', 'Hospital', 'City', 'Blood group', 'Units needed', 'Units fulfilled', 'Urgency',
                             'Required by', 'Status', 'Created at', 'Donors matched'];
                $rows     = $admin->exportRequests();
                break;
            case 'donors':
                $headings = ['ID', 'Name', 'Blood group', 'City', 'Account status', 'Available', 'Last donation', 'Eligible now'];
                $rows     = $admin->exportDonors();
                break;
            default:
                throw new NotFoundException('That report does not exist.');
        }

        (new AuditRepository())->log(
            (int) $user['id'],
            'report.exported',
            'reports',
            null,
            null,
            $report . ': ' . count($rows) . ' row(s)',
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')
        );

        (new CsvExporter())->send('donornexa_' . $report . '_' . date('Ymd_His') . '.csv', $headings, $rows);
        exit;
    }

    /** The audit page's list definition (search, sort keys), shared with the CSV export. */
    private function auditList(): ListQuery
    {
        return new ListQuery($_GET, '/admin/audit', [
            'when'   => 'a.created_at',
            'user'   => 'u.name',
            'action' => 'a.action',
            'entity' => 'a.entity',
            'ip'     => 'a.ip_address',
        ], 'when', 'desc', 15);
    }
}
