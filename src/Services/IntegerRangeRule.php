<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DonorNexa\Contracts\SettingRule;

/** A setting that must be a whole number between $min and $max (inclusive). */
class IntegerRangeRule implements SettingRule
{
    private int $min;
    private int $max;

    public function __construct(int $min, int $max)
    {
        $this->min = $min;
        $this->max = $max;
    }

    public function error(string $value): ?string
    {
        if (preg_match('/^\d{1,6}$/', $value) !== 1) {
            return 'Enter a whole number (digits only).';
        }
        $number = (int) $value;
        if ($number < $this->min || $number > $this->max) {
            return 'Enter a number from ' . $this->min . ' to ' . $this->max . '.';
        }

        return null;
    }

    public function describe(): string
    {
        return 'whole number from ' . $this->min . ' to ' . $this->max;
    }
}
