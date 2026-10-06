<?php

declare(strict_types=1);

/**
 * Unit tests: revision target segmentation (no database, fake division data).
 * Run: php tests/Unit/RevisionTargetServiceTest.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/Support/FakeDivisionLocator.php';

use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\RevisionTargetUnit;
use App\Services\RevisionTargetService;

// Synthetic ranges (test values only — not Quran data):
//   hizb: 1-4, 5-8, 9-12, 13-16
//   juz:  1-8 (shared page 8), 8-16
//   rub:  1-2, 3-4, 5-6, 7-8, 9-11, 11-12 (shared page 11), 13-14, 15-16
$locator = new FakeDivisionLocator([
    'hizb' => [
        ['number' => 1, 'start' => 1, 'end' => 4],
        ['number' => 2, 'start' => 5, 'end' => 8],
        ['number' => 3, 'start' => 9, 'end' => 12],
        ['number' => 4, 'start' => 13, 'end' => 16],
    ],
    'juz' => [
        ['number' => 1, 'start' => 1, 'end' => 8],
        ['number' => 2, 'start' => 8, 'end' => 16],
    ],
    'rub' => [
        ['number' => 1, 'start' => 1, 'end' => 2],
        ['number' => 2, 'start' => 3, 'end' => 4],
        ['number' => 3, 'start' => 5, 'end' => 6],
        ['number' => 4, 'start' => 7, 'end' => 8],
        ['number' => 5, 'start' => 9, 'end' => 11],
        ['number' => 6, 'start' => 11, 'end' => 12],
        ['number' => 7, 'start' => 13, 'end' => 14],
        ['number' => 8, 'start' => 15, 'end' => 16],
    ],
]);

$service = new RevisionTargetService($locator);

/** @return array<int, array{0: int, 1: int}> [start, end] pairs */
function pairs(array $segments): array
{
    return array_map(
        static fn (array $segment): array => [$segment['start_page'], $segment['end_page']],
        $segments
    );
}

// --- page targets -----------------------------------------------------------

$segments = $service->segmentRange(1, 10, RevisionTargetUnit::Page, 4.0);
checkEquals('page target 4: segments', [[1, 4], [5, 8], [9, 10]], pairs($segments));
checkEquals('page target 4: final segment is smaller', 2, $segments[2]['page_count']);
checkCovers('page target 4: tiles the range', $segments, 1, 10);

$segments = $service->segmentRange(1, 8, RevisionTargetUnit::Page, 4.0);
checkEquals('page target 4 exact fit', [[1, 4], [5, 8]], pairs($segments));
checkCovers('page target 4 exact fit covers', $segments, 1, 8);

$segments = $service->segmentRange(3, 5, RevisionTargetUnit::Page, 1);
checkEquals('page target 1: every page', [[3, 3], [4, 4], [5, 5]], pairs($segments));

$segments = $service->segmentRange(5, 5, RevisionTargetUnit::Page, 2.0);
checkEquals('target larger than range: single smaller segment', [[5, 5]], pairs($segments));

$segments = $service->segmentRange(1, 7, RevisionTargetUnit::Page, 1.5);
checkEquals('fractional page target 1.5', [[1, 2], [3, 4], [5, 6], [7, 7]], pairs($segments));
checkCovers('fractional page target 1.5 covers', $segments, 1, 7);

$segments = $service->segmentRange(1, 10, RevisionTargetUnit::Page, 2.5);
checkEquals('fractional page target 2.5', [[1, 3], [4, 6], [7, 9], [10, 10]], pairs($segments));
checkCovers('fractional page target 2.5 covers', $segments, 1, 10);

$segments = $service->segmentRange(1, 10, RevisionTargetUnit::Page, 100.0);
checkEquals('target beyond range: one segment', [[1, 10]], pairs($segments));

$segments = $service->segmentRange(1, 3, RevisionTargetUnit::Page, 0.001);
checkEquals('minimum amount 0.001: one page per segment', [[1, 1], [2, 2], [3, 3]], pairs($segments));

// --- division targets -------------------------------------------------------

$segments = $service->segmentRange(1, 16, RevisionTargetUnit::Hizb, 1.0);
checkEquals('hizb target 1', [[1, 4], [5, 8], [9, 12], [13, 16]], pairs($segments));
checkCovers('hizb target 1 covers', $segments, 1, 16);

$segments = $service->segmentRange(2, 15, RevisionTargetUnit::Hizb, 1.0);
checkEquals('hizb target 1 from mid-division', [[2, 4], [5, 8], [9, 12], [13, 15]], pairs($segments));
checkEquals('hizb final segment smaller', 3, $segments[3]['page_count']);
checkCovers('hizb target 1 from mid covers', $segments, 2, 15);

$segments = $service->segmentRange(6, 14, RevisionTargetUnit::Hizb, 2.0);
checkEquals('hizb target 2', [[6, 12], [13, 14]], pairs($segments));
checkCovers('hizb target 2 covers', $segments, 6, 14);

$segments = $service->segmentRange(1, 16, RevisionTargetUnit::Juz, 1.0);
checkEquals('juz target 1 uses shared page 8 → juz 2', [[1, 8], [9, 16]], pairs($segments));
checkCovers('juz target 1 covers', $segments, 1, 16);

$segments = $service->segmentRange(1, 16, RevisionTargetUnit::Juz, 10.0);
checkEquals('juz target beyond available divisions: clamps to range end', [[1, 16]], pairs($segments));

$segments = $service->segmentRange(11, 14, RevisionTargetUnit::Rub, 1.0);
checkEquals('rub from shared page 11 → rub 6 first', [[11, 12], [13, 14]], pairs($segments));
checkCovers('rub from shared page covers', $segments, 11, 14);

$segments = $service->segmentRange(1, 16, RevisionTargetUnit::Rub, 2.0);
checkEquals('rub target 2', [[1, 4], [5, 8], [9, 12], [13, 16]], pairs($segments));

// --- validation / failure ---------------------------------------------------

checkThrows(
    'reversed range rejected',
    fn () => $service->segmentRange(5, 4, RevisionTargetUnit::Page, 1.0),
    ValidationException::class,
    'end_page'
);
checkThrows(
    'page 0 rejected',
    fn () => $service->segmentRange(0, 4, RevisionTargetUnit::Page, 1.0),
    ValidationException::class,
    'start_page'
);
checkThrows(
    'zero amount rejected',
    fn () => $service->segmentRange(1, 4, RevisionTargetUnit::Page, 0.0),
    ValidationException::class,
    'amount'
);
checkThrows(
    'sub-minimal amount rejected',
    fn () => $service->segmentRange(1, 4, RevisionTargetUnit::Page, 0.0005),
    ValidationException::class,
    'amount'
);
checkThrows(
    'absurd amount rejected (schema DECIMAL(6,3) bound)',
    fn () => $service->segmentRange(1, 4, RevisionTargetUnit::Page, 10000.0),
    ValidationException::class,
    'amount'
);
checkThrows(
    'fractional division target rejected',
    fn () => $service->segmentRange(1, 16, RevisionTargetUnit::Hizb, 1.5),
    ValidationException::class,
    'amount'
);
checkThrows(
    'division target below one rejected',
    fn () => $service->segmentRange(1, 16, RevisionTargetUnit::Rub, 0.5),
    ValidationException::class,
    'amount'
);

$empty = new RevisionTargetService(new FakeDivisionLocator([]));
checkThrows(
    'missing division data fails closed',
    fn () => $empty->segmentRange(1, 4, RevisionTargetUnit::Hizb, 1.0),
    AppException::class
);

exit(summary('RevisionTargetService'));
