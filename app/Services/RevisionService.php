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
use App\Models\RevisionTargetUnit;
use App\Repositories\RevisionCycleRepository;
use App\Repositories\RevisionPlanRepository;
use App\Repositories\RevisionSegmentRepository;
use App\Repositories\RevisionSessionRepository;
use App\Repositories\SettingsRepository;

/**
 * Long-term Quran revision (المراجعة, Prompt 10): plans, cycles, daily
 * segments and their state machine.
 *
 * Established rules:
 * - a cycle's segments are generated from the CURRENT memorized range and
 *   never extend past it (HifzCalculationService + RevisionTargetService —
 *   no page range is ever hard-coded);
 * - cycles snapshot the boundary at generation time; the next cycle uses
 *   today's boundary, so memorization growth is picked up automatically;
 * - old cycles are superseded, never rewritten; sessions stay attached;
 * - missed days are derived (pending + scheduled_date in the past), never
 *   fabricated — complete late or skip explicitly;
 * - every plan mutation returns a freshly derived view (percentages and
 *   missed counts are read-time calculations).
 */
final class RevisionService
{
    /** Mirrors user_settings.daily_revision_amount default (0003). */
    private const DEFAULT_DAILY_AMOUNT = 1.0;

    private HifzCalculationService $hifz;
    private RevisionTargetService $targets;
    private RevisionPlanRepository $plans;
    private RevisionCycleRepository $cycles;
    private RevisionSegmentRepository $segments;
    private RevisionSessionRepository $sessions;
    private SettingsRepository $settings;

    public function __construct(
        ?HifzCalculationService $hifz = null,
        ?RevisionTargetService $targets = null,
        ?RevisionPlanRepository $plans = null,
        ?RevisionCycleRepository $cycles = null,
        ?RevisionSegmentRepository $segments = null,
        ?RevisionSessionRepository $sessions = null,
        ?SettingsRepository $settings = null,
    ) {
        $this->hifz = $hifz ?? new HifzCalculationService();
        $this->targets = $targets ?? new RevisionTargetService();
        $this->plans = $plans ?? new RevisionPlanRepository();
        $this->cycles = $cycles ?? new RevisionCycleRepository();
        $this->segments = $segments ?? new RevisionSegmentRepository();
        $this->sessions = $sessions ?? new RevisionSessionRepository();
        $this->settings = $settings ?? new SettingsRepository();
    }

    // ------------------------------------------------------------------
    // Plans
    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function listPlans(int $userId): array
    {
        return array_map($this->mapPlan(...), $this->plans->findByUser($userId));
    }

    /**
     * Creates a plan over the CURRENT memorized range with the first cycle
     * already segmented. Omitted target fields fall back to the user's
     * saved defaults (user_settings).
     *
     * @return array<string, mixed> plan detail
     */
    public function createPlan(
        int $userId,
        ?string $targetUnit,
        int|float|null $dailyAmount,
        ?string $name,
    ): array {
        if ($this->plans->countUnfinishedByUser($userId) > 0) {
            throw ValidationException::withErrors([
                ['field' => 'plan', 'message' => 'Another revision plan is already in progress'],
            ]);
        }

        $range = $this->hifz->memorizedRange($userId);
        [$unit, $amount] = $this->resolveTarget($userId, $targetUnit, $dailyAmount);

        $startPage = $range['memorized_start_page'];
        $boundary = $range['current_boundary_page'];
        $segments = $this->targets->segmentRange($startPage, $boundary, $unit, $amount);

        Database::transaction(function () use (
            $userId, $unit, $amount, $name, $startPage, $boundary, $segments
        ): void {
            $planId = $this->plans->create(
                $userId,
                $name,
                $unit->value,
                (float) $amount,
                $startPage,
                $boundary,
                $boundary
            );

            $cycleId = $this->cycles->create(
                $planId,
                $userId,
                1,
                $startPage,
                $boundary,
                $boundary,
                count($segments)
            );

            $this->insertSegments($cycleId, $userId, $segments, 0);
        });

        return $this->detailForNewestPlan($userId);
    }

