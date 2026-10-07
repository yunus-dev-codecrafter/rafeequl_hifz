<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\BoundaryChangeReason;
use App\Repositories\MemorizationStateRepository;
use App\Repositories\RevisionPlanRepository;

/**
 * Memorization progress (Prompt 09): establishing the memorized range and
 * boundary, identifying the next page, explicitly marking ranges as
 * memorized, and confirmed boundary correction.
 *
 * Rules enforced here:
 * - viewing never writes (state() is read-only; nothing auto-marks a page);
 * - history is append-only — boundary moves add rows, never rewrite them;
 * - boundary correction always requires explicit confirmation;
 * - all writes run in one transaction; dependent figures recalculate at
 *   read time, active revision plans outside a shrunken range are paused;
 * - every boundary write also makes sure today's Rabt task exists. That is
 *   the only link between the domains and it points one way: memorization →
 *   task. Productivity data still never touches Hifz progress.
 *
 * Quran coordinates are validated against the canonical dataset and never
 * fabricated (data-architecture §9); arithmetic lives in HifzCalculationService.
 */
final class MemorizationProgressService
{
    private MemorizationStateRepository $states;
    private RevisionPlanRepository $plans;
    private QuranStructureService $structure;
    private HifzCalculationService $hifz;
    private TaskService $tasks;

    public function __construct(
        ?MemorizationStateRepository $states = null,
        ?RevisionPlanRepository $plans = null,
        ?QuranStructureService $structure = null,
        ?HifzCalculationService $hifz = null,
        ?TaskService $tasks = null,
    ) {
        $this->states = $states ?? new MemorizationStateRepository();
        $this->plans = $plans ?? new RevisionPlanRepository();
        $this->structure = $structure ?? new QuranStructureService();
        $this->hifz = $hifz ?? new HifzCalculationService($this->states, $this->structure);
        $this->tasks = $tasks ?? new TaskService();
    }

    /**
     * Read-only snapshot of the user's progress. Writes nothing — opening
     * the app or a page must never mark anything as memorized.
     *
     * @return array<string, mixed>
     */
    public function state(int $userId): array
    {
        $state = $this->states->findState($userId);
        if ($state === null) {
            return [
                'established' => false,
                'memorized_start_page' => null,
                'current_boundary_page' => null,
                'next_page_to_memorize' => null,
                'status' => null,
                'last_boundary_changed_at' => null,
                'last_page_memorized_at' => null,
                'progress' => null,
            ];
        }

        $range = $this->hifz->memorizedRange($userId);

        return [
            'established' => true,
            'memorized_start_page' => $range['memorized_start_page'],
            'current_boundary_page' => $range['current_boundary_page'],
            'next_page_to_memorize' => $this->hifz->nextPageToMemorize($userId),
            'status' => $range['status'],
            'last_boundary_changed_at' => $state['last_boundary_changed_at'],
            'last_page_memorized_at' => $state['last_page_memorized_at'],
            'progress' => [
                'page_count' => $range['page_count'],
                'total_pages' => $range['total_pages'],
                'percent_memorized' => $range['percent_memorized'],
            ],
        ];
    }

    /**
     * Establishes the memorized range and boundary in one explicit action.
     * Writes the state row plus one boundary-history row (previous = NULL);
     * no per-page history is invented — the pages were memorized before
     * this app existed and their timestamps would be a guess.
     *
     * @return array{state: array<string, mixed>, boundary_change: array<string, mixed>}
     */
    public function establish(int $userId, int $startPage, int $boundaryPage, ?string $note = null): array
    {
        if ($this->states->findState($userId) !== null) {
            throw ValidationException::withErrors([
                [
                    'field' => 'established',
                    'message' => 'Memorization state is already established; correct the boundary instead',
                ],
            ]);
        }

        $this->assertPageInDataset($startPage, 'memorized_start_page');
        $this->assertPageInDataset($boundaryPage, 'current_boundary_page');
        $this->assertBoundaryCoversRange($startPage, $boundaryPage);

        Database::transaction(function () use ($userId, $startPage, $boundaryPage, $note): void {
            $this->states->insertState($userId, $startPage, $boundaryPage);
            $this->states->recordBoundaryChange(
                $userId,
                null,
                $boundaryPage,
                BoundaryChangeReason::Manual,
                $note
            );
        });

        $this->tasks->ensureRabtTaskForToday($userId);

        return [
            'state' => $this->state($userId),
            'boundary_change' => $this->boundaryChange(null, $boundaryPage),
        ];
    }

