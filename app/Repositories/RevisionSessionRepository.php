<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Reads/writes for revision_sessions (0005_revision): one row per attempt.
 *
 * A session is inserted in progress (ended_at NULL) and finalized exactly
 * once — finalization is guarded by `ended_at IS NULL`, so finished rows
 * are history and never rewritten (append-only policy §6).
 */
final class RevisionSessionRepository extends Repository
{
    /** The user's unfinished attempt, if any (at most one at a time). */
    public function findInProgress(int $userId): ?array
    {
        return $this->fetch(
            'SELECT id, segment_id, user_id, resumes_session_id, status, total_pages,
                    pages_completed, last_page_reached, started_at, ended_at,
                    duration_seconds, interruption_reason, notes
               FROM revision_sessions
              WHERE user_id = ? AND ended_at IS NULL
              ORDER BY id DESC
              LIMIT 1',
            [$userId]
        );
    }

    /** @return array<string, mixed>|null scoped to its owner */
    public function find(int $userId, int $sessionId): ?array
    {
        return $this->fetch(
            'SELECT id, segment_id, user_id, resumes_session_id, status, total_pages,
                    pages_completed, last_page_reached, started_at, ended_at,
                    duration_seconds, interruption_reason, notes, created_at
               FROM revision_sessions
              WHERE id = ? AND user_id = ?',
            [$sessionId, $userId]
        );
    }

    /** Session history with its segment context, newest first. */
    public function findByUser(int $userId, int $limit): array
    {
        return $this->fetchAll(
            'SELECT r.id, r.segment_id, r.resumes_session_id, r.status, r.total_pages,
                    r.pages_completed, r.last_page_reached, r.started_at, r.ended_at,
                    r.duration_seconds, r.interruption_reason, r.notes,
                    s.segment_number, s.start_page, s.end_page, s.page_count, s.scheduled_date
               FROM revision_sessions r
               JOIN revision_segments s ON s.id = r.segment_id
              WHERE r.user_id = ?
              ORDER BY r.id DESC
              LIMIT ?',
            [$userId, $limit]
        );
    }

    /** @return int new session id */
    public function create(
        int $segmentId,
        int $userId,
        int $totalPages,
        int $pagesCompleted,
        ?int $lastPageReached,
        ?int $resumesSessionId,
    ): int {
        return $this->insert(
            'INSERT INTO revision_sessions
                    (segment_id, user_id, resumes_session_id, status, total_pages,
                     pages_completed, last_page_reached, started_at, ended_at,
                     duration_seconds, interruption_reason, notes, created_at, updated_at)
             VALUES (?, ?, ?, \'partial\', ?, ?, ?, UTC_TIMESTAMP(), NULL, NULL, NULL, NULL,
                     UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$segmentId, $userId, $resumesSessionId, $totalPages, $pagesCompleted, $lastPageReached]
        );
    }

    /** Live progress while the attempt runs — guarded to unfinished rows. */
    public function updateProgress(int $sessionId, int $pagesCompleted, ?int $lastPageReached): void
    {
        $this->run(
            'UPDATE revision_sessions
                SET pages_completed = ?, last_page_reached = ?, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND ended_at IS NULL',
            [$pagesCompleted, $lastPageReached, $sessionId]
        );
    }

    /**
     * Finalizes an attempt exactly once. Duration is computed in SQL from
     * started_at (server clock), never by the client.
     */
    public function finish(
        int $sessionId,
        string $status,
        int $pagesCompleted,
        ?int $lastPageReached,
        ?string $interruptionReason,
        ?string $notes,
    ): int {
        $statement = $this->run(
            "UPDATE revision_sessions
                SET status = ?,
                    pages_completed = ?,
                    last_page_reached = ?,
                    interruption_reason = ?,
                    notes = ?,
                    ended_at = UTC_TIMESTAMP(),
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()),
                    updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND ended_at IS NULL",
            [$status, $pagesCompleted, $lastPageReached, $interruptionReason, $notes, $sessionId]
        );

        return $statement->rowCount();
    }
}
