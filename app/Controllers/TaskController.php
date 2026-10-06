<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\TaskStatus;
use App\Request;
use App\Response;
use App\Services\TaskService;
use App\Validators\CreateTaskValidator;
use App\Validators\TaskDayQueryValidator;
use App\Validators\TaskHistoryValidator;
use App\Validators\TaskStatusValidator;
use App\Validators\UpdateTaskValidator;

/**
 * Daily task endpoints (المهام اليومية, Prompt 14). All routes sit
 * behind AuthMiddleware; every method operates on the authenticated
 * user only. Business rules live in TaskService (conventions §9).
 */
final class TaskController extends Controller
{
    private TaskService $tasks;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->tasks = new TaskService();
    }

    /** The active task vocabulary (seeded quran + general types). */
    public function types(array $params = []): Response
    {
        return $this->success($this->tasks->types());
    }

    /** One scheduled day (default today) with its server-computed summary. */
    public function index(array $params = []): Response
    {
        $data = $this->validate(new TaskDayQueryValidator(), ['date' => $this->query('date')]);

        return $this->success($this->tasks->day(
            $this->requireUser()->id(),
            $data['date'] !== null ? (string) $data['date'] : null,
        ));
    }

    /** Task history across an inclusive date range, newest day first. */
    public function history(array $params = []): Response
    {
        $data = $this->validate(new TaskHistoryValidator(), [
            'from' => $this->query('from'),
            'to' => $this->query('to'),
            'limit' => $this->query('limit'),
        ]);

        return $this->success($this->tasks->history(
            $this->requireUser()->id(),
            (string) $data['from'],
            (string) $data['to'],
            $data['limit'] !== null ? (int) $data['limit'] : TaskService::DEFAULT_HISTORY_LIMIT,
        ));
    }

    /** Schedules a new pending task (201). */
    public function create(array $params = []): Response
    {
        $data = $this->validate(new CreateTaskValidator());

        return $this->success($this->tasks->create($this->requireUser()->id(), $data), 201);
    }

    /** One task with its completion record. */
    public function detail(array $params = []): Response
    {
        return $this->success($this->tasks->detail(
            $this->requireUser()->id(),
            $this->routeId($params, 'task_id'),
        ));
    }

    /** Edits title/type/duration/notes (date immutable). */
    public function update(array $params = []): Response
    {
        $data = $this->validate(new UpdateTaskValidator());

        return $this->success($this->tasks->update(
            $this->requireUser()->id(),
            $this->routeId($params, 'task_id'),
            $data,
        ));
    }

    /** Applies a status transition; completing records history (200). */
    public function changeStatus(array $params = []): Response
    {
        $data = $this->validate(new TaskStatusValidator());

        return $this->success($this->tasks->changeStatus(
            $this->requireUser()->id(),
            $this->routeId($params, 'task_id'),
            TaskStatus::from((string) $data['status']),
            $data,
        ));
    }

    /** Explicitly deletes a task (its completion history goes with it). */
    public function delete(array $params = []): Response
    {
        return $this->success($this->tasks->delete(
            $this->requireUser()->id(),
            $this->routeId($params, 'task_id'),
        ));
    }
}