    /**
     * Explicitly records every page in [startPage..endPage] as memorized
     * (append-only) and advances the boundary when the range continues
     * from it. Re-marking a page appends another history row on purpose.
     *
     * @return array{state: array<string, mixed>, marked: array<string, mixed>, boundary_change: array<string, mixed>|null, revision_plans_paused: int}
     */
    public function markMemorized(int $userId, int $startPage, int $endPage, ?string $note = null): array
    {
        $state = $this->requireState($userId);

        $this->assertPageInDataset($startPage, 'start_page');
        $this->assertPageInDataset($endPage, 'end_page');
        if ($startPage > $endPage) {
            throw ValidationException::withErrors([
                ['field' => 'range', 'message' => 'Start page must not be after end page'],
            ]);
        }

        $rangeStart = (int) $state['memorized_start_page'];
        $boundary = (int) $state['current_boundary_page'];

        // Contiguous with the memorized range, never below its start.
        if ($startPage < $rangeStart || $startPage > $boundary + 1) {
            throw ValidationException::withErrors([
                [
                    'field' => 'start_page',
                    'message' => sprintf(
                        'Marking must start inside the memorized range or on its next page (%d-%d)',
                        $rangeStart,
                        $boundary + 1
                    ),
                ],
            ]);
        }

        $newBoundary = max($boundary, $endPage);
        $boundaryAdvanced = $newBoundary > $boundary;
        $boundaryChange = $this->boundaryChange($boundary, $newBoundary);

        // Marking only ever grows the boundary: no plan can fall outside.
        $paused = (int) Database::transaction(
            function () use ($userId, $startPage, $endPage, $note, $boundary, $newBoundary, $boundaryAdvanced): int {
                $this->states->recordMemorizedPages($userId, $startPage, $endPage, $note);

                if ($boundaryAdvanced) {
                    $this->states->updateBoundary($userId, $newBoundary);
                    $this->states->recordBoundaryChange(
                        $userId,
                        $boundary,
                        $newBoundary,
                        BoundaryChangeReason::Manual,
                        $note
                    );
                }

                $this->states->touchLastPageMemorized($userId);

                return 0;
            }
        );

        $this->tasks->ensureRabtTaskForToday($userId);

        return [
            'state' => $this->state($userId),
            'marked' => [
                'start_page' => $startPage,
                'end_page' => $endPage,
                'page_count' => $endPage - $startPage + 1,
                'previous_boundary_page' => $boundary,
                'current_boundary_page' => $newBoundary,
                'boundary_advanced' => $boundaryAdvanced,
            ],
            'boundary_change' => $boundaryChange,
            'revision_plans_paused' => $paused,
        ];
    }

    /**
     * Corrects an incorrect boundary — explicit confirmation required.
     * The old value stays in boundary history; active revision plans that
     * no longer fit the shrunken range are paused (never rewritten).
     *
     * @return array{state: array<string, mixed>, boundary_change: array<string, mixed>|null, revision_plans_paused: int}
     */
    public function correctBoundary(int $userId, int $newBoundary, bool $confirm, ?string $note = null): array
    {
        $state = $this->requireState($userId);

        if (!$confirm) {
            throw ValidationException::withErrors([
                [
                    'field' => 'confirm',
                    'message' => 'Confirmation is required to change the memorization boundary',
                ],
            ]);
        }

        $this->assertPageInDataset($newBoundary, 'current_boundary_page');

        $rangeStart = (int) $state['memorized_start_page'];
        $boundary = (int) $state['current_boundary_page'];
        $this->assertBoundaryCoversRange($rangeStart, $newBoundary);

        $boundaryChange = $this->boundaryChange($boundary, $newBoundary);
        if ($boundaryChange === null) {
            return [
                'state' => $this->state($userId),
                'boundary_change' => null,
                'revision_plans_paused' => 0,
            ];
        }

        $paused = (int) Database::transaction(
            function () use ($userId, $rangeStart, $boundary, $newBoundary, $note): int {
                $this->states->updateBoundary($userId, $newBoundary);
                $this->states->recordBoundaryChange(
                    $userId,
                    $boundary,
                    $newBoundary,
                    BoundaryChangeReason::Manual,
                    $note
                );

                if ($newBoundary < $boundary) {
                    return $this->plans->pauseActiveExceedingRange($userId, $rangeStart, $newBoundary);
                }

                return 0;
            }
        );

        $this->tasks->ensureRabtTaskForToday($userId);

        return [
            'state' => $this->state($userId),
            'boundary_change' => $boundaryChange,
            'revision_plans_paused' => $paused,
        ];
    }

    /** @return array<string, mixed> */
    private function requireState(int $userId): array
    {
        $state = $this->states->findState($userId);
        if ($state === null) {
            throw new NotFoundException('Memorization state not found');
        }
        return $state;
    }

    /** Fails closed (422, named field) on pages the dataset cannot support. */
    private function assertPageInDataset(int $page, string $field): void
    {
        $this->structure->assertValidPageNumber($page, $field);
    }

    private function assertBoundaryCoversRange(int $startPage, int $boundaryPage): void
    {
        if ($boundaryPage < $startPage) {
            throw ValidationException::withErrors([
                [
                    'field' => 'current_boundary_page',
                    'message' => 'Boundary must not be before the start of the memorized range',
                ],
            ]);
        }
    }

    /** @return array{previous_boundary_page: int|null, new_boundary_page: int, reason: string}|null */
    private function boundaryChange(?int $previous, int $new): ?array
    {
        if ($previous !== null && $previous === $new) {
            return null;
        }

        return [
            'previous_boundary_page' => $previous,
            'new_boundary_page' => $new,
            'reason' => BoundaryChangeReason::Manual->value,
        ];
    }
}
