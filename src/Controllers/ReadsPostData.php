<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

/**
 * Trait (course requirement: traits) shared by the account controllers.
 * Reads form fields from $_POST safely.
 */
trait ReadsPostData
{
    /**
     * One text field, trimmed. A field that is missing, or that was sent as an
     * array (name[]=x), becomes '' so the validators never receive an array.
     */
    protected function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** Several fields at once: ['name' => '...', 'email' => '...']. */
    protected function postFields(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->postString($key);
        }

        return $values;
    }

    /** A password: same as postString() but NOT trimmed (spaces may be part of it). */
    protected function postPassword(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
