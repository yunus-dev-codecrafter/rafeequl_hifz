<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Exceptions\AppException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\RevisionCycleStatus;
use App\Models\RevisionPlanStatus;
use App\Models\RevisionSegmentStatus;
use App\Models\RevisionSessionStatus;
use App\Repositories\RevisionSegmentRepository;
use App\Repositories\RevisionSessionRepository;

/**
 * Revision sessions (المراجعة, Prompt 10): start/resume an attempt on a
 * segment, report live progress and finalize it.
 *
 * Established rules:
 * - at most one in-progress session per user; it is the "open" state the
 *   plan pause/resume flow keys on;
 * - progress is sequential inside the segment and server-computed
 *   (pages_completed = last_page_reached - start + 1) — the client only
 *   reports the last page it reached;
 * - completion forces the segment end (server authoritative), partial and
 *   interrupted keep the reported progress verbatim;
 * - finished rows are history (ended_at IS NULL guards every write);
 * - cycle settlement lives in RevisionService to keep dependencies
 *   one-directional (this service → RevisionService, never back).
 */
final class RevisionSessionService
{
    private RevisionSessionRepository $sessions;
    private RevisionSegmentRepository $segments;
    private RevisionService $revision;

    public function __construct(
        ?RevisionSessionRepository $sessions = null,
        ?RevisionSegmentRepository $segments = null,
        ?RevisionService $revision = null,
    ) {
        $this->sessions = $sessions ?? new RevisionSessionRepository();
        $this->segments = $segments ?? new RevisionSegmentRepository();
        $this->revision = $revision ?? new RevisionService();
    }

    /** @return array{sessions: array<int, array<string, mixed>>} */
    public function listSessions(int $userId, int $limit): array
    {
        $rows = $this->sessions->findByUser($userId, $limit);

        return [
            'sessions' => array_map(function (array $row): array {
                $session = $this->revision->mapSessionWithDisplay($row, $row);
                if (isset($row['segment_number'])) {
                    $session['segment'] = [
                        'segment_number' => (int) $row['segment_number'],
                        'start_page' => (int) $row['start_page'],
                        'end_page' => (int) $row['end_page'],
                        'scheduled_date' => (string) $row['scheduled_date'],
                    ];
                }
                return $session;
            }, $rows),
        ];
    }

    /**
     * Opens an attempt on a segment, or resumes a finished
     * interrupted/partial attempt of the same segment (inheriting its
     * progress).
     *
     * @return array{session: array<string, mixed>}
     */
    public function start(int $userId, int $segmentId, ?int $resumesSessionId): array
    {
        $open = $this->sessions->findInProgress($userId);
        if ($open !== null) {
            throw ValidationException::withErrors([
                ['field' => 'session', 'message' => 'Finish the open revision session first'],
            ]);
        }

        $context = $this->segments->findWithContext($userId, $segmentId);
        if ($context === null) {
            throw new NotFoundException('Revision segment not found');
        }

        if (RevisionPlanStatus::from($context['plan_status']) !== RevisionPlanStatus::Active) {
            throw ValidationException::withErrors([
                ['field' => 'plan', 'message' => 'Resume the revision plan before working a segment'],
            ]);
        }

        $cycleStatus = RevisionCycleStatus::from($context['cycle_status']);
        if ($cycleStatus === RevisionCycleStatus::Superseded
            || $cycleStatus === RevisionCycleStatus::Completed
        ) {
            throw ValidationException::withErrors([
                ['field' => 'cycle', 'message' => 'This cycle is no longer the current pass'],
            ]);
        }

        $segmentStatus = RevisionSegmentStatus::from($context['status']);
        if (!in_array($segmentStatus, [RevisionSegmentStatus::Pending, RevisionSegmentStatus::Active], true)) {
            throw ValidationException::withErrors([
                ['field' => 'segment', 'message' => 'This segment is already finished'],
            ]);
        }

        $totalPages = (int) $context['page_count'];
        $pagesCompleted = 0;
        $lastPageReached = null;

        if ($resumesSessionId !== null) {
            $prior = $this->sessions->find($userId, $resumesSessionId);
            if ($prior === null
                || $prior['ended_at'] === null
                || !RevisionSessionStatus::from($prior['status'])->canResume()
                || (int) $prior['segment_id'] !== $segmentId
            ) {
                throw ValidationException::withErrors([
                    ['field' => 'resumes_session_id', 'message' => 'Only a finished interrupted/partial session of this segment can be resumed'],
                ]);
            }

            $pagesCompleted = (int) $prior['pages_completed'];
            $lastPageReached = $prior['last_page_reached'] === null
                ? null
                : (int) $prior['last_page_reached'];
        }

        $cycleId = (int) $context['cycle_id'];
        Database::transaction(function () use (
            $segmentId, $userId, $totalPages, $pagesCompleted, $lastPageReached,
            $resumesSessionId, $cycleId
        ): void {
            $sessionId = $this->sessions->create(
                $segmentId,
                $userId,
                $totalPages,
                $pagesCompleted,
                $lastPageReached,
                $resumesSessionId
            );

            $this->revision->activateCycle($cycleId);
            $this->segments->markActive($segmentId);
        });

        return ['session' => $this->lastOpenedSession($userId, $context)];
    }