    /** Full, freshly derived view of one plan. */
    public function planDetail(int $userId, int $planId): array
    {
        $plan = $this->requirePlan($userId, $planId);
        return $this->buildDetail($userId, $plan);
    }

    /**
     * Changes the daily target. The already-finished part of the current
     * cycle is preserved; only the untouched pending tail is re-segmented
     * with the new target (from today onward).
     *
     * @return array<string, mixed> plan detail + segments_regenerated
     */
    public function updateTarget(
        int $userId,
        int $planId,
        string $targetUnit,
        int|float $dailyAmount,
    ): array {
        $plan = $this->requirePlan($userId, $planId);
        if (RevisionPlanStatus::from($plan['status']) === RevisionPlanStatus::Completed) {
            throw ValidationException::withErrors([
                ['field' => 'status', 'message' => 'A completed plan keeps its recorded target'],
            ]);
        }

        $unit = $this->unitFrom($targetUnit, 'target_unit');
        $amount = (float) $dailyAmount;

        $regenerated = (int) Database::transaction(
            function () use ($plan, $unit, $amount, $userId): int {
                $cycle = $this->cycles->findLatestByPlan((int) $plan['id']);
                if ($cycle === null) {
                    throw new AppException('Revision plan has no cycle');
                }

                $regenerated = 0;
                $cycleStatus = RevisionCycleStatus::from($cycle['status']);
                if ($cycleStatus === RevisionCycleStatus::Pending
                    || $cycleStatus === RevisionCycleStatus::Active
                ) {
                    $regenerated = $this->regeneratePendingTail($cycle, $userId, $unit, $amount);
                }

                $this->plans->updateTarget((int) $plan['id'], $unit->value, $amount);

                return $regenerated;
            }
        );

        $fresh = $this->requirePlan($userId, $planId);
        return $this->buildDetail($userId, $fresh) + ['segments_regenerated' => $regenerated];
    }

    /**
     * Applies a status transition. The documented machine is
     * active ⇄ paused → completed; resuming also verifies the current
     * cycle still fits the memorized range.
     *
     * @return array<string, mixed> plan detail
     */
    public function changeStatus(int $userId, int $planId, RevisionPlanStatus $status): array
    {
        $plan = $this->requirePlan($userId, $planId);
        $current = RevisionPlanStatus::from($plan['status']);

        if ($current === $status) {
            return $this->buildDetail($userId, $plan);
        }

        $this->assertTransition($current, $status);

        if ($status === RevisionPlanStatus::Paused || $status === RevisionPlanStatus::Completed) {
            if ($this->sessions->findInProgress($userId) !== null) {
                throw ValidationException::withErrors([
                    ['field' => 'session', 'message' => 'Finish the open revision session first'],
                ]);
            }
        }

        if ($status === RevisionPlanStatus::Active) {
            $this->assertCurrentCycleFitsMemorizedRange($userId, (int) $plan['id']);
        }

        Database::transaction(function () use ($plan, $status): void {
            match ($status) {
                RevisionPlanStatus::Active => $this->plans->markActive((int) $plan['id']),
                RevisionPlanStatus::Paused => $this->plans->markPaused((int) $plan['id']),
                RevisionPlanStatus::Completed => $this->plans->markCompleted((int) $plan['id']),
            };
        });

        $fresh = $this->requirePlan($userId, $planId);
        return $this->buildDetail($userId, $fresh);
    }

