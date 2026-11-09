<?php
declare(strict_types=1);

namespace DonorNexa\Exceptions;

/** HTTP 401: not logged in (use for API endpoints; pages redirect to /login). */
class AuthenticationException extends AppException
{
    public function __construct(string $message = 'Please log in to continue.')
    {
        parent::__construct($message, 401);
    }
}
