<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Request;
use App\Response;
use App\Services\HifzCalculationService;
use App\Services\MemorizationProgressService;
use App\Validators\CorrectBoundaryValidator;
use App\Validators\EstablishStateValidator;
use App\Validators\MarkMemorizedValidator;

/**
 * Memorization progress endpoints (Prompt 09) + the rolling Rabt window
 * (Prompt 12). All routes sit behind AuthMiddleware; every method operates
 * on the authenticated user only. Business rules live in the services
 * (conventions §9).
 */
final class MemorizationController extends Controller
{
    private MemorizationProgressService $progress;
    private HifzCalculationService $hifz;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->progress = new MemorizationProgressService();
        $this->hifz = new HifzCalculationService();
    }

    /** Read-only progress snapshot — viewing never marks anything. */
    public function state(array $params = []): Response
    {
        return $this->success(['state' => $this->progress->state($this->requireUser()->id())]);
    }

    /** Establishes the memorized range + boundary (once per user). */
    public function establish(array $params = []): Response
    {
        $data = $this->validate(new EstablishStateValidator());

        $result = $this->progress->establish(
            $this->requireUser()->id(),
            (int) $data['memorized_start_page'],
            (int) $data['current_boundary_page'],
            isset($data['note']) && is_string($data['note']) && $data['note'] !== ''
                ? $data['note']
                : null
        );

        return $this->success($result, 201);
    }

    /** Confirmed correction of an incorrect boundary. */
    public function correctBoundary(array $params = []): Response
    {
        $data = $this->validate(new CorrectBoundaryValidator());

        $result = $this->progress->correctBoundary(
            $this->requireUser()->id(),
            (int) $data['current_boundary_page'],
            ($data['confirm'] ?? 0) === 1,
            isset($data['note']) && is_string($data['note']) && $data['note'] !== ''
                ? $data['note']
                : null
        );

        return $this->success($result);
    }

    /** Explicitly marks a page range as memorized (append-only history). */
    public function markMemorized(array $params = []): Response
    {
        $data = $this->validate(new MarkMemorizedValidator());

        $result = $this->progress->markMemorized(
            $this->requireUser()->id(),
            (int) $data['start_page'],
            (int) $data['end_page'],
            isset($data['note']) && is_string($data['note']) && $data['note'] !== ''
                ? $data['note']
                : null
        );

        return $this->success($result, 201);
    }

    /** Rolling Rabt (ربط) window over the newest memorized pages (Prompt 12). */
    public function rabt(array $params = []): Response
    {
        return $this->success(['rabt' => $this->hifz->rabtRange($this->requireUser()->id())]);
    }
}