    /**
     * Generates the next pass from the CURRENT memorized range. Requires a
     * completed cycle, or regenerate=true to supersede an unfinished one
     * (its segments and sessions are kept as history).
     *
     * @return array<string, mixed> plan detail + superseded_cycle_id
     */
    public function generateCycle(int $userId, int $planId, bool $regenerate): array
    {
        $plan = $this->requirePlan($userId, $planId);
        if (RevisionPlanStatus::from($plan['status']) === RevisionPlanStatus::Completed) {
            throw ValidationException::withErrors([
                ['field' => 'status', 'message' => 'A completed plan does not generate new cycles'],
            ]);
        }

        $current = $this->cycles->findLatestByPlan($planId);
        if ($current === null) {
            throw new AppException('Revision plan has no cycle');
        }

        $currentStatus = RevisionCycleStatus::from($current['status']);
        $supersededCycleId = null;

        if ($currentStatus === RevisionCycleStatus::Completed) {
            // ready for the next pass
        } elseif ($regenerate
            && ($currentStatus === RevisionCycleStatus::Pending || $currentStatus === RevisionCycleStatus::Active)
        ) {
            $supersededCycleId = (int) $current['id'];
        } else {
            throw ValidationException::withErrors([
                [
                    'field' => 'cycle',
                    'message' => 'Finish the current cycle first, or regenerate it to replace it',
                ],
            ]);
        }

        $range = $this->hifz->memorizedRange($userId);
        $startPage = $range['memorized_start_page'];
        $boundary = $range['current_boundary_page'];
        $unit = $this->unitFrom((string) $plan['target_unit'], 'target_unit');
        $amount = (float) $plan['daily_amount'];
        $segments = $this->targets->segmentRange($startPage, $boundary, $unit, $amount);
        $nextNumber = (int) $current['cycle_number'] + 1;

        Database::transaction(function () use (
            $plan, $supersededCycleId, $userId, $startPage, $boundary, $segments, $nextNumber
        ): void {
            if ($supersededCycleId !== null) {
                $this->cycles->markSuperseded($supersededCycleId);
            }

            $cycleId = $this->cycles->create(
                (int) $plan['id'],
                $userId,
                $nextNumber,
                $startPage,
                $boundary,
                $boundary,
                count($segments)
            );
            $this->insertSegments($cycleId, $userId, $segments, 0);
            $this->plans->updateCurrentCycleNumber((int) $plan['id'], $nextNumber);
        });

        $fresh = $this->requirePlan($userId, $planId);
        return $this->buildDetail($userId, $fresh) + ['superseded_cycle_id' => $supersededCycleId];
    }

    // ------------------------------------------------------------------
    // Cycles & segments
    // ------------------------------------------------------------------

    /** One cycle with its segments (history browsing). */
    public function cycleDetail(int $userId, int $cycleId): array
    {
        $cycle = $this->cycles->find($userId, $cycleId);
        if ($cycle === null) {
            throw new NotFoundException('Revision cycle not found');
        }

        [$segments, $missed] = $this->decorateSegments(
            $this->segments->findByCycle($cycleId)
        );

        return [
            'cycle' => $this->mapCycle($cycle),
            'segments' => $segments,
            'missed_count' => $missed,
        ];
    }

