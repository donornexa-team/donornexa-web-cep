<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Repositories\PublicRepository;

/**
 * Public "Active Emergencies" board. Anyone can open it (no login).
 */
class EmergencyController extends BaseController
{
    public function index(array $params = []): void
    {
        $requests = (new PublicRepository())->activeEmergencies();

        $this->render('emergencies', [
            'title'    => 'Active Emergencies',
            'requests' => $requests,
        ]);
    }
}
