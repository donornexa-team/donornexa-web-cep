<?php
declare(strict_types=1);

namespace DonorNexa\Exceptions;

/** HTTP 403: logged in, but not allowed to do this. */
class ForbiddenException extends AppException
{
    public function __construct(string $message = 'You do not have permission to view this page.')
    {
        parent::__construct($message, 403);
    }
}
