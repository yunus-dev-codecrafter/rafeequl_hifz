<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Reads/writes for revision_segments (0005_revision): daily chunks of a
 * cycle. Segments are generated from the memorized range (never beyond
 * it), worked in order, and finalized as completed or skipped.
 */
final class RevisionSegmentRepository extends Repository
{
    /**
     * Segment joined with its cycle and plan — the context every session/
     * skip decision needs, scoped to the owner.
     *
     * @return array<string, mixed>|null
     */
    public function findWithContext(int $userId, int $segmentId): ?array
    {
        return $this->fetch(
            'SELECT s.id, s.cycle_id, s.user_id, s.segment_number, s.start_page, s.end_page,
                    s.page_count, s.scheduled_date, s.status, s.completed_at,
                    c.plan_id, c.cycle_number, c.status AS cycle_status,
                    c.range_start_page AS cycle_start_page, c.range_end_page AS cycle_end_page,
                    p.status AS plan_status, p.name AS plan_name
               FROM revision_segments s
               JOIN revision_cycles c ON c.id = s.cycle_id
               JOIN revision_plans p ON p.id = c.plan_id
              WHERE s.id = ? AND s.user_id = ?',
            [$segmentId, $userId]
        );
    }

    /** @return array<int, array<string, mixed>> segment order */
    public function findByCycle(int $cycleId): array
    {
        return $this->fetchAll(
            'SELECT id, segment_number, start_page, end_page, page_count,
                    scheduled_date, status, completed_at
               FROM revision_segments
              WHERE cycle_id = ?
              ORDER BY segment_number ASC',
            [$cycleId]
        );
    }

    /**
     * The last segment already out of "pending" (completed/skipped/active).
     * Everything after it is the pending tail a target change regenerates.
     *
     * @return array<string, mixed>|null
     */
    public function findLastNonPending(int $cycleId): ?array
    {
        return $this->fetch(
            "SELECT id, segment_number, start_page, end_page, status
               FROM revision_segments
              WHERE cycle_id = ? AND status <> 'pending'
              ORDER BY segment_number DESC
              LIMIT 1",
            [$cycleId]
        );
    }

    /** Segments still owed on this cycle (pending or active). */
    public function countUnfinished(int $cycleId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM revision_segments WHERE cycle_id = ? AND status IN ('pending', 'active')",
            [$cycleId]
        );
    }

    /** In-progress attempt on this segment (session not yet finished). */
    public function countInProgressSessions(int $segmentId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM revision_sessions WHERE segment_id = ? AND ended_at IS NULL',
            [$segmentId]
        );
    }

    public function createPending(
        int $cycleId,
        int $userId,
        int $segmentNumber,
        int $startPage,
        int $endPage,
        string $scheduledDate,
    ): void {
        $this->insert(
            'INSERT INTO revision_segments
                    (cycle_id, user_id, segment_number, start_page, end_page, page_count,
                     scheduled_date, status, completed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$cycleId, $userId, $segmentNumber, $startPage, $endPage, $endPage - $startPage + 1, $scheduledDate]
        );
    }

    /** Removes the not-yet-started tail before regenerating it. */
    public function deletePendingInCycle(int $cycleId): int
    {
        $statement = $this->run(
            "DELETE FROM revision_segments WHERE cycle_id = ? AND status = 'pending'",
            [$cycleId]
        );
        return $statement->rowCount();
    }

    /** pending → active (first attempt on this segment started). */
    public function markActive(int $segmentId): void
    {
        $this->run(
            "UPDATE revision_segments
                SET status = 'active', updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'pending'",
            [$segmentId]
        );
    }

    /** pending/active → completed. */
    public function markCompleted(int $segmentId): void
    {
        $this->run(
            "UPDATE revision_segments
                SET status = 'completed', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status IN ('pending', 'active')",
            [$segmentId]
        );
    }

    /** pending/active → skipped (explicit missed-day decision). */
    public function markSkipped(int $segmentId): void
    {
        $this->run(
            "UPDATE revision_segments
                SET status = 'skipped', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status IN ('pending', 'active')",
            [$segmentId]
        );
    }
}
