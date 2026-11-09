<?php
declare(strict_types=1);

namespace DonorNexa\Exceptions;

/**
 * Thrown by services when input is invalid. Carries the field errors:
 *   throw new ValidationException(['email' => 'Enter a valid email address.']);
 * Catch it in the controller and show the errors next to the form fields.
 */
class ValidationException extends AppException
{
    private array $errors;

    public function __construct(array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message, 422);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
