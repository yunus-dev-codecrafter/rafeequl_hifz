<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Authentication sessions (server-side session records).
 * Only the SHA-256 hash of a session token is ever stored.
 * Timestamps are UTC and must match PHP's gmdate() convention.
 */
final class SessionRepository extends Repository
{
    public function create(int $userId, string $tokenHash, string $expiresAt, ?string $ip, ?string $userAgent): void
    {
        $this->run(
            'INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, last_seen_at, expires_at, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())',
            [$userId, $tokenHash, $ip, $userAgent, $expiresAt]
        );
    }

    /** Active session → joined user row (status must be active). @return array<string, mixed>|null */
    public function findActiveUserByTokenHash(string $tokenHash): ?array
    {
        return $this->fetch(
            "SELECT u.*
             FROM user_sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ?
               AND s.expires_at > UTC_TIMESTAMP()
               AND u.status = 'active'
             LIMIT 1",
            [$tokenHash]
        );
    }

    /** Sliding "last seen" marker — throttled to one write per minute. */
    public function touch(string $tokenHash): void
    {
        $this->run(
            'UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP()
             WHERE token_hash = ? AND last_seen_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND',
            [$tokenHash]
        );
    }

    public function findIdByTokenHash(string $tokenHash): ?int
    {
        $id = $this->scalar('SELECT id FROM user_sessions WHERE token_hash = ? LIMIT 1', [$tokenHash]);
        return $id === null ? null : (int) $id;
    }

    public function deleteByTokenHash(string $tokenHash): void
    {
        $this->run('DELETE FROM user_sessions WHERE token_hash = ?', [$tokenHash]);
    }

    public function deleteAllForUser(int $userId): void
    {
        $this->run('DELETE FROM user_sessions WHERE user_id = ?', [$userId]);
    }

    /** @return array<int, array<string, mixed>> own sessions, WITHOUT any token material */
    public function listForUser(int $userId): array
    {
        return $this->fetchAll(
            'SELECT id, ip_address, user_agent, last_seen_at, expires_at, created_at
             FROM user_sessions
             WHERE user_id = ? AND expires_at > UTC_TIMESTAMP()
             ORDER BY last_seen_at DESC',
            [$userId]
        );
    }

    /** Ownership enforced in the query itself: row is only found for its owner. */
    public function deleteForUser(int $userId, int $sessionId): int
    {
        return $this->run(
            'DELETE FROM user_sessions WHERE id = ? AND user_id = ?',
            [$sessionId, $userId]
        )->rowCount();
    }
}
