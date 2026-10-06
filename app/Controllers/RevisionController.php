<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\RevisionPlanStatus;
use App\Request;
use App\Response;
use App\Services\RevisionService;
use App\Validators\CreatePlanValidator;
use App\Validators\GenerateCycleValidator;
use App\Validators\PlanStatusValidator;
use App\Validators\SegmentStatusValidator;
use App\Validators\UpdateTargetValidator;

/**
 * Revision endpoints (المراجعة, Prompt 10). All routes sit behind
 * AuthMiddleware; every method operates on the authenticated user only.
 * Business rules live in RevisionService (conventions §9).
 */
final class RevisionController extends Controller
{
    private RevisionService $revision;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->revision = new RevisionService();
    }

    /** Lists the user's revision plans, newest first. */
    public function plans(array $params = []): Response
    {
        return $this->success([
            'plans' => $this->revision->listPlans($this->requireUser()->id()),
        ]);
    }

    /** Creates a plan over the current memorized range (201). */
    public function createPlan(array $params = []): Response
    {
        $data = $this->validate(new CreatePlanValidator());

        $result = $this->revision->createPlan(
            $this->requireUser()->id(),
            $data['target_unit'] ?? null,
            $data['daily_amount'] ?? null,
            $data['name'] ?? null,
        );

        return $this->success($result, 201);
    }

    /** Full derived view of one plan (segments, cycles, missed days). */
    public function planDetail(array $params = []): Response
    {
        $result = $this->revision->planDetail(
            $this->requireUser()->id(),
            $this->routeId($params, 'plan_id'),
        );

        return $this->success($result);
    }

    /** Changes the daily target, re-segmenting the pending tail. */
    public function updateTarget(array $params = []): Response
    {
        $data = $this->validate(new UpdateTargetValidator());

        $result = $this->revision->updateTarget(
            $this->requireUser()->id(),
            $this->routeId($params, 'plan_id'),
            (string) $data['target_unit'],
            $data['daily_amount'],
        );

        return $this->success($result);
    }

    /** Applies a plan status transition (active/paused/completed). */
    public function changeStatus(array $params = []): Response
    {
        $data = $this->validate(new PlanStatusValidator());

        $result = $this->revision->changeStatus(
            $this->requireUser()->id(),
            $this->routeId($params, 'plan_id'),
            RevisionPlanStatus::from((string) $data['status']),
        );

        return $this->success($result);
    }

    /** Generates the next cycle from the current memorized range (201). */
    public function generateCycle(array $params = []): Response
    {
        $data = $this->validate(new GenerateCycleValidator());

        $result = $this->revision->generateCycle(
            $this->requireUser()->id(),
            $this->routeId($params, 'plan_id'),
            ($data['regenerate'] ?? 0) === 1,
        );

        return $this->success($result, 201);
    }

    /** History browsing: one cycle with its segments. */
    public function cycleDetail(array $params = []): Response
    {
        $result = $this->revision->cycleDetail(
            $this->requireUser()->id(),
            $this->routeId($params, 'cycle_id'),
        );

        return $this->success($result);
    }

    /** Explicitly skips a segment (the missed-day decision). */
    public function skipSegment(array $params = []): Response
    {
        $this->validate(new SegmentStatusValidator());

        $result = $this->revision->skipSegment(
            $this->requireUser()->id(),
            $this->routeId($params, 'segment_id'),
        );

        return $this->success($result);
    }
}
