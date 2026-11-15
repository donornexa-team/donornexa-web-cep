<?php
declare(strict_types=1);

namespace DonorNexa\Services;

/**
 * Validates the inventory form: one whole number of units for each of the
 * eight blood groups (0 to MAX_UNITS). Like the other validators it looks only
 * at the values it is given, so it can be tested without a database.
 */
class InventoryValidator
{
    public const MAX_UNITS = 5000;

    /**
     * @param mixed $units what the form sent in units[...] (normally an array: group => text)
     * @return array{values: array<string,int>, errors: array<string,string>}
     *         values  = the accepted numbers per blood group,
     *         errors  = blood group => message (empty = everything is valid)
     */
    public function validate($units): array
    {
        $units  = is_array($units) ? $units : [];
        $values = [];
        $errors = [];

        foreach (MatchingService::BLOOD_GROUPS as $group) {
            $text = $units[$group] ?? '';
            $text = is_string($text) ? trim($text) : '';

            if (!ctype_digit($text) || strlen($text) > 5 || (int) $text > self::MAX_UNITS) {
                $errors[$group] = 'Enter a whole number from 0 to ' . self::MAX_UNITS . '.';
                continue;
            }
            $values[$group] = (int) $text;
        }

        return ['values' => $values, 'errors' => $errors];
    }
}
