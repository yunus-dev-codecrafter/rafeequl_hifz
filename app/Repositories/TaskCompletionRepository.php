<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Writes for task_completions (0008): append-only completion history,
 * one row per task (UNIQUE). Rows survive later task edits and are
 * removed only with the task itself (FK cascade).
 */
final class TaskCompletionRepository extends Repository
{
    /** Records the completion event. Returns the new completion id. */
    public function create(int $taskId, int $userId, ?int $durationSeconds, ?string $note): int
    {
        return $this->insert(
            'INSERT INTO task_completions
                    (task_id, user_id, completed_at, duration_seconds, note, created_at)
             VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP())',
            [$taskId, $userId, $durationSeconds, $note]
        );
    }
}
