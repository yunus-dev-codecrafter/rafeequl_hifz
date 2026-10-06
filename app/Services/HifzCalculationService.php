<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AppException;
use App\Exceptions\NotFoundException;
use App\Models\DivisionType;
use App\Models\RevisionTargetUnit;
use App\Repositories\MemorizationStateRepository;

/**
 * User-facing Hifz calculations (Prompt 08): memorized range, current
 * memorization boundary, current page context, division coverage and
 * revision targets. All arithmetic runs here — never in JS (conventions §1/§14).
 *
 * The boundary comes from user tables only; Quran coordinates are validated
 * against the canonical dataset and never fabricated (data-architecture §9).
 */
final class HifzCalculationService
{
    private const PERCENT_PRECISION = 2;

    /** Rolling Rabt (ربط) window: at most this many newest memorized pages. */
    public const MAX_RABT_PAGES = 30;

    private MemorizationStateRepository $states;
    private QuranStructureService $structure;
    private RevisionTargetService $targets;

    public function __construct(
        ?MemorizationStateRepository $states = null,
        ?QuranStructureService $structure = null,
        ?RevisionTargetService $targets = null,
    ) {
        $this->states = $states ?? new MemorizationStateRepository();
        $this->structure = $structure ?? new QuranStructureService();
        $this->targets = $targets ?? new RevisionTargetService($this->structure);
    }

    /** The user's memorized range with dataset-backed progress figures. */
    public function memorizedRange(int $userId): array
    {
        $state = $this->requireState($userId);
        $bounds = $this->structure->pageBounds();

        $start = (int) $state['memorized_start_page'];
        $end = (int) $state['current_boundary_page'];

        if ($end > $bounds['max_page']) {
            throw new AppException(
                'Memorization boundary page ' . $end . ' exceeds the loaded dataset (max page ' . $bounds['max_page'] . ').'
            );
        }

        $pageCount = $this->structure->pageCountBetween($start, $end);
        $total = $bounds['page_count'];

        return [
            'memorized_start_page' => $start,
            'current_boundary_page' => $end,
            'page_count' => $pageCount,
            'total_pages' => $total,
            'percent_memorized' => $total > 0
                ? round($pageCount / $total * 100, self::PERCENT_PRECISION)
                : 0.0,
            'status' => $state['status'],
        ];
    }

    /** Where the user's memorization currently stops (user data, not Quran data). */
    public function currentBoundary(int $userId): int
    {
        return (int) $this->requireState($userId)['current_boundary_page'];
    }

    /** The boundary page with its Quran location and divisions containing it. */
    public function currentPageDetails(int $userId): array
    {
        $page = $this->currentBoundary($userId);
        $details = $this->structure->pageDetails($page);

        return [
            'page_number' => $page,
            'start_surah' => (int) $details['start_surah'],
            'start_ayah' => (int) $details['start_ayah'],
            'end_surah' => (int) $details['end_surah'],
            'end_ayah' => (int) $details['end_ayah'],
            'divisions' => [
                'juz' => $this->structure->divisionAtPage(DivisionType::Juz, $page),
                'hizb' => $this->structure->divisionAtPage(DivisionType::Hizb, $page),
                'rub' => $this->structure->divisionAtPage(DivisionType::Rub, $page),
            ],
        ];
    }

    /**
     * The page right after the boundary — what to memorize next.
     * Null when the boundary already covers the dataset's last page;
     * fails closed when the stored boundary exceeds the dataset.
     */
    public function nextPageToMemorize(int $userId): ?int
    {
        $boundary = $this->currentBoundary($userId);
        $maxPage = $this->structure->pageBounds()['max_page'];

        if ($boundary > $maxPage) {
            throw new AppException(
                'Memorization boundary page ' . $boundary . ' exceeds the loaded dataset (max page ' . $maxPage . ').'
            );
        }

        return $boundary >= $maxPage ? null : $boundary + 1;
    }

