<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\TaskCategory;
use App\Models\TaskStatus;
use App\Repositories\TaskCompletionRepository;
use App\Repositories\TaskRepository;

/**
 * Daily productivity (المهام اليومية, Prompt 14): schedule, run and
 * history daily tasks over the seeded type vocabulary (4 Quran types +
 * personal).
 *
 * Established rules:
 * - "today" is the server's UTC date (same rule as revision scheduling);
 *   dates are plain scheduling data, never Quran data;
 * - durations and every shown percentage are computed server-side;
 * - the status machine ends at completed: pending ⇄ active, either can
 *   become skipped (and revert), completed is terminal and records the
 *   task's single append-only completion row;
 * - completed tasks stay fully editable (title/type/duration/notes) —
 *   history lives in task_completions, not in the mutable task row;
 * - productivity tables have no link into memorization/revision data:
 *   creating, editing, completing or deleting a task can never corrupt
 *   Hifz progress.
 */
final class TaskService
{
    /** Decimal places for every percentage shown to the user. */
    private const PERCENT_PRECISION = 2;

    /** History page size when the caller does not ask for a limit. */
    public const DEFAULT_HISTORY_LIMIT = 50;

    private TaskRepository $tasks;
    private TaskCompletionRepository $completions;

    public function __construct(
        ?TaskRepository $tasks = null,
        ?TaskCompletionRepository $completions = null,
    ) {
        $this->tasks = $tasks ?? new TaskRepository();
        $this->completions = $completions ?? new TaskCompletionRepository();
    }

