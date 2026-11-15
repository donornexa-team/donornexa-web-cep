<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DonorNexa\Repositories\ExpiryRepository;
use DonorNexa\Repositories\MatchingRepository;

/**
 * Auto-expiry (CEP business rule): requests past their required-by time are
 * marked "expired", and unanswered donor notifications are marked "no response"
 * (after the setting no_response_hours, or at once when the request is no longer open).
 *
 * XAMPP has no background job, so the sweep runs whenever someone opens a page
 * that shows requests (hospital requests, donor inbox). It is a couple of
 * indexed UPDATEs and does nothing when there is nothing to expire. For a
 * server with cron, the same sweep() can be called from a scheduled script.
 */
class ExpiryService
{
    private ExpiryRepository $expiry;
    private MatchingRepository $settings;

    public function __construct(?ExpiryRepository $expiry = null, ?MatchingRepository $settings = null)
    {
        $this->expiry   = $expiry ?? new ExpiryRepository();
        $this->settings = $settings ?? new MatchingRepository();
    }

    /** @return array{requests_expired:int, matches_no_response:int} how many rows were changed */
    public function sweep(): array
    {
        return $this->expiry->sweep($this->settings->setting('no_response_hours', 24));
    }
}