    public function isPageMemorized(int $userId, int $page): bool
    {
        $this->structure->assertValidPageNumber($page);
        $state = $this->requireState($userId);

        return $page >= (int) $state['memorized_start_page']
            && $page <= (int) $state['current_boundary_page'];
    }

    /**
     * How much of each division the memorized range covers: fully covered
     * (inside the range) vs partially covered (touches the range).
     */
    public function divisionCoverage(int $userId, DivisionType $type): array
    {
        $range = $this->memorizedRange($userId);
        $start = $range['memorized_start_page'];
        $end = $range['current_boundary_page'];

        $fully = [];
        $partially = [];
        foreach ($this->structure->divisionsInRange($type, $start, $end) as $row) {
            $inside = (int) $row['start_page'] >= $start && (int) $row['end_page'] <= $end;
            if ($inside) {
                $fully[] = $row;
            } else {
                $partially[] = $row;
            }
        }

        return [
            'division_type' => $type->value,
            'memorized_start_page' => $start,
            'current_boundary_page' => $end,
            'fully_covered' => $fully,
            'partially_covered' => $partially,
            'fully_covered_count' => count($fully),
            'partially_covered_count' => count($partially),
        ];
    }

    /**
     * Daily target segments across the memorized range. The final segment
     * may be smaller; nothing ever extends past the current boundary.
     */
    public function revisionTargets(int $userId, RevisionTargetUnit $unit, int|float $amount): array
    {
        $range = $this->memorizedRange($userId);
        $start = $range['memorized_start_page'];
        $end = $range['current_boundary_page'];

        $segments = $this->targets->segmentRange($start, $end, $unit, $amount);

        return [
            'target_unit' => $unit->value,
            'daily_amount' => (float) $amount,
            'range_start_page' => $start,
            'range_end_page' => $end,
            'segment_count' => count($segments),
            'segments' => $segments,
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

    /**
     * Pure rolling Rabt (ربط) bounds (Prompt 12): the newest `maxPages`
     * memorized pages ending at the boundary, never sliding below the
     * memorized start — fewer memorized pages means the whole range.
     *
     * @return array{start_page: int, end_page: int, page_count: int}
     */
    public function rabtBounds(
        int $memorizedStartPage,
        int $boundaryPage,
        int $maxPages = self::MAX_RABT_PAGES,
    ): array {
        if ($maxPages < 1) {
            throw new AppException('Rabt window must cover at least one page');
        }
        if ($boundaryPage < $memorizedStartPage) {
            throw new AppException(
                'Memorization boundary page ' . $boundaryPage . ' is below the memorized start page ' . $memorizedStartPage . '.'
            );
        }

        $memorizedCount = $boundaryPage - $memorizedStartPage + 1;
        $count = min($maxPages, $memorizedCount);

        return [
            'start_page' => $boundaryPage - $count + 1,
            'end_page' => $boundaryPage,
            'page_count' => $count,
        ];
    }

    /**
     * The rolling Rabt (ربط) range: most recent memorized pages, capped at
     * MAX_RABT_PAGES (Prompt 12). Derived at read time from the current
     * state, so every boundary change (establish / mark / correction)
     * slides the window automatically and unmemorized pages can never
     * enter it.
     *
     * @return array{start_page: int, end_page: int, page_count: int, max_pages: int, memorized_page_count: int, pages: array<int, int>}
     */
    public function rabtRange(int $userId, int $maxPages = self::MAX_RABT_PAGES): array
    {
        $range = $this->memorizedRange($userId);

        $bounds = $this->rabtBounds(
            $range['memorized_start_page'],
            $range['current_boundary_page'],
            $maxPages
        );

        return [
            'start_page' => $bounds['start_page'],
            'end_page' => $bounds['end_page'],
            'page_count' => $bounds['page_count'],
            'max_pages' => $maxPages,
            'memorized_page_count' => $range['page_count'],
            'pages' => range($bounds['start_page'], $bounds['end_page']),
        ];
    }
}