    /** The active task vocabulary (seeded quran + general types). */
    public function types(): array
    {
        return ['types' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name_en' => (string) $row['name_en'],
            'name_ar' => (string) $row['name_ar'],
            'category' => (string) $row['category'],
            'default_duration_minutes' => (int) $row['default_duration_minutes'],
            'sort_order' => (int) $row['sort_order'],
        ], $this->tasks->activeTypes())];
    }

    /**
     * One scheduled day (default: today, server UTC) with its summary.
     *
     * @return array{date: string, tasks: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function day(int $userId, ?string $date): array
    {
        $date ??= gmdate('Y-m-d');
        $tasks = array_map(fn (array $row): array => $this->mapTask($row), $this->tasks->dayList($userId, $date));

        return [
            'date' => $date,
            'tasks' => $tasks,
            'summary' => $this->summarize($tasks),
        ];
    }

    /**
     * Task history across an inclusive date range, newest day first.
     *
     * @return array{tasks: array<int, array<string, mixed>>}
     */
    public function history(int $userId, string $from, string $to, int $limit): array
    {
        if ($from > $to) {
            throw ValidationException::withErrors([
                ['field' => 'from', 'message' => 'From must not be after To'],
            ]);
        }

        $rows = $this->tasks->history($userId, $from, $to, $limit);

        return ['tasks' => array_map(fn (array $row): array => $this->mapTask($row), $rows)];
    }

    /** One task with its completion record. @return array{task: array<string, mixed>} */
    public function detail(int $userId, int $taskId): array
    {
        return ['task' => $this->requireTask($userId, $taskId)];
    }

    /**
     * Schedules a new pending task (201). Duration falls back to the
     * type's default; general tasks require a title.
     *
     * @param array<string, mixed> $input validated create payload
     * @return array{task: array<string, mixed>}
     */
    public function create(int $userId, array $input): array
    {
        $type = $this->requireActiveType((int) $input['task_type_id']);
        $title = ($input['title'] ?? null) !== null ? (string) $input['title'] : null;

        if (TaskCategory::from($type['category'])->isGeneral() && $title === null) {
            throw ValidationException::withErrors([
                ['field' => 'title', 'message' => 'General tasks need a title'],
            ]);
        }

        $duration = ($input['duration_minutes'] ?? null) !== null
            ? (int) $input['duration_minutes']
            : (int) $type['default_duration_minutes'];
        $scheduledDate = ($input['scheduled_date'] ?? null) !== null ? (string) $input['scheduled_date'] : gmdate('Y-m-d');
        $notes = ($input['notes'] ?? null) !== null ? (string) $input['notes'] : null;

        $taskId = $this->tasks->create($userId, (int) $type['id'], $title, $scheduledDate, $duration, $notes);

        return ['task' => $this->requireTask($userId, $taskId)];
    }

    /**
     * Edits title/type/duration/notes (all optional; the scheduled date
     * is immutable). Switching to a general type requires an effective
     * title. Completed tasks stay editable.
     *
     * @param array<string, mixed> $input validated update payload
     * @return array{task: array<string, mixed>}
     */
    public function update(int $userId, int $taskId, array $input): array
    {
        $task = $this->requireTask($userId, $taskId);

        $typeId = ($input['task_type_id'] ?? null) !== null ? (int) $input['task_type_id'] : null;
        $title = ($input['title'] ?? null) !== null ? (string) $input['title'] : null;
        $duration = ($input['duration_minutes'] ?? null) !== null ? (int) $input['duration_minutes'] : null;
        $notes = ($input['notes'] ?? null) !== null ? (string) $input['notes'] : null;

        if ($typeId !== null) {
            $type = $this->requireActiveType($typeId);
            $effectiveTitle = $title ?? ($task['title'] !== null ? (string) $task['title'] : null);
            if (TaskCategory::from($type['category'])->isGeneral() && $effectiveTitle === null) {
                throw ValidationException::withErrors([
                    ['field' => 'title', 'message' => 'General tasks need a title'],
                ]);
            }
        }

        $this->tasks->update($userId, $taskId, $typeId, $title, $duration, $notes);

        return ['task' => $this->requireTask($userId, $taskId)];
    }

    /**
     * Status transition. Same state is an idempotent no-op; completed
     * tasks are terminal; entering completed stamps the task row and
     * appends the single completion record in one transaction.
     *
     * @param array<string, mixed> $input validated status payload
     * @return array{task: array<string, mixed>}
     */
    public function changeStatus(int $userId, int $taskId, TaskStatus $target, array $input): array
    {
        $task = $this->requireTask($userId, $taskId);
        $current = TaskStatus::from($task['status']);

        if ($target === $current) {
            return ['task' => $task];
        }

        if (!$current->canTransitionTo($target)) {
            throw ValidationException::withErrors([
                ['field' => 'status', 'message' => $current === TaskStatus::Completed
                    ? 'A completed task cannot be reopened'
                    : 'A skipped task must be reopened before it can be completed'],
            ]);
        }

        if ($target === TaskStatus::Completed) {
            $actual = ($input['actual_duration_seconds'] ?? null) !== null ? (int) $input['actual_duration_seconds'] : null;
            $note = ($input['note'] ?? null) !== null ? (string) $input['note'] : null;

            Database::transaction(function () use ($userId, $taskId, $actual, $note): void {
                if ($this->tasks->complete($userId, $taskId, $actual) === 0) {
                    throw ValidationException::withErrors([
                        ['field' => 'status', 'message' => 'A completed task cannot be reopened'],
                    ]);
                }
                $this->completions->create($taskId, $userId, $actual, $note);
            });
        } else {
            $this->tasks->updateStatus($userId, $taskId, $target->value);
        }

        return ['task' => $this->requireTask($userId, $taskId)];
    }

    /**
     * Explicit delete: the task and its completion history leave together
     * (FK cascade). Nothing in the Hifz domain references this table.
     *
     * @return array{task_id: int, deleted: bool}
     */
    public function delete(int $userId, int $taskId): array
    {
        $this->requireTask($userId, $taskId);
        $this->tasks->delete($userId, $taskId);

        return ['task_id' => $taskId, 'deleted' => true];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return array<string, mixed> type row (422 when unknown/inactive) */
    private function requireActiveType(int $typeId): array
    {
        $type = $this->tasks->findType($typeId);
        if ($type === null || (int) $type['is_active'] !== 1) {
            throw ValidationException::withErrors([
                ['field' => 'task_type_id', 'message' => 'Task type does not exist or is not active'],
            ]);
        }
        return $type;
    }

    /** @return array<string, mixed> mapped task */
    private function requireTask(int $userId, int $taskId): array
    {
        $row = $this->tasks->find($userId, $taskId);
        if ($row === null) {
            throw new NotFoundException('Task not found');
        }
        return $this->mapTask($row);
    }

    /** Server-owned day summary: counts, planned minutes, completion %. */
    private function summarize(array $tasks): array
    {
        $counts = ['pending' => 0, 'active' => 0, 'completed' => 0, 'skipped' => 0];
        $plannedMinutes = 0;

        foreach ($tasks as $task) {
            $counts[$task['status']]++;
            $plannedMinutes += $task['duration_minutes'];
        }

        $total = count($tasks);

        return [
            'total' => $total,
            'completed' => $counts['completed'],
            'active' => $counts['active'],
            'pending' => $counts['pending'],
            'skipped' => $counts['skipped'],
            'planned_minutes' => $plannedMinutes,
            'completion_percent' => $total > 0
                ? round($counts['completed'] / $total * 100, self::PERCENT_PRECISION)
                : 0.0,
        ];
    }

    /** Row → API shape (ids/counts cast, dates/timestamps untouched). */
    private function mapTask(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'task_type_id' => (int) $row['task_type_id'],
            'type_slug' => (string) $row['type_slug'],
            'type_name_en' => (string) $row['type_name_en'],
            'type_name_ar' => (string) $row['type_name_ar'],
            'type_category' => (string) $row['type_category'],
            'title' => $row['title'] === null ? null : (string) $row['title'],
            'scheduled_date' => (string) $row['scheduled_date'],
            'duration_minutes' => (int) $row['duration_minutes'],
            'status' => (string) $row['status'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            'actual_duration_seconds' => $row['actual_duration_seconds'] === null ? null : (int) $row['actual_duration_seconds'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'completion' => $row['completion_id'] === null ? null : [
                'id' => (int) $row['completion_id'],
                'completed_at' => (string) $row['completion_completed_at'],
                'duration_seconds' => $row['completion_duration_seconds'] === null ? null : (int) $row['completion_duration_seconds'],
                'note' => $row['completion_note'] === null ? null : (string) $row['completion_note'],
            ],
        ];
    }
}