    /**
     * Explicitly skips a segment (the missed-day decision). Completed
     * segments are never touched.
     *
     * @return array<string, mixed> plan detail
     */
    public function skipSegment(int $userId, int $segmentId): array
    {
        $context = $this->segments->findWithContext($userId, $segmentId);
        if ($context === null) {
            throw new NotFoundException('Revision segment not found');
        }

        $segmentStatus = RevisionSegmentStatus::from($context['status']);
        if ($segmentStatus === RevisionSegmentStatus::Skipped) {
            return $this->planDetail($userId, (int) $context['plan_id']);
        }

        if ($segmentStatus === RevisionSegmentStatus::Completed) {
            throw ValidationException::withErrors([
                ['field' => 'status', 'message' => 'A completed segment cannot be skipped'],
            ]);
        }

        $planStatus = RevisionPlanStatus::from($context['plan_status']);
        if ($planStatus === RevisionPlanStatus::Completed) {
            throw ValidationException::withErrors([
                ['field' => 'plan', 'message' => 'This plan is completed'],
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

        if ($this->segments->countInProgressSessions($segmentId) > 0) {
            throw ValidationException::withErrors([
                ['field' => 'session', 'message' => 'Finish the open session on this segment first'],
            ]);
        }

        $cycleId = (int) $context['cycle_id'];
        Database::transaction(function () use ($segmentId, $cycleId): void {
            $this->segments->markSkipped($segmentId);
            $this->activateCycle($cycleId);
            $this->settleCycleIfComplete($cycleId);
        });

        return $this->planDetail($userId, (int) $context['plan_id']);
    }

    // ------------------------------------------------------------------
    // Shared transitions (also used by RevisionSessionService)
    // ------------------------------------------------------------------

    /** pending → active: the first unit of work on this cycle started. */
    public function activateCycle(int $cycleId): void
    {
        $this->cycles->markActive($cycleId);
    }

    /** Marks the cycle completed once no segment is pending/active. */
    public function settleCycleIfComplete(int $cycleId): bool
    {
        if ($this->segments->countUnfinished($cycleId) > 0) {
            return false;
        }

        $this->cycles->markCompleted($cycleId);
        return true;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function requirePlan(int $userId, int $planId): array
    {
        $plan = $this->plans->find($userId, $planId);
        if ($plan === null) {
            throw new NotFoundException('Revision plan not found');
        }
        return $plan;
    }

    /** @return array<string, mixed> plan detail of the just-created plan */
    private function detailForNewestPlan(int $userId): array
    {
        $plans = $this->plans->findByUser($userId);
        if ($plans === []) {
            throw new AppException('Revision plan disappeared after creation');
        }
        return $this->buildDetail($userId, $plans[0]);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function buildDetail(int $userId, array $plan): array
    {
        $planId = (int) $plan['id'];
        $current = $this->cycles->findLatestByPlan($planId);
        if ($current === null) {
            throw new AppException('Revision plan ' . $planId . ' has no cycle');
        }

        [$segments, $missed] = $this->decorateSegments(
            $this->segments->findByCycle((int) $current['id'])
        );

        return [
            'plan' => $this->mapPlan($plan),
            'cycles' => array_map($this->mapCycle(...), $this->cycles->findByPlan($planId)),
            'current_cycle' => $this->mapCycle($current),
            'segments' => $segments,
            'missed_count' => $missed,
            'in_progress_session' => $this->inProgressForPlan($userId, $planId),
        ];
    }

    /** The unfinished session only when it belongs to the given plan. */
    private function inProgressForPlan(int $userId, int $planId): ?array
    {
        $session = $this->sessions->findInProgress($userId);
        if ($session === null) {
            return null;
        }

        $context = $this->segments->findWithContext($userId, (int) $session['segment_id']);
        if ($context === null || (int) $context['plan_id'] !== $planId) {
            return null;
        }

        return $this->mapSessionWithDisplay($session, $context);
    }

    /**
     * Re-segments the untouched pending tail of a cycle (everything after
     * the last completed/skipped/active segment), scheduled from today.
     *
     * @return int segments written
     */
    private function regeneratePendingTail(array $cycle, int $userId, RevisionTargetUnit $unit, float $amount): int
    {
        $cycleId = (int) $cycle['id'];
        $lastFinal = $this->segments->findLastNonPending($cycleId);
        $startNumber = $lastFinal === null ? 1 : (int) $lastFinal['segment_number'] + 1;
        $startPage = $lastFinal === null
            ? (int) $cycle['range_start_page']
            : (int) $lastFinal['end_page'] + 1;
        $cycleEnd = (int) $cycle['range_end_page'];

        if ($startPage > $cycleEnd) {
            return 0;
        }

        $segments = $this->targets->segmentRange($startPage, $cycleEnd, $unit, $amount);

        $rows = $this->segments->findByCycle($cycleId);
        $kept = count(array_filter(
            $rows,
            static fn (array $row): bool => $row['status'] !== RevisionSegmentStatus::Pending->value
        ));

        $this->segments->deletePendingInCycle($cycleId);
        $this->insertSegments($cycleId, $userId, $segments, $startNumber - 1, 0);
        $this->cycles->updateSegmentCount($cycleId, $kept + count($segments));

        return count($segments);
    }

    /**
     * @param array<int, array{segment_number: int, start_page: int, end_page: int, page_count: int}> $segments
     */
    private function insertSegments(int $cycleId, int $userId, array $segments, int $numberOffset, int $dayOffset = 0): void
    {
        foreach ($segments as $index => $segment) {
            $this->segments->createPending(
                $cycleId,
                $userId,
                $numberOffset + $segment['segment_number'],
                $segment['start_page'],
                $segment['end_page'],
                $this->scheduleDate($dayOffset + $index)
            );
        }
    }

    /** Scheduled dates are consecutive UTC days starting at generation. */
    private function scheduleDate(int $daysFromNow): string
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $today->modify(($daysFromNow >= 0 ? '+' : '') . $daysFromNow . ' days')->format('Y-m-d');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: int} decorated + missed count
     */
    private function decorateSegments(array $rows): array
    {
        $today = gmdate('Y-m-d');
        $decorated = [];
        $missed = 0;

        foreach ($rows as $row) {
            $status = RevisionSegmentStatus::from($row['status']);
            $date = (string) $row['scheduled_date'];
            $isMissed = $status === RevisionSegmentStatus::Pending && $date < $today;
            $isToday = $date === $today && !$status->isFinal();

            if ($isMissed) {
                $missed++;
            }

            $decorated[] = $this->mapSegment($row) + [
                'is_missed' => $isMissed,
                'is_today' => $isToday,
            ];
        }

        return [$decorated, $missed];
    }

    /**
     * Resolves the target from input, falling back to the saved defaults.
     *
     * @param int|float|null $dailyAmount
     * @return array{0: RevisionTargetUnit, 1: float}
     */
    private function resolveTarget(int $userId, ?string $targetUnit, int|float|null $dailyAmount): array
    {
        $defaults = $this->settings->findDefaultRevisionTarget($userId) ?? [];

        $unit = $targetUnit !== null
            ? $this->unitFrom($targetUnit, 'target_unit')
            : RevisionTargetUnit::from($defaults['daily_revision_unit'] ?? RevisionTargetUnit::Page->value);

        $amount = $dailyAmount !== null
            ? (float) $dailyAmount
            : (float) ($defaults['daily_revision_amount'] ?? self::DEFAULT_DAILY_AMOUNT);

        return [$unit, $amount];
    }

    private function unitFrom(string $value, string $field): RevisionTargetUnit
    {
        $unit = RevisionTargetUnit::tryFrom($value);
        if ($unit === null) {
            throw ValidationException::withErrors([
                ['field' => $field, 'message' => 'Unsupported revision target unit: ' . $value],
            ]);
        }
        return $unit;
    }

    private function assertTransition(RevisionPlanStatus $current, RevisionPlanStatus $target): void
    {
        $allowed = match ($current) {
            RevisionPlanStatus::Active => $target === RevisionPlanStatus::Paused,
            RevisionPlanStatus::Paused => $target === RevisionPlanStatus::Active
                || $target === RevisionPlanStatus::Completed,
            RevisionPlanStatus::Completed => false,
        };

        if ($allowed) {
            return;
        }

        throw ValidationException::withErrors([
            ['field' => 'status', 'message' => sprintf(
                'Cannot move a revision plan from %s to %s',
                $current->value,
                $target->value
            )],
        ]);
    }

    /** Resuming refuses a cycle that no longer fits the memorized range. */
    private function assertCurrentCycleFitsMemorizedRange(int $userId, int $planId): void
    {
        $cycle = $this->cycles->findLatestByPlan($planId);
        if ($cycle === null) {
            throw new AppException('Revision plan has no cycle');
        }

        $range = $this->hifz->memorizedRange($userId);
        $fits = (int) $cycle['range_start_page'] >= $range['memorized_start_page']
            && (int) $cycle['range_end_page'] <= $range['current_boundary_page'];

        if (!$fits) {
            throw ValidationException::withErrors([
                [
                    'field' => 'cycle',
                    'message' => 'The current cycle no longer fits your memorized range; regenerate it first',
                ],
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function mapPlan(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'target_unit' => (string) $row['target_unit'],
            'daily_amount' => (float) $row['daily_amount'],
            'range_start_page' => (int) $row['range_start_page'],
            'range_end_page' => (int) $row['range_end_page'],
            'boundary_page_snapshot' => (int) $row['boundary_page_snapshot'],
            'status' => (string) $row['status'],
            'current_cycle_number' => (int) $row['current_cycle_number'],
            'started_at' => $row['started_at'],
            'completed_at' => $row['completed_at'],
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    private function mapCycle(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'plan_id' => isset($row['plan_id']) ? (int) $row['plan_id'] : null,
            'cycle_number' => (int) $row['cycle_number'],
            'range_start_page' => (int) $row['range_start_page'],
            'range_end_page' => (int) $row['range_end_page'],
            'boundary_page_snapshot' => (int) $row['boundary_page_snapshot'],
            'segment_count' => (int) $row['segment_count'],
            'status' => (string) $row['status'],
            'started_at' => $row['started_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    public function mapSegment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'segment_number' => (int) $row['segment_number'],
            'start_page' => (int) $row['start_page'],
            'end_page' => (int) $row['end_page'],
            'page_count' => (int) $row['page_count'],
            'scheduled_date' => (string) $row['scheduled_date'],
            'status' => (string) $row['status'],
            'completed_at' => $row['completed_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    public function mapSession(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'segment_id' => (int) $row['segment_id'],
            'resumes_session_id' => $row['resumes_session_id'] === null
                ? null
                : (int) $row['resumes_session_id'],
            'status' => (string) $row['status'],
            'total_pages' => (int) $row['total_pages'],
            'pages_completed' => (int) $row['pages_completed'],
            'last_page_reached' => $row['last_page_reached'] === null
                ? null
                : (int) $row['last_page_reached'],
            'started_at' => $row['started_at'],
            'ended_at' => $row['ended_at'] ?? null,
            'duration_seconds' => $row['duration_seconds'] ?? null,
            'interruption_reason' => $row['interruption_reason'] ?? null,
            'notes' => $row['notes'] ?? null,
        ];
    }

    /**
     * Session payload for the UI, with the display arithmetic computed
     * server-side (conventions §14 — PHP owns every number shown to the
     * user):
     *
     * - current_page: the page to open next (last reached + 1, clamped to
     *   the segment end; segment start when nothing was reached yet);
     * - pages_remaining: pages of the segment not yet completed;
     * - percent_complete: completion percentage of the segment, rounded
     *   to a whole number.
     *
     * @param array<string, mixed> $row raw session row
     * @param array<string, mixed> $segment segment row (start_page, end_page, page_count)
     * @return array<string, mixed>
     */
    public function mapSessionWithDisplay(array $row, array $segment): array
    {
        $session = $this->mapSession($row);
        $start = (int) $segment['start_page'];
        $end = (int) $segment['end_page'];
        $total = (int) $segment['page_count'];
        $last = $session['last_page_reached'];

        return $session + [
            'current_page' => $last === null ? $start : min($last + 1, $end),
            'pages_remaining' => max($total - $session['pages_completed'], 0),
            'percent_complete' => $total > 0
                ? (int) round($session['pages_completed'] * 100 / $total)
                : 0,
        ];
    }
}
