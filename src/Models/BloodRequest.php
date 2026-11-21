<?php
declare(strict_types=1);

namespace DonorNexa\Models;

/**
 * One blood request (blood_requests table). Returned by RequestRepository::findForHospital().
 */
class BloodRequest
{
    public function __construct(
        public int $id,
        public string $bloodGroup,
        public int $unitsNeeded,
        public int $unitsFulfilled,
        public string $urgency,              // critical | urgent | normal
        public string $requiredBy,
        public ?string $wardNotes,
        public string $status,               // open | fulfilled | expired | cancelled | closed
        public string $createdAt,
        public bool $pastDeadline
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['blood_group'],
            (int) $row['units_needed'],
            (int) $row['units_fulfilled'],
            (string) $row['urgency'],
            (string) $row['required_by'],
            $row['ward_notes'] ?? null,
            (string) $row['status'],
            (string) $row['created_at'],
            (bool) $row['past_deadline']
        );
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function unitsRemaining(): int
    {
        return max(0, $this->unitsNeeded - $this->unitsFulfilled);
    }

    public function hasNotes(): bool
    {
        return $this->wardNotes !== null && $this->wardNotes !== '';
    }
}