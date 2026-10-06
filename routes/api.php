<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\FlipCardController;
use App\Controllers\HealthController;
use App\Controllers\MemorizationController;
use App\Controllers\ProgressController;
use App\Controllers\RevisionController;
use App\Controllers\RevisionSessionController;
use App\Controllers\SettingsController;
use App\Controllers\TaskController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;

/*
 * API routes (conventions §7): /api/v1/... plural resources.
 * Row format: [METHOD, path, [ControllerClass, 'method'], [middleware...]?]
 *
 * Auth policy: login/register/password routes are public; everything else
 * is behind AuthMiddleware (identity comes only from the session cookie).
 */

return [
    // Health
    ['GET', '/api/v1/health', [HealthController::class, 'status']],

    // Authentication
    ['POST', '/api/v1/auth/register', [AuthController::class, 'register'], [RateLimitMiddleware::class]],
    ['POST', '/api/v1/auth/login', [AuthController::class, 'login']],
    ['POST', '/api/v1/auth/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]],
    ['GET', '/api/v1/auth/me', [AuthController::class, 'me'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/auth/profile', [AuthController::class, 'updateProfile'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/auth/password', [AuthController::class, 'changePassword'], [AuthMiddleware::class]],
    ['GET', '/api/v1/auth/sessions', [AuthController::class, 'sessions'], [AuthMiddleware::class]],
    ['DELETE', '/api/v1/auth/sessions/{session_id}', [AuthController::class, 'revokeSession'], [AuthMiddleware::class]],

    // Password reset (throttled: audit finding F-03)
    ['POST', '/api/v1/auth/password/forgot', [AuthController::class, 'forgotPassword'], [RateLimitMiddleware::class]],
    ['POST', '/api/v1/auth/password/reset', [AuthController::class, 'resetPassword'], [RateLimitMiddleware::class]],

    // Memorization progress (Prompt 09)
    ['GET', '/api/v1/memorization/state', [MemorizationController::class, 'state'], [AuthMiddleware::class]],
    ['POST', '/api/v1/memorization/state', [MemorizationController::class, 'establish'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/memorization/state', [MemorizationController::class, 'correctBoundary'], [AuthMiddleware::class]],
    ['POST', '/api/v1/memorization/history', [MemorizationController::class, 'markMemorized'], [AuthMiddleware::class]],
    ['GET', '/api/v1/memorization/rabt', [MemorizationController::class, 'rabt'], [AuthMiddleware::class]],

    // Revision plans, cycles & segments (Prompt 10)
    ['GET', '/api/v1/revision/plans', [RevisionController::class, 'plans'], [AuthMiddleware::class]],
    ['POST', '/api/v1/revision/plans', [RevisionController::class, 'createPlan'], [AuthMiddleware::class]],
    ['GET', '/api/v1/revision/plans/{plan_id}', [RevisionController::class, 'planDetail'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/revision/plans/{plan_id}/target', [RevisionController::class, 'updateTarget'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/revision/plans/{plan_id}/status', [RevisionController::class, 'changeStatus'], [AuthMiddleware::class]],
    ['POST', '/api/v1/revision/plans/{plan_id}/cycles', [RevisionController::class, 'generateCycle'], [AuthMiddleware::class]],
    ['GET', '/api/v1/revision/cycles/{cycle_id}', [RevisionController::class, 'cycleDetail'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/revision/segments/{segment_id}', [RevisionController::class, 'skipSegment'], [AuthMiddleware::class]],

    // Revision sessions (Prompt 10)
    ['GET', '/api/v1/revision/sessions', [RevisionSessionController::class, 'index'], [AuthMiddleware::class]],
    ['POST', '/api/v1/revision/sessions', [RevisionSessionController::class, 'start'], [AuthMiddleware::class]],
    ['POST', '/api/v1/revision/sessions/{session_id}/progress', [RevisionSessionController::class, 'progress'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/revision/sessions/{session_id}', [RevisionSessionController::class, 'finish'], [AuthMiddleware::class]],

    // Flip cards (Prompt 13) — static paths before {card_id} (first match wins).
    ['GET', '/api/v1/flip-cards/categories', [FlipCardController::class, 'categories'], [AuthMiddleware::class]],
    ['GET', '/api/v1/flip-cards/queue', [FlipCardController::class, 'queue'], [AuthMiddleware::class]],
    ['GET', '/api/v1/flip-cards', [FlipCardController::class, 'index'], [AuthMiddleware::class]],
    ['POST', '/api/v1/flip-cards', [FlipCardController::class, 'create'], [AuthMiddleware::class]],
    ['GET', '/api/v1/flip-cards/{card_id}', [FlipCardController::class, 'detail'], [AuthMiddleware::class]],
    ['POST', '/api/v1/flip-cards/{card_id}/review', [FlipCardController::class, 'review'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/flip-cards/{card_id}/status', [FlipCardController::class, 'changeStatus'], [AuthMiddleware::class]],
    ['DELETE', '/api/v1/flip-cards/{card_id}', [FlipCardController::class, 'delete'], [AuthMiddleware::class]],

    // Daily tasks (Prompt 14) — static paths before {task_id} (first match wins).
    ['GET', '/api/v1/task-types', [TaskController::class, 'types'], [AuthMiddleware::class]],
    ['GET', '/api/v1/tasks/history', [TaskController::class, 'history'], [AuthMiddleware::class]],
    ['GET', '/api/v1/tasks', [TaskController::class, 'index'], [AuthMiddleware::class]],
    ['POST', '/api/v1/tasks', [TaskController::class, 'create'], [AuthMiddleware::class]],
    ['GET', '/api/v1/tasks/{task_id}', [TaskController::class, 'detail'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/tasks/{task_id}', [TaskController::class, 'update'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/tasks/{task_id}/status', [TaskController::class, 'changeStatus'], [AuthMiddleware::class]],
    ['DELETE', '/api/v1/tasks/{task_id}', [TaskController::class, 'delete'], [AuthMiddleware::class]],

    // Progress analytics (Prompt 22)
    ['GET', '/api/v1/progress/summary', [ProgressController::class, 'summary'], [AuthMiddleware::class]],

    // User preferences (Prompt 18)
    ['GET', '/api/v1/settings', [SettingsController::class, 'show'], [AuthMiddleware::class]],
    ['PUT', '/api/v1/settings', [SettingsController::class, 'update'], [AuthMiddleware::class]],

    // Privacy / data controls (Prompt 18)
    ['GET', '/api/v1/account/export', [AccountController::class, 'export'], [AuthMiddleware::class]],
    ['DELETE', '/api/v1/account', [AccountController::class, 'delete'], [AuthMiddleware::class]],
];
