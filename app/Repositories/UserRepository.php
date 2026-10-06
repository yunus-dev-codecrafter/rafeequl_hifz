<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Users, credentials, and account counters (authentication only).
 * All queries are parameterized; results are mapped by callers.
 */
final class UserRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $userId): ?array
    {
        return $this->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
    }

    /** @return array<string, mixed>|null */
    public function findAuth(int $userId): ?array
    {
        return $this->fetch(
            'SELECT user_id, password_hash, failed_login_count, locked_until
             FROM user_auth WHERE user_id = ? LIMIT 1',
            [$userId]
        );
    }

    /**
     * Creates users + credentials + default settings rows.
     * Caller must wrap this in a transaction.
     */
    public function create(string $email, string $passwordHash, string $displayName): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $userId = $this->insert(
            'INSERT INTO users (email, display_name, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$email, $displayName, 'active', $now, $now]
        );

        $this->run(
            'INSERT INTO user_auth (user_id, password_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?)',
            [$userId, $passwordHash, $now, $now]
        );

        $this->run(
            'INSERT INTO user_settings (user_id, theme, locale, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, 'auto', 'ar', $now, $now]
        );

        return $userId;
    }

    public function updateProfile(int $userId, string $email, string $displayName): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->run(
            'UPDATE users SET email = ?, display_name = ?, updated_at = ? WHERE id = ?',
            [$email, $displayName, $now, $userId]
        );
    }

    /** Soft delete (Prompt 18): tombstone status + anonymized identity. */
    public function softDelete(int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->run(
            "UPDATE users SET status = 'deleted', email = ?, display_name = '', updated_at = ?
              WHERE id = ?",
            ['deleted+' . $userId . '@deleted.invalid', $now, $userId]
        );
    }

    public function recordFailedLogin(int $userId, int $failedCount, ?string $lockedUntil): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->run(
            'UPDATE user_auth SET failed_login_count = ?, locked_until = ?, updated_at = ?
             WHERE user_id = ?',
            [$failedCount, $lockedUntil, $now, $userId]
        );
    }

    /** Clears an expired lockout so the next mistake starts a fresh attempt window. */
    public function clearLockout(int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->run(
            'UPDATE user_auth SET failed_login_count = 0, locked_until = NULL, updated_at = ?
             WHERE user_id = ?',
            [$now, $userId]
        );
    }

    public function recordSuccessfulLogin(int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->run(
            'UPDATE user_auth SET failed_login_count = 0, locked_until = NULL, updated_at = ?
             WHERE user_id = ?',
            [$now, $userId]
        );
        $this->run('UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $userId]);
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->run(
            'UPDATE user_auth SET password_hash = ?, password_changed_at = ?, failed_login_count = 0,
                                 locked_until = NULL, updated_at = ?
             WHERE user_id = ?',
            [$passwordHash, $now, $now, $userId]
        );
    }
}
