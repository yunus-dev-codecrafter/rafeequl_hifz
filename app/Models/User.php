<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Authenticated user representation.
 * The profile() shape is the ONLY user data that may leave the server —
 * it never contains password hashes or session token hashes.
 */
final class User extends Model
{
    public function id(): int
    {
        return (int) $this->get('id', 0);
    }

    public function email(): string
    {
        return (string) $this->get('email', '');
    }

    public function displayName(): string
    {
        return (string) $this->get('display_name', '');
    }

    public function status(): string
    {
        return (string) $this->get('status', '');
    }

    public function isActive(): bool
    {
        return $this->status() === 'active';
    }

    /** @return array<string, mixed> safe public representation */
    public function profile(): array
    {
        return [
            'id' => $this->id(),
            'email' => $this->email(),
            'display_name' => $this->displayName(),
            'status' => $this->status(),
            'created_at' => $this->get('created_at'),
        ];
    }
}
