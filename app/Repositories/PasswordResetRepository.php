<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Password-reset tokens (hash-only, single-use, time-limited).
 */
final class PasswordResetRepository extends Repository
{
    public function create(int $userId, string $tokenHash, string $expiresAt, ?string $ip): void
    {
        $this->run(
            'INSERT INTO password_reset_tokens (user_id, token_hash, requested_ip, expires_at, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [$userId, $tokenHash, $ip, $expiresAt]
        );
    }

    /** @return array<string, mixed>|null */
    public function findValidByHash(string $tokenHash): ?array
    {
        return $this->fetch(
            'SELECT id, user_id, expires_at
             FROM password_reset_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1',
            [$tokenHash]
        );
    }

    /** Atomic single-use claim: succeeds only once for a given token. */
    public function claim(int $tokenId): bool
    {
        $affected = $this->run(
            'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP()
             WHERE id = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [$tokenId]
        )->rowCount();

        return $affected === 1;
    }

    public function deleteAllForUser(int $userId): void
    {
        $this->run('DELETE FROM password_reset_tokens WHERE user_id = ?', [$userId]);
    }
}
