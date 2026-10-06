<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Fixed-window rate-limit counters (Prompt 25, audit finding F-03).
 * bucket_key is always a SHA-256 hash supplied by the caller, so no raw
 * IP addresses or e-mail addresses are ever stored in this table.
 * Timestamps are UTC and must match PHP's gmdate() convention.
 */
final class RateLimitRepository extends Repository
{
    /**
     * Records one attempt: starts a fresh window when the previous one has
     * expired, otherwise increments the counter. Returns the bucket's
     * attempts AFTER the upsert (atomic single-row update, race-safe).
     */
    public function record(string $bucketHash, string $windowCutoffUtc): int
    {
        $this->run(
            'INSERT INTO rate_limits (bucket_key, window_started_at, attempts, updated_at)
             VALUES (?, UTC_TIMESTAMP(), 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               attempts = IF(window_started_at <= ?, 1, attempts + 1),
               window_started_at = IF(window_started_at <= ?, UTC_TIMESTAMP(), window_started_at),
               updated_at = UTC_TIMESTAMP()',
            [$bucketHash, $windowCutoffUtc, $windowCutoffUtc]
        );

        $attempts = $this->scalar(
            'SELECT attempts FROM rate_limits WHERE bucket_key = ?',
            [$bucketHash]
        );
        return $attempts === null ? 1 : (int) $attempts;
    }

    /** Opportunistic cleanup: counters last seen over a day ago can never matter. */
    public function prune(string $staleBeforeUtc): void
    {
        $this->run('DELETE FROM rate_limits WHERE window_started_at <= ?', [$staleBeforeUtc]);
    }

    /** @return array{attempts: int, window_started_at: string}|null test support */
    public function find(string $bucketHash): ?array
    {
        $row = $this->fetch(
            'SELECT attempts, window_started_at FROM rate_limits WHERE bucket_key = ?',
            [$bucketHash]
        );
        if ($row === null) {
            return null;
        }
        return ['attempts' => (int) $row['attempts'], 'window_started_at' => (string) $row['window_started_at']];
    }
}
