<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DonorNexa\Repositories\DonationRepository;

/**
 * What a hospital can do with its own request after the donors were matched:
 * record a completed donation, cancel the request (lowers the trust score) or
 * close it. The transactions are in DonationRepository.
 */
class RequestLifecycleService
{
    private DonationRepository $donations;

    public function __construct(?DonationRepository $donations = null)
    {
        $this->donations = $donations ?? new DonationRepository();
    }

    public function markDonated(int $hospitalId, int $requestId, int $matchId, string $ip): void
    {
        $this->donations->markDonated($hospitalId, $requestId, $matchId, $ip);
    }

    public function cancel(int $hospitalId, int $requestId, string $ip): void
    {
        $this->donations->cancel($hospitalId, $requestId, $ip);
    }

    public function close(int $hospitalId, int $requestId, string $ip): void
    {
        $this->donations->close($hospitalId, $requestId, $ip);
    }
}
