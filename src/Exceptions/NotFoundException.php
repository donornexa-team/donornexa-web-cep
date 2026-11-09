<?php
declare(strict_types=1);

namespace DonorNexa\Exceptions;

/** HTTP 404: the page or record does not exist. */
class NotFoundException extends AppException
{
    public function __construct(string $message = 'The page you are looking for does not exist.')
    {
        parent::__construct($message, 404);
    }
}
