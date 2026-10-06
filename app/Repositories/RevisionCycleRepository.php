<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Reads/writes for revision_cycles (0005_revision). Each cycle snapshots
 * the memorized range that was current when it was generated; old cycles
 * are superseded, never destroyed (schema history policy §6).
 */
final class RevisionCycleRepository extends Repository
{
    /** Latest cycle by number (the one the user works on). */
    public function findLatestByPlan(int $planId): ?array
    {
        return $this->fetch(
            'SELECT id, plan_id, user_id, cycle_number, range_start_page, range_end_page,
                    boundary_page_snapshot, segment_count, status, started_at, completed_at,
                    created_at, updated_at
               FROM revision_cycles
              WHERE plan_id = ?
              ORDER BY cycle_number DESC
              LIMIT 1',
            [$planId]
        );
    }

    /** @return array<string, mixed>|null scoped to its owner */
    public function find(int $userId, int $cycleId): ?array
    {
        return $this->fetch(
            'SELECT id, plan_id, user_id, cycle_number, range_start_page, range_end_page,
                    boundary_page_snapshot, segment_count, status, started_at, completed_at,
                    created_at, updated_at
               FROM revision_cycles
              WHERE id = ? AND user_id = ?',
            [$cycleId, $userId]
        );
    }

    /** @return array<int, array<string, mixed>> all cycles of a plan, oldest first */
    public function findByPlan(int $planId): array
    {
        return $this->fetchAll(
            'SELECT id, plan_id, cycle_number, range_start_page, range_end_page,
                    boundary_page_snapshot, segment_count, status, started_at, completed_at
               FROM revision_cycles
              WHERE plan_id = ?
              ORDER BY cycle_number ASC',
            [$planId]
        );
    }

    /** @return int new cycle id */
    public function create(
        int $planId,
        int $userId,
        int $cycleNumber,
        int $rangeStartPage,
        int $rangeEndPage,
        int $boundarySnapshot,
        int $segmentCount,
    ): int {
        return $this->insert(
            'INSERT INTO revision_cycles
                    (plan_id, user_id, cycle_number, range_start_page, range_end_page,
                     boundary_page_snapshot, segment_count, status,
                     started_at, completed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$planId, $userId, $cycleNumber, $rangeStartPage, $rangeEndPage, $boundarySnapshot, $segmentCount]
        );
    }

    /** pending → active (first segment of the cycle got underway). */
    public function markActive(int $cycleId): void
    {
        $this->run(
            "UPDATE revision_cycles
                SET status = 'active', started_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'pending'",
            [$cycleId]
        );
    }

    /** active → completed (every segment is completed or skipped). */
    public function markCompleted(int $cycleId): void
    {
        $this->run(
            "UPDATE revision_cycles
                SET status = 'completed', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'active'",
            [$cycleId]
        );
    }

    /** Keeps segment_count honest after a pending-tail regeneration. */
    public function updateSegmentCount(int $cycleId, int $segmentCount): void
    {
        $this->run(
            'UPDATE revision_cycles
                SET segment_count = ?, updated_at = UTC_TIMESTAMP()
              WHERE id = ?',
            [$segmentCount, $cycleId]
        );
    }

    /** active/pending → superseded (replaced by a regenerated cycle). */
    public function markSuperseded(int $cycleId): void
    {
        $this->run(
            "UPDATE revision_cycles
                SET status = 'superseded', updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status IN ('pending', 'active')",
            [$cycleId]
        );
    }
}
