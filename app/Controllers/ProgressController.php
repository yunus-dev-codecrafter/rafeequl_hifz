<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Request;
use App\Response;
use App\Services\ProgressAnalyticsService;

/**
 * Progress analytics endpoint (Prompt 22): one read-only snapshot for
 * the dashboard's "التقدم والمتابعة" section. All routes sit behind
 * AuthMiddleware; the payload covers only the authenticated user.
 * Business rules and arithmetic live in ProgressAnalyticsService
 * (conventions §9, §14) — this class only maps HTTP to the service.
 */
final class ProgressController extends Controller
{
    private ProgressAnalyticsService $analytics;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->analytics = new ProgressAnalyticsService();
    }

    /** Read-only analytics snapshot — viewing never writes anything. */
    public function summary(array $params = []): Response
    {
        return $this->success([
            'analytics' => $this->analytics->summary($this->requireUser()->id()),
        ]);
    }
}
