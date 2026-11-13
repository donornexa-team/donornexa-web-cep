<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DateInterval;
use DateTimeImmutable;

/**
 * Server-side validation of the "new blood request" form.
 *
 * Like AccountValidator it only looks at the values it is given (no database,
 * no $_POST), so it can be tested from the command line. Each check returns an
 * error message, or null when the value is fine; validate() collects the
 * messages into an array such as ['units_needed' => 'Enter 1 to 20 units.'].
 *
 * Rules:
 *   blood_group   one of the eight groups
 *   units_needed  whole number 1-20 (the same range as the database CHECK)
 *   urgency       critical, urgent or normal
 *   required_by   a real date and time, later than now, at most 30 days ahead
 *   ward_notes    optional, up to 255 characters, plain characters only
 *                 (ward or bed information: NEVER a patient name)
 */
class RequestValidator
{
    public const MIN_UNITS      = 1;
    public const MAX_UNITS      = 20;
    public const MAX_DAYS_AHEAD = 30;
    public const NOTES_MAX      = 255;

    /** The browser's datetime-local field sends "2026-11-15T18:30". */
    private const FORMATS = ['Y-m-d\TH:i', 'Y-m-d H:i'];

    /** Letters, digits, spaces and . , ; : ( ) / # & ' + - only (no < > or quotes that could break markup). */
    private const NOTES_PATTERN = '/^[\p{L}\p{N} .,;:()\/#&\'+\-]*$/u';

    /**
     * @param array                  $in  blood_group, units_needed, urgency, required_by, ward_notes
     * @param DateTimeImmutable|null $now "current time"; only tests pass it
     * @return array<string,string> field => message (empty array = valid)
     */
    public function validate(array $in, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now');   // Asia/Karachi (set in bootstrap)

        return $this->onlyErrors([
            'blood_group'  => $this->bloodGroupError($in['blood_group'] ?? ''),
            'units_needed' => $this->unitsError($in['units_needed'] ?? ''),
            'urgency'      => $this->urgencyError($in['urgency'] ?? ''),
            'required_by'  => $this->requiredByError($in['required_by'] ?? '', $now),
            'ward_notes'   => $this->notesError($in['ward_notes'] ?? ''),
        ]);
    }

    // ------------------------------------------------------------ single checks

    public function bloodGroupError(string $group): ?string
    {
        return in_array($group, MatchingService::BLOOD_GROUPS, true) ? null : 'Choose the blood group the patient needs.';
    }

    public function unitsError(string $units): ?string
    {
        if (!ctype_digit($units) || (int) $units < self::MIN_UNITS || (int) $units > self::MAX_UNITS) {
            return 'Enter the number of units (' . self::MIN_UNITS . ' to ' . self::MAX_UNITS . ').';
        }
        return null;
    }

    public function urgencyError(string $urgency): ?string
    {
        return in_array($urgency, MatchingService::URGENCIES, true) ? null : 'Choose how urgent the request is.';
    }

    public function requiredByError(string $value, DateTimeImmutable $now): ?string
    {
        $when = $this->parseRequiredBy($value);
        if ($when === null) {
            return 'Enter the date and time the blood is needed by.';
        }
        if ($when <= $now) {
            return 'The required-by time must be in the future.';
        }
        if ($when > $now->add(new DateInterval('P' . self::MAX_DAYS_AHEAD . 'D'))) {
            return 'The required-by time can be at most ' . self::MAX_DAYS_AHEAD . ' days ahead.';
        }
        return null;
    }

    public function notesError(string $notes): ?string
    {
        if (mb_strlen($notes) > self::NOTES_MAX) {
            return 'The notes are too long (' . self::NOTES_MAX . ' characters at most).';
        }
        if (preg_match(self::NOTES_PATTERN, $notes) !== 1) {
            return 'Use letters, numbers and simple punctuation only. Never enter a patient name.';
        }
        return null;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * "2026-11-15T18:30" (or "2026-11-15 18:30") as a date, or null when it is
     * not a real date. 31 February is rejected because the date is formatted
     * back and compared with the input.
     */
    public function parseRequiredBy(string $value): ?DateTimeImmutable
    {
        foreach (self::FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }
        return null;
    }

    /** Message shown when a hospital is at its limit of open critical requests. */
    public static function criticalLimitMessage(int $max): string
    {
        return 'You already have ' . $max . ' open critical requests, which is the limit. '
            . 'Wait until one is fulfilled, closed or expired, or choose a lower urgency.';
    }

    /** Drop the fields that passed (null) and keep only the messages. */
    private function onlyErrors(array $checks): array
    {
        return array_filter($checks, static function ($message): bool {
            return $message !== null;
        });
    }
}
