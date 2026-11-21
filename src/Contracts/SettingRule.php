<?php
declare(strict_types=1);

namespace DonorNexa\Contracts;

/**
 * One validation rule for one system setting.
 * Every rule answers the same question ("what is wrong with this value?"), so
 * SettingsValidator can check any setting without knowing which kind of rule it is.
 */
interface SettingRule
{
    /** An error message for the typed value, or null when it is acceptable. */
    public function error(string $value): ?string;

    /** Short text for the form, e.g. "whole number from 30 to 365". */
    public function describe(): string;
}