    /**
     * Reports the furthest page reached in the running attempt. The
     * server derives pages_completed and enforces order and bounds.
     *
     * @return array{session: array<string, mixed>}
     */
    public function progress(int $userId, int $sessionId, int $lastPageReached): array
    {
        $session = $this->requireOpenSession($userId, $sessionId);
        $context = $this->requireContext($userId, (int) $session['segment_id']);

        $start = (int) $context['start_page'];
        $end = (int) $context['end_page'];
        $current = $session['last_page_reached'] === null ? null : (int) $session['last_page_reached'];

        if ($lastPageReached < $start || $lastPageReached > $end) {
            throw ValidationException::withErrors([
                [
                    'field' => 'last_page_reached',
                    'message' => 'Progress must stay inside segment pages ' . $start . '-' . $end,
                ],
            ]);
        }

        if ($current !== null && $lastPageReached < $current) {
            throw ValidationException::withErrors([
                ['field' => 'last_page_reached', 'message' => 'Progress cannot move backwards inside a segment'],
            ]);
        }

        $this->sessions->updateProgress(
            $sessionId,
            $lastPageReached - $start + 1,
            $lastPageReached
        );

        return ['session' => $this->revision->mapSessionWithDisplay(
            $this->requireSession($userId, $sessionId),
            $context
        )];
    }

    /**
     * Finalizes the attempt once. Completed forces the segment end and
     * settles the cycle; partial/interrupted keep progress verbatim.
     *
     * @return array{session: array<string, mixed>, segment: array<string, mixed>, cycle_completed: bool}
     */
    public function finish(
        int $userId,
        int $sessionId,
        RevisionSessionStatus $status,
        ?int $lastPageReached,
        ?string $interruptionReason,
        ?string $notes,
    ): array {
        $session = $this->requireOpenSession($userId, $sessionId);
        $context = $this->requireContext($userId, (int) $session['segment_id']);

        $start = (int) $context['start_page'];
        $end = (int) $context['end_page'];
        $totalPages = (int) $context['page_count'];
        $current = $session['last_page_reached'] === null ? null : (int) $session['last_page_reached'];

        if ($status === RevisionSessionStatus::Completed) {
            $reached = $end;
            $completed = $totalPages;
        } else {
            $reached = $lastPageReached ?? $current;

            if ($reached !== null && ($reached < $start || $reached > $end)) {
                throw ValidationException::withErrors([
                    [
                        'field' => 'last_page_reached',
                        'message' => 'Progress must stay inside segment pages ' . $start . '-' . $end,
                    ],
                ]);
            }

            if ($reached !== null && $current !== null && $reached < $current) {
                throw ValidationException::withErrors([
                    ['field' => 'last_page_reached', 'message' => 'Progress cannot move backwards inside a segment'],
                ]);
            }

            $completed = $reached === null ? 0 : $reached - $start + 1;
        }

        $segmentId = (int) $context['id'];
        $cycleId = (int) $context['cycle_id'];

        $cycleCompleted = (bool) Database::transaction(function () use (
            $sessionId, $userId, $status, $reached, $completed, $interruptionReason, $notes,
            $segmentId, $cycleId
        ): bool {
            $updated = $this->sessions->finish(
                $sessionId,
                $status->value,
                $completed,
                $reached,
                $interruptionReason,
                $notes
            );

            if ($updated === 0) {
                throw ValidationException::withErrors([
                    ['field' => 'status', 'message' => 'This session is already finished'],
                ]);
            }

            if ($status === RevisionSessionStatus::Completed) {
                $this->segments->markCompleted($segmentId);
                return $this->revision->settleCycleIfComplete($cycleId);
            }

            return false;
        });

        $finishedContext = $this->requireContext($userId, $segmentId);

        return [
            'session' => $this->revision->mapSessionWithDisplay(
                $this->requireSession($userId, $sessionId),
                $finishedContext
            ),
            'segment' => $this->revision->mapSegment($finishedContext),
            'cycle_completed' => $cycleCompleted,
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function requireOpenSession(int $userId, int $sessionId): array
    {
        $session = $this->requireSession($userId, $sessionId);
        if ($session['ended_at'] !== null) {
            throw ValidationException::withErrors([
                ['field' => 'status', 'message' => 'This session is already finished'],
            ]);
        }
        return $session;
    }

    /** @return array<string, mixed> */
    private function requireSession(int $userId, int $sessionId): array
    {
        $session = $this->sessions->find($userId, $sessionId);
        if ($session === null) {
            throw new NotFoundException('Revision session not found');
        }
        return $session;
    }

    /** @return array<string, mixed> */
    private function requireContext(int $userId, int $segmentId): array
    {
        $context = $this->segments->findWithContext($userId, $segmentId);
        if ($context === null) {
            throw new NotFoundException('Revision segment not found');
        }
        return $context;
    }

    /** The user's just-opened attempt (fresh read after the insert). */
    private function lastOpenedSession(int $userId, array $segment): array
    {
        $session = $this->sessions->findInProgress($userId);
        if ($session === null) {
            throw new AppException('Revision session disappeared after creation');
        }
        return $this->revision->mapSessionWithDisplay($session, $segment);
    }
}
