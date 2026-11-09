<?php
declare(strict_types=1);

namespace DonorNexa\Exceptions;

use RuntimeException;

/**
 * Base class for our own errors. The exception code is the HTTP status
 * (404, 403, 500 ...) and the message is safe to show to the user.
 */
class AppException extends RuntimeException
{
}
