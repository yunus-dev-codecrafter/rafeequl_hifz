<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Writes/reads for revision_plans (0005_revision). Plan rows hold the
 * user's target configuration and the range snapshot taken at creation;
 * generation logic lives in RevisionService.
 */
final class RevisionPlanRepository extends Repository
{
    /** @return array<int, array<string, mixed>> newest first */
    public function findByUser(int $userId): array
    {
        return $this->fetchAll(
            'SELECT id, name, target_unit, daily_amount, range_start_page, range_end_page,
                    boundary_page_snapshot, status, current_cycle_number,
                    started_at, completed_at, created_at, updated_at
               FROM revision_plans
              WHERE user_id = ?
              ORDER BY id DESC',
            [$userId]
        );
    }

    /** @return array<string, mixed>|null scoped to its owner */
    public function find(int $userId, int $planId): ?array
    {
        return $this->fetch(
            'SELECT id, name, target_unit, daily_amount, range_start_page, range_end_page,
                    boundary_page_snapshot, status, current_cycle_number,
                    started_at, completed_at, created_at, updated_at
               FROM revision_plans
              WHERE id = ? AND user_id = ?',
            [$planId, $userId]
        );
    }

    /** Plans that block creating a new one (active or paused). */
    public function countUnfinishedByUser(int $userId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM revision_plans WHERE user_id = ? AND status IN ('active', 'paused')",
            [$userId]
        );
    }

    /** @return int new plan id */
    public function create(
        int $userId,
        ?string $name,
        string $targetUnit,
        float $dailyAmount,
        int $rangeStartPage,
        int $rangeEndPage,
        int $boundarySnapshot,
    ): int {
        return $this->insert(
            'INSERT INTO revision_plans
                    (user_id, name, target_unit, daily_amount, range_start_page, range_end_page,
                     boundary_page_snapshot, status, current_cycle_number,
                     started_at, completed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'active\', 1, UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$userId, $name, $targetUnit, $dailyAmount, $rangeStartPage, $rangeEndPage, $boundarySnapshot]
        );
    }

    /** Config change only — ranges and snapshots stay as recorded. */
    public function updateTarget(int $planId, string $targetUnit, float $dailyAmount): void
    {
        $this->run(
            'UPDATE revision_plans
                SET target_unit = ?, daily_amount = ?, updated_at = UTC_TIMESTAMP()
              WHERE id = ?',
            [$targetUnit, $dailyAmount, $planId]
        );
    }

    public function markPaused(int $planId): void
    {
        $this->run(
            "UPDATE revision_plans SET status = 'paused', updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'active'",
            [$planId]
        );
    }

    public function markActive(int $planId): void
    {
        $this->run(
            "UPDATE revision_plans SET status = 'active', updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'paused'",
            [$planId]
        );
    }

    public function markCompleted(int $planId): void
    {
        $this->run(
            "UPDATE revision_plans
                SET status = 'completed', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND status = 'paused'",
            [$planId]
        );
    }

    public function updateCurrentCycleNumber(int $planId, int $cycleNumber): void
    {
        $this->run(
            'UPDATE revision_plans
                SET current_cycle_number = ?, updated_at = UTC_TIMESTAMP()
              WHERE id = ?',
            [$cycleNumber, $planId]
        );
    }

    /**
     * Pauses active plans whose range no longer fits inside
     * [startPage..boundaryPage] (boundary shrank). Segments, cycles and
     * sessions are never touched — history stays verbatim.
     *
     * @return int rows paused
     */
    public function pauseActiveExceedingRange(int $userId, int $startPage, int $boundaryPage): int
    {
        $statement = $this->run(
            "UPDATE revision_plans
                SET status = 'paused',
                    updated_at = UTC_TIMESTAMP()
              WHERE user_id = ?
                AND status = 'active'
                AND (range_start_page < ? OR range_end_page > ?)",
            [$userId, $startPage, $boundaryPage]
        );

        return $statement->rowCount();
    }
}
