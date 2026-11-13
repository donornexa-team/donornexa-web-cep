<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DateTimeImmutable;
use DonorNexa\Exceptions\ForbiddenException;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\RequestRepository;

/**
 * Raising a blood request: the steps from "form submitted" to "donors notified".
 *
 *  1. The hospital must exist, be active and be VERIFIED by an admin.
 *  2. The form is validated (RequestValidator).
 *  3. A hospital may have at most max_open_critical (3) open critical requests.
 *  4. MatchingService ranks the eligible donors (best first, already cut to
 *     the pool size; critical requests widen the pool automatically).
 *  5. RequestRepository saves the request, the matches, the notifications and
 *     the audit entries in one transaction (it re-checks step 3 under a lock).
 *
 * Controllers call raise() and show the result; they hold no business rules.
 */
class RequestService
{
    private RequestRepository $requests;
    private MatchingService $matching;
    private RequestValidator $validator;

    public function __construct(
        ?RequestRepository $requests = null,
        ?MatchingService $matching = null,
        ?RequestValidator $validator = null
    ) {
        $this->requests  = $requests ?? new RequestRepository();
        $this->matching  = $matching ?? new MatchingService();
        $this->validator = $validator ?? new RequestValidator();
    }

    /**
     * @param int    $hospitalId the logged-in hospital's user id
     * @param array  $input      blood_group, units_needed, urgency, required_by, ward_notes (text from the form)
     * @param string $ip         client IP address, for the audit log
     * @return array{request_id:int, donors_notified:int}
     * @throws ForbiddenException  not a verified, active hospital
     * @throws ValidationException invalid input, or the critical-request limit is reached
     */
    public function raise(int $hospitalId, array $input, string $ip): array
    {
        $hospital = $this->requests->hospitalProfile($hospitalId);
        $this->assertMayRaise($hospital);

        $errors = $this->validator->validate($input);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $urgency = $input['urgency'];
        $max     = $this->requests->maxOpenCritical();
        if ($urgency === 'critical' && $this->requests->openCriticalCount($hospitalId) >= $max) {
            throw new ValidationException(['urgency' => RequestValidator::criticalLimitMessage($max)]);
        }

        $requiredBy = $this->validator->parseRequiredBy($input['required_by']);

        $matches = $this->matching->findMatches(
            $input['blood_group'],
            (int) $hospital['city_id'],
            $urgency
        );

        $requestId = $this->requests->createWithMatches(
            $hospitalId,
            [
                'blood_group'  => $input['blood_group'],
                'units_needed' => (int) $input['units_needed'],
                'urgency'      => $urgency,
                'required_by'  => $requiredBy->format('Y-m-d H:i:s'),
                'ward_notes'   => (($input['ward_notes'] ?? '') === '') ? null : $input['ward_notes'],
            ],
            $matches,
            $this->notificationMessage($hospital, $input, $requiredBy),
            $max,
            $ip
        );

        return ['request_id' => $requestId, 'donors_notified' => count($matches)];
    }

    /** The text every matched donor receives (255 characters at most; no phone, no patient details). */
    public function notificationMessage(array $hospital, array $input, DateTimeImmutable $requiredBy): string
    {
        $text = sprintf(
            '%s: %d unit(s) of %s blood needed at %s (%s) by %s. Log in to DonorNexa to accept or decline.',
            ucfirst($input['urgency']),
            (int) $input['units_needed'],
            $input['blood_group'],
            $hospital['hospital_name'],
            $hospital['city_name'],
            $requiredBy->format('d M Y, g:i A')
        );

        return mb_strimwidth($text, 0, 255, '...');
    }

    /** Only an active, verified hospital may raise requests. */
    private function assertMayRaise(?array $hospital): void
    {
        if ($hospital === null || $hospital['account_status'] !== 'active') {
            throw new ForbiddenException('Only an active hospital account can raise blood requests.');
        }
        if ($hospital['verification_status'] !== 'verified') {
            throw new ForbiddenException('Your hospital must be verified by an administrator before you can raise blood requests.');
        }
    }
}
