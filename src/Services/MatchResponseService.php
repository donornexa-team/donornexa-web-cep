<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\MatchRepository;

/**
 * A donor answers a matched request: accept, or decline with an optional reason.
 * The rules (still open, still eligible, no over-allocation) are enforced inside
 * the locked transaction of MatchRepository::accept().
 */
class MatchResponseService
{
    public const REASON_MAX = 255;

    private MatchRepository $matches;

    public function __construct(?MatchRepository $matches = null)
    {
        $this->matches = $matches ?? new MatchRepository();
    }

    public function accept(int $donorId, int $requestId, string $ip): void
    {
        $this->matches->accept($donorId, $requestId, $ip);
    }

    /** @throws ValidationException when the reason is too long or has unusual characters */
    public function decline(int $donorId, int $requestId, string $reason, string $ip): void
    {
        $reason = trim($reason);
        $error  = $this->reasonError($reason);
        if ($error !== null) {
            throw new ValidationException(['reason' => $error]);
        }

        $this->matches->decline($donorId, $requestId, $reason, $ip);
    }

    /** Optional reason: up to 255 letters, digits and simple punctuation (no markup). */
    public function reasonError(string $reason): ?string
    {
        if (mb_strlen($reason) > self::REASON_MAX) {
            return 'The reason is too long (' . self::REASON_MAX . ' characters at most).';
        }
        if (preg_match('/^[\p{L}\p{N} .,;:()\/#&\'+\-!?]*$/u', $reason) !== 1) {
            return 'Use letters, numbers and simple punctuation only.';
        }

        return null;
    }
}
