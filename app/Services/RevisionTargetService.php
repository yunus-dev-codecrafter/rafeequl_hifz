<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\RevisionTargetUnit;

/**
 * Daily revision targeting (المراجعة): splits a page range into segments of
 * a fixed daily target unit (revision target calculations, Prompt 08).
 *
 * Established rules (Prompt 00): normal segments reach the full daily
 * target; the final segment may be smaller; a segment NEVER extends past
 * the range end — callers pass the memorized boundary as the end, so
 * revision can never be scheduled beyond it.
 *
 * Whole-division targets (hizb/rub/juz) end exactly on canonical division
 * boundaries via DivisionLocatorInterface — never on estimated pages.
 */
final class RevisionTargetService
{
    /** Pages are accumulated in thousandths so DECIMAL(6,3) targets stay exact. */
    private const THOUSANDTHS_PER_PAGE = 1000;

    private const MIN_AMOUNT = 0.001;

    /** Mirrors revision_plans.daily_amount DECIMAL(6,3) — schema-driven bound. */
    private const MAX_AMOUNT = 9999.999;

    private DivisionLocatorInterface $divisions;

    public function __construct(?DivisionLocatorInterface $divisions = null)
    {
        $this->divisions = $divisions ?? new QuranStructureService();
    }

    /**
     * @return array<int, array{segment_number: int, start_page: int, end_page: int, page_count: int}>
     */
    public function segmentRange(int $startPage, int $endPage, RevisionTargetUnit $unit, int|float $amount): array
    {
        $this->assertRange($startPage, $endPage);
        $amount = (float) $amount;
        $amountThousandths = $this->assertAmount($amount, $unit);

        return $unit->isPage()
            ? $this->segmentPages($startPage, $endPage, $amountThousandths)
            : $this->segmentDivisions($startPage, $endPage, $unit, $amountThousandths);
    }

    /**
     * Page targets: each segment accumulates at least the daily amount;
     * the leftover tail becomes the smaller final segment.
     *
     * @return array<int, array{segment_number: int, start_page: int, end_page: int, page_count: int}>
     */
    private function segmentPages(int $startPage, int $endPage, int $amountThousandths): array
    {
        $segments = [];
        $segmentStart = $startPage;
        $accumulated = 0;

        for ($page = $startPage; $page <= $endPage; $page++) {
            $accumulated += self::THOUSANDTHS_PER_PAGE;
            if ($accumulated >= $amountThousandths) {
                $segments[] = $this->segment(count($segments) + 1, $segmentStart, $page);
                $segmentStart = $page + 1;
                $accumulated = 0;
            }
        }

        if ($segmentStart <= $endPage) {
            $segments[] = $this->segment(count($segments) + 1, $segmentStart, $endPage);
        }

        return $segments;
    }

    /**
     * Division targets: a segment runs from its current position to the end
     * of the amount-th canonical division counting from the one it starts in.
     *
     * @return array<int, array{segment_number: int, start_page: int, end_page: int, page_count: int}>
     */
    private function segmentDivisions(int $startPage, int $endPage, RevisionTargetUnit $unit, int $amountThousandths): array
    {
        $type = $unit->toDivisionType();
        if ($type === null) {
            throw new AppException('Target unit has no division type');
        }
        $units = intdiv($amountThousandths, self::THOUSANDTHS_PER_PAGE);

        $segments = [];
        $position = $startPage;
        $currentNumber = $this->divisions->divisionNumberAtPage($type, $position);
        if ($currentNumber === null) {
            throw new AppException(
                'No ' . $type->value . ' division covers page ' . $position
            );
        }

        while ($position <= $endPage) {
            $targetNumber = $currentNumber + $units - 1;
            $divisionEnd = $this->divisions->divisionEndPage($type, $targetNumber);
            $segmentEnd = min($divisionEnd ?? $endPage, $endPage);

            if ($segmentEnd < $position) {
                throw new AppException(
                    'Dataset is inconsistent: ' . $type->value . ' division ' . $currentNumber
                    . ' ends before page ' . $position
                );
            }

            $segments[] = $this->segment(count($segments) + 1, $position, $segmentEnd);
            $position = $segmentEnd + 1;

            if ($position <= $endPage) {
                $currentNumber = $this->divisions->divisionNumberAtPage($type, $position);
                if ($currentNumber === null) {
                    throw new AppException(
                        'No ' . $type->value . ' division covers page ' . $position
                    );
                }
            }
        }

        return $segments;
    }

    /** @return array{segment_number: int, start_page: int, end_page: int, page_count: int} */
    private function segment(int $number, int $startPage, int $endPage): array
    {
        return [
            'segment_number' => $number,
            'start_page' => $startPage,
            'end_page' => $endPage,
            'page_count' => $endPage - $startPage + 1,
        ];
    }

    private function assertRange(int $startPage, int $endPage): void
    {
        if ($startPage < 1) {
            throw ValidationException::withErrors([
                ['field' => 'start_page', 'message' => 'Page numbers start at 1'],
            ]);
        }
        if ($endPage < $startPage) {
            throw ValidationException::withErrors([
                ['field' => 'end_page', 'message' => 'Range end must not be before its start'],
            ]);
        }
    }

    /** @return int amount in thousandths of the unit */
    private function assertAmount(float $amount, RevisionTargetUnit $unit): int
    {
        if (!is_finite($amount) || $amount > self::MAX_AMOUNT) {
            throw ValidationException::withErrors([
                ['field' => 'amount', 'message' => 'Amount must be a finite number up to ' . self::MAX_AMOUNT],
            ]);
        }

        if (!$unit->isPage()) {
            if ($amount < 1 || floor($amount) !== $amount) {
                throw ValidationException::withErrors([
                    ['field' => 'amount', 'message' => 'Division targets must be whole units (e.g. 1 or 2 hizb)'],
                ]);
            }
        }

        $amountThousandths = (int) round($amount * self::THOUSANDTHS_PER_PAGE);
        if ($amount < self::MIN_AMOUNT) {
            throw ValidationException::withErrors([
                ['field' => 'amount', 'message' => 'Amount must be at least ' . self::MIN_AMOUNT],
            ]);
        }

        return $amountThousandths;
    }
}
