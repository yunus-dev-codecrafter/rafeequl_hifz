<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Models\RevisionSessionStatus;
use App\Request;
use App\Response;
use App\Services\RevisionSessionService;
use App\Validators\FinishSessionValidator;
use App\Validators\SessionProgressValidator;
use App\Validators\StartSessionValidator;

/**
 * Revision session endpoints (المراجعة, Prompt 10). All routes sit behind
 * AuthMiddleware; every method operates on the authenticated user only.
 * Business rules live in RevisionSessionService (conventions §9).
 */
final class RevisionSessionController extends Controller
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    private RevisionSessionService $sessions;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->sessions = new RevisionSessionService();
    }

    /** Session history, newest first (?limit=1..100). */
    public function index(array $params = []): Response
    {
        return $this->success(
            $this->sessions->listSessions($this->requireUser()->id(), $this->limit())
        );
    }

    /** Opens an attempt on a segment, or resumes a prior one (201). */
    public function start(array $params = []): Response
    {
        $data = $this->validate(new StartSessionValidator());

        $result = $this->sessions->start(
            $this->requireUser()->id(),
            (int) $data['segment_id'],
            isset($data['resumes_session_id']) && $data['resumes_session_id'] !== null
                ? (int) $data['resumes_session_id']
                : null,
        );

        return $this->success($result, 201);
    }

    /** Reports the furthest page reached in the running attempt. */
    public function progress(array $params = []): Response
    {
        $data = $this->validate(new SessionProgressValidator());

        $result = $this->sessions->progress(
            $this->requireUser()->id(),
            $this->routeId($params, 'session_id'),
            (int) $data['last_page_reached'],
        );

        return $this->success($result);
    }

    /** Finalizes the attempt once (completed/partial/interrupted). */
    public function finish(array $params = []): Response
    {
        $data = $this->validate(new FinishSessionValidator());

        $result = $this->sessions->finish(
            $this->requireUser()->id(),
            $this->routeId($params, 'session_id'),
            RevisionSessionStatus::from((string) $data['status']),
            $data['last_page_reached'] ?? null,
            $data['interruption_reason'] ?? null,
            $data['notes'] ?? null,
        );

        return $this->success($result);
    }

    /** Clamped history limit (422 on nonsense query input). */
    private function limit(): int
    {
        $raw = $this->query('limit', self::DEFAULT_LIMIT);
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }

        if (!is_numeric($raw) || (float) $raw !== (float) (int) $raw || (int) $raw < 1) {
            throw ValidationException::withErrors([
                ['field' => 'limit', 'message' => 'Limit must be a positive integer'],
            ]);
        }

        return min((int) $raw, self::MAX_LIMIT);
    }
}
