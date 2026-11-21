<?php
declare(strict_types=1);

namespace DonorNexa\Models;

/**
 * One account (users table). Returned by UserRepository::findByEmail().
 */
class User
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $passwordHash,
        public string $role,                 // admin | hospital | donor
        public string $status,               // active | blocked
        public ?string $verificationStatus = null   // only for hospitals
    ) {
    }

    /** Build a User from a database row. */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['password_hash'],
            (string) $row['role'],
            (string) $row['status'],
            $row['verification_status'] ?? null
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isUnverifiedHospital(): bool
    {
        return $this->role === 'hospital' && $this->verificationStatus !== 'verified';
    }

    /** Where this user lands after logging in. */
    public function dashboardPath(): string
    {
        return [
            'donor'    => '/donor/dashboard',
            'hospital' => '/hospital/dashboard',
            'admin'    => '/admin/dashboard',
        ][$this->role] ?? '/';
    }
}