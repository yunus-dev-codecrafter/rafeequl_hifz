<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Reads/writes for daily_tasks and the seeded task_types vocabulary
 * (0008): the user's productivity tasks. Completion history lives in
 * TaskCompletionRepository. These tables have no FK into the Hifz
 * domain — editing/deleting a task can never touch memorization data.
 */
final class TaskRepository extends Repository
{
    /** Shared select: task + type + its (at most one) completion record. */
    private const SELECT = 'SELECT t.id, t.user_id, t.task_type_id, t.title, t.scheduled_date,
               t.duration_minutes, t.status, t.completed_at, t.actual_duration_seconds,
               t.notes, t.created_at, t.updated_at,
               k.slug AS type_slug, k.name_en AS type_name_en, k.name_ar AS type_name_ar,
               k.category AS type_category, k.default_duration_minutes,
               c.id AS completion_id, c.completed_at AS completion_completed_at,
               c.duration_seconds AS completion_duration_seconds, c.note AS completion_note
          FROM daily_tasks t
          JOIN task_types k ON k.id = t.task_type_id
          LEFT JOIN task_completions c ON c.task_id = t.id';

    /** Type row by id (validation + defaults), whatever its is_active flag. */
    public function findType(int $typeId): ?array
    {
        return $this->fetch(
            'SELECT id, slug, name_en, name_ar, category, default_duration_minutes, sort_order, is_active
               FROM task_types
              WHERE id = ?',
            [$typeId]
        );
    }

    /** The active task vocabulary (seeded quran + general types). */
    public function activeTypes(): array
    {
        return $this->fetchAll(
            'SELECT id, slug, name_en, name_ar, category, default_duration_minutes, sort_order
               FROM task_types
              WHERE is_active = 1
              ORDER BY sort_order ASC, id ASC'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $userId, int $taskId): ?array
    {
        return $this->fetch(
            self::SELECT . ' WHERE t.id = ? AND t.user_id = ?',
            [$taskId, $userId]
        );
    }

    /** One scheduled day, task order. @return array<int, array<string, mixed>> */
    public function dayList(int $userId, string $date): array
    {
        return $this->fetchAll(
            self::SELECT . ' WHERE t.user_id = ? AND t.scheduled_date = ? ORDER BY t.id ASC',
            [$userId, $date]
        );
    }

    /** Inclusive date range, newest day first. @return array<int, array<string, mixed>> */
    public function history(int $userId, string $from, string $to, int $limit): array
    {
        return $this->fetchAll(
            self::SELECT . ' WHERE t.user_id = ? AND t.scheduled_date BETWEEN ? AND ?
              ORDER BY t.scheduled_date DESC, t.id DESC LIMIT ?',
            [$userId, $from, $to, $limit]
        );
    }

    /** Inserts a pending task. Returns the new task id. */
    public function create(
        int $userId,
        int $typeId,
        ?string $title,
        string $scheduledDate,
        int $durationMinutes,
        ?string $notes,
    ): int {
        return $this->insert(
            'INSERT INTO daily_tasks
                    (user_id, task_type_id, title, scheduled_date, duration_minutes, status,
                     completed_at, actual_duration_seconds, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'pending\', NULL, NULL, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$userId, $typeId, $title, $scheduledDate, $durationMinutes, $notes]
        );
    }

    /**
     * Edits task columns; null arguments keep the current value and the
     * scheduled date is never touched. Returns the affected row count.
     */
    public function update(
        int $userId,
        int $taskId,
        ?int $typeId,
        ?string $title,
        ?int $durationMinutes,
        ?string $notes,
    ): int {
        $sets = [];
        $params = [];
        if ($typeId !== null) {
            $sets[] = 'task_type_id = ?';
            $params[] = $typeId;
        }
        if ($title !== null) {
            $sets[] = 'title = ?';
            $params[] = $title;
        }
        if ($durationMinutes !== null) {
            $sets[] = 'duration_minutes = ?';
            $params[] = $durationMinutes;
        }
        if ($notes !== null) {
            $sets[] = 'notes = ?';
            $params[] = $notes;
        }
        if ($sets === []) {
            return 0;
        }

        $sets[] = 'updated_at = UTC_TIMESTAMP()';
        $params[] = $taskId;
        $params[] = $userId;

        $statement = $this->run(
            'UPDATE daily_tasks SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
            $params
        );
        return $statement->rowCount();
    }

    /**
     * Non-completion transition; the WHERE guard keeps completed tasks
     * terminal even if a caller slips past the service. Returns rows.
     */
    public function updateStatus(int $userId, int $taskId, string $status): int
    {
        $statement = $this->run(
            "UPDATE daily_tasks
                SET status = ?, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND user_id = ? AND status <> 'completed'",
            [$status, $taskId, $userId]
        );
        return $statement->rowCount();
    }

    /**
     * Completion transition: stamps completed_at + actual duration in the
     * same write (single UTC timestamp shared via UTC_TIMESTAMP()). The
     * WHERE guard makes double completion impossible. Returns rows.
     */
    public function complete(int $userId, int $taskId, ?int $actualDurationSeconds): int
    {
        $statement = $this->run(
            "UPDATE daily_tasks
                SET status = 'completed', completed_at = UTC_TIMESTAMP(),
                    actual_duration_seconds = ?, updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND user_id = ? AND status <> 'completed'",
            [$actualDurationSeconds, $taskId, $userId]
        );
        return $statement->rowCount();
    }

    /** Explicit delete (completion rows cascade). Returns affected rows. */
    public function delete(int $userId, int $taskId): int
    {
        $statement = $this->run(
            'DELETE FROM daily_tasks WHERE id = ? AND user_id = ?',
            [$taskId, $userId]
        );
        return $statement->rowCount();
    }
}
