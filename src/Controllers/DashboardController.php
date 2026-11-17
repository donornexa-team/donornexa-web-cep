<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Repositories\DashboardRepository;
use DonorNexa\Repositories\DonorRepository;
use DonorNexa\Repositories\InventoryRepository;
use DonorNexa\Repositories\RequestRepository;
use DonorNexa\Services\BadgeService;
use DonorNexa\Services\EligibilityService;
use DonorNexa\Services\ExpiryService;

/**
 * Home pages after login: /donor/dashboard and /hospital/dashboard
 * (the admin dashboard has its own controller). Read-only: each page only
 * gathers numbers for the logged-in user and links to the pages that act on them.
 */
class DashboardController extends BaseController
{
    public function donor(array $params = []): void
    {
        $user    = $this->requireRole('donor');
        $userId  = (int) $user['id'];
        $donors  = new DonorRepository();
        $profile = $donors->findProfile($userId);
        if ($profile === null) {
            throw new NotFoundException('Donor profile not found.');
        }

        (new ExpiryService())->sweep();   // so "waiting for you" never shows an expired request

        $dash   = new DashboardRepository();
        $counts = $dash->donorCounts($userId);
        $badges = new BadgeService();

        $this->render('donor/dashboard', [
            'title'       => 'My dashboard',
            'profile'     => $profile,
            'eligibility' => (new EligibilityService())->status($profile, $donors->cooldownDays()),
            'counts'      => $counts,
            'waiting'     => $dash->donorWaiting($userId),
            'badges'      => $badges->forCount($counts['donations']),
            'untilNext'   => $badges->untilNext($counts['donations']),
        ]);
    }

    public function hospital(array $params = []): void
    {
        $user     = $this->requireRole('hospital');
        $userId   = (int) $user['id'];
        $hospital = (new RequestRepository())->hospitalProfile($userId);
        if ($hospital === null) {
            throw new NotFoundException('Hospital profile not found.');
        }

        (new ExpiryService())->sweep();

        $dash = new DashboardRepository();

        $this->render('hospital/dashboard', [
            'title'     => 'Hospital dashboard',
            'hospital'  => $hospital,
            'counts'    => $dash->hospitalCounts($userId),
            'open'      => $dash->hospitalOpen($userId),
            'inventory' => (new InventoryRepository())->forHospital($userId),
        ]);
    }
}
