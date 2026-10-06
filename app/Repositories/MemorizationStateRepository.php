<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\BoundaryChangeReason;
use App\Models\MemorizationSource;

/**
 * Reads and writes the user's memorization state and its append-only
 * history (0004_hifz).
 *
 * Every write is invoked from MemorizationProgressService inside a single
 * transaction; history rows are only ever inserted, never updated or
 * deleted (prompt: never silently modify historical records).
 */
final class MemorizationStateRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function findState(int $userId): ?array
    {
        return $this->fetch(
            'SELECT memorized_start_page, current_boundary_page, status,
                    last_boundary_changed_at, last_page_memorized_at
             FROM memorization_states
             WHERE user_id = ?',
            [$userId]
        );
    }

    /** Creates the state row and stamps the initial boundary change. */
    public function insertState(int $userId, int $startPage, int $boundaryPage): void
    {
        $this->run(
            'INSERT INTO memorization_states
                    (user_id, memorized_start_page, current_boundary_page, status,
                     last_boundary_changed_at, last_page_memorized_at, created_at, updated_at)
             VALUES (?, ?, ?, \'active\', UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$userId, $startPage, $boundaryPage]
        );
    }

    /** Moves the boundary (inclusive last-memorized page). */
    public function updateBoundary(int $userId, int $boundaryPage): void
    {
        $this->run(
            'UPDATE memorization_states
                SET current_boundary_page = ?,
                    last_boundary_changed_at = UTC_TIMESTAMP(),
                    updated_at = UTC_TIMESTAMP()
              WHERE user_id = ?',
            [$boundaryPage, $userId]
        );
    }

    /** Stamps that a page range was just recorded as memorized. */
    public function touchLastPageMemorized(int $userId): void
    {
        $this->run(
            'UPDATE memorization_states
                SET last_page_memorized_at = UTC_TIMESTAMP(),
                    updated_at = UTC_TIMESTAMP()
              WHERE user_id = ?',
            [$userId]
        );
    }

    /** Appends one row to memorization_boundary_history (previous value kept). */
    public function recordBoundaryChange(
        int $userId,
        ?int $previousBoundaryPage,
        int $newBoundaryPage,
        BoundaryChangeReason $reason,
        ?string $note = null,
    ): void {
        $this->insert(
            'INSERT INTO memorization_boundary_history
                    (user_id, previous_boundary_page, new_boundary_page, changed_at, reason, note, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP())',
            [$userId, $previousBoundaryPage, $newBoundaryPage, $reason->value, $note]
        );
    }

    /**
     * Appends one memorization_history row per page in the inclusive range.
     * Re-marking a page appends again — the table records every record event.
     *
     * @return int rows appended
     */
    public function recordMemorizedPages(int $userId, int $startPage, int $endPage, ?string $note = null): int
    {
        $appended = 0;
        for ($page = $startPage; $page <= $endPage; $page++) {
            $this->insert(
                'INSERT INTO memorization_history
                        (user_id, page_number, memorized_at, source, note, created_at)
                 VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP())',
                [$userId, $page, MemorizationSource::Manual->value, $note]
            );
            $appended++;
        }
        return $appended;
    }
}
