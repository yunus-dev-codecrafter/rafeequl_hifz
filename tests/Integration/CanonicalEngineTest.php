<?php

declare(strict_types=1);

/**
 * Prompt 24A §9: the Prompt 08 Hifz calculation engine exercised against
 * the REAL imported Madinah dataset (604 pages) copied into this test
 * database - no synthetic assumptions, expectations derived from the
 * canonical structure itself and from arithmetic on our own inputs.
 *
 * Covers: page boundaries, memorized page ranges, consecutive revision
 * segments, juz/hizb/rub relationships, the 30-page Rabt window, the
 * current memorized boundary, and the final shorter revision segment.
 *
 * SKIPPED (exit 0) when the canonical database is unreachable or not a
 * canonical import. Canonical DB: env QURAN_CANONICAL_DB (default
 * "rafeequl_hifz").
 *
 * Run: php tests/Integration/CanonicalEngineTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/tools/quran-data/lib.php';

use App\Database;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\DivisionType;
use App\Models\RevisionTargetUnit;
use App\Services\AuthService;
use App\Services\HifzCalculationService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

$canonicalDb = getenv('QURAN_CANONICAL_DB');
$canonicalDb = ($canonicalDb === false || $canonicalDb === '') ? 'rafeequl_hifz' : $canonicalDb;
$version = canonicalDatasetVersion($canonicalDb);
if ($version === null) {
    echo "SKIPPED: canonical dataset not reachable in '{$canonicalDb}' (run tools/quran-data/import.php --execute)\n";
    exit(0);
}
if (!str_starts_with($version, 'tanzil-quran-metadata-')) {
    echo "SKIPPED: '{$canonicalDb}' holds non-canonical dataset {$version}\n";
    exit(0);
}

requireTestingDatabase();
loadCanonicalFixture($canonicalDb);

$email = 'canonical-engine-test@example.com';

try {
    $auth = new AuthService();
    $registered = $auth->register(
        ['email' => $email, 'password' => 'Password123!', 'display_name' => 'Canonical Engine Test'],
        '127.0.0.1',
        'test'
    );
    $userId = (int) $registered['user']['id'];

    $hifz = new HifzCalculationService();

    /** @return array<int, array{division_number: int, start_page: int, end_page: int}> */
    $divisionSpans = static function (string $type): array {
        $rows = Database::fetchAll(
            'SELECT division_number, start_page, end_page FROM quran_divisions WHERE division_type = ? ORDER BY division_number',
            [$type]
        );
        return array_map(static fn (array $row): array => [
            'division_number' => (int) $row['division_number'],
            'start_page' => (int) $row['start_page'],
            'end_page' => (int) $row['end_page'],
        ], $rows);
    };
    $pageRow = static function (int $page): array {
        $row = Database::fetch(
            'SELECT start_surah, start_ayah, end_surah, end_ayah FROM quran_pages WHERE page_number = ?',
            [$page]
        );
        if ($row === null) {
            throw new RuntimeException("canonical page {$page} missing");
        }
        return [(int) $row['start_surah'], (int) $row['start_ayah'], (int) $row['end_surah'], (int) $row['end_ayah']];
    };

    $setBoundary = static function (int $boundary) use ($userId): void {
        Database::run(
            'UPDATE memorization_states SET current_boundary_page = ?, last_boundary_changed_at = UTC_TIMESTAMP() WHERE user_id = ?',
            [$boundary, $userId]
        );
    };

    // start = page 1, boundary = last canonical page: the full mushaf
    Database::run(
        'INSERT INTO memorization_states
             (user_id, memorized_start_page, current_boundary_page, status, last_boundary_changed_at, created_at, updated_at)
         VALUES (?, 1, 604, \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        [$userId]
    );

    // === memorized page ranges on real data =================================
    $range = $hifz->memorizedRange($userId);
    checkEquals('full range: total pages from canonical data', 604, $range['total_pages']);
    checkEquals('full range: page count', 604, $range['page_count']);
    checkEquals('full range: percent', 100.0, $range['percent_memorized']);
    checkEquals('full range: boundary', 604, $range['current_boundary_page']);
    checkEquals('current boundary', 604, $hifz->currentBoundary($userId));

    // === page boundaries: engine detail vs canonical page rows =============
    $details = $hifz->currentPageDetails($userId);
    checkEquals('page 604 is the boundary page', 604, $details['page_number']);
    checkEquals('page 604 location matches quran_pages', $pageRow(604), [
        $details['start_surah'],
        $details['start_ayah'],
        $details['end_surah'],
        $details['end_ayah'],
    ]);
    check('page 604 divisions resolve', $details['divisions']['juz']['division_number'] === 30);

    // === membership + dataset edge fail-closed ==============================
    check('page 1 memorized', $hifz->isPageMemorized($userId, 1));
    check('last page memorized', $hifz->isPageMemorized($userId, 604));
    checkThrows(
        'page 605 outside canonical dataset → 422',
        fn () => $hifz->isPageMemorized($userId, 605),
        ValidationException::class,
        'page'
    );

    // === consecutive revision segments + final shorter segment ==============
    $targets = $hifz->revisionTargets($userId, RevisionTargetUnit::Page, 10.0);
    checkCovers('page targets tile the whole mushaf', $targets['segments'], 1, 604);
    checkEquals('page target segment count (604 = 60 x 10 + 4)', 61, $targets['segment_count']);
    checkEquals('first page segment', [1, 10], [$targets['segments'][0]['start_page'], $targets['segments'][0]['end_page']]);
    checkEquals('final segment is shorter (4 pages)', 4, $targets['segments'][60]['page_count']);
    checkEquals('final segment ends at 604', [601, 604], [$targets['segments'][60]['start_page'], $targets['segments'][60]['end_page']]);

    // === juz/hizb/rub relationships: one target per division, exact spans ==
    $enginePairs = [
        'juz' => [RevisionTargetUnit::Juz, 30],
        'hizb' => [RevisionTargetUnit::Hizb, 60],
        'rub' => [RevisionTargetUnit::Rub, 240],
    ];
    foreach ($enginePairs as $type => [$unit, $expectedCount]) {
        $spans = $divisionSpans($type);
        checkEquals("{$type} segment count", $expectedCount, count($spans));
        $unitTargets = $hifz->revisionTargets($userId, $unit, 1.0);
        checkEquals("{$type} targets: one per division", $expectedCount, $unitTargets['segment_count']);
        if ($unitTargets['segment_count'] === count($spans)) {
            $mismatches = [];
            foreach ($spans as $index => $span) {
                $segment = $unitTargets['segments'][$index];
                // Segments are disjoint page windows: a division starts on
                // its canonical start page, except when that page is shared
                // with the previous division (transition inside the page) -
                // then the shared page belongs to the earlier segment.
                $expectedStart = $index === 0
                    ? $span['start_page']
                    : max($span['start_page'], $spans[$index - 1]['end_page'] + 1);
                if ($segment['start_page'] !== $expectedStart || $segment['end_page'] !== $span['end_page']) {
                    $mismatches[] = sprintf(
                        '%s %d: engine [%d..%d] vs canonical start/end [%d..%d] (expected start %d)',
                        $type,
                        $span['division_number'],
                        $segment['start_page'],
                        $segment['end_page'],
                        $span['start_page'],
                        $span['end_page'],
                        $expectedStart
                    );
                }
            }
            check("{$type} segments end on canonical ends; shared start pages go to the earlier division", $mismatches === [], implode(' | ', array_slice($mismatches, 0, 5)));
        }
    }

    // === division coverage over the full mushaf =============================
    foreach ([DivisionType::Juz->value => 30, DivisionType::Hizb->value => 60, DivisionType::Rub->value => 240] as $type => $total) {
        $coverage = $hifz->divisionCoverage($userId, DivisionType::from($type));
        checkEquals("full coverage: all {$type} fully covered", $total, $coverage['fully_covered_count']);
        checkEquals("full coverage: no partial {$type}", 0, $coverage['partially_covered_count']);
    }

    // === 30-page Rabt window ===============================================
    $rabt = $hifz->rabtRange($userId);
    checkEquals('rabt default cap', HifzCalculationService::MAX_RABT_PAGES, $rabt['page_count']);
    checkEquals('rabt window start', 604 - 29, $rabt['start_page']);
    checkEquals('rabt window end', 604, $rabt['end_page']);
    checkEquals('rabt window pages', array_values(range(604 - 29, 604)), $rabt['pages']);

    // === move the boundary to the exact midpoint (arithmetic, not lore) =====
    $setBoundary(302);
    $range = $hifz->memorizedRange($userId);
    checkEquals('midpoint: page count', 302, $range['page_count']);
    checkEquals('midpoint: percent', 50.0, $range['percent_memorized']);
    checkEquals('midpoint: current boundary', 302, $hifz->currentBoundary($userId));

    $details = $hifz->currentPageDetails($userId);
    checkEquals('page 302 location matches quran_pages', $pageRow(302), [
        $details['start_surah'],
        $details['start_ayah'],
        $details['end_surah'],
        $details['end_ayah'],
    ]);

    $rabt = $hifz->rabtRange($userId);
    checkEquals('rabt follows boundary', [302 - 29, 302], [$rabt['start_page'], $rabt['end_page']]);

    $coverage = $hifz->divisionCoverage($userId, DivisionType::Juz);
    check(
        'partial range: coverage never exceeds 30 juz',
        $coverage['fully_covered_count'] + $coverage['partially_covered_count'] <= 30
    );
    check('partial range: at least one full juz behind page 302', $coverage['fully_covered_count'] >= 1);

    $targets = $hifz->revisionTargets($userId, RevisionTargetUnit::Page, 10.0);
    checkCovers('partial range: page targets tile 1..302', $targets['segments'], 1, 302);
    checkEquals('partial range: final shorter segment (302 = 30 x 10 + 2)', 2, $targets['segments'][30]['page_count']);

    // === corrupted boundary fails closed on canonical data ==================
    $setBoundary(999);
    checkThrows(
        'boundary beyond canonical dataset fails closed',
        fn () => $hifz->memorizedRange($userId),
        AppException::class
    );
} finally {
    Database::run('DELETE FROM users WHERE email = ?', [$email]);
    clearCanonicalTables();
    loadSyntheticFixture();
}

exit(summary('CanonicalEngine'));
