<?php

declare(strict_types=1);

/**
 * Integration tests: user-facing Hifz calculations (memorized range,
 * boundary, current page, division coverage, revision targets).
 * Run: php tests/Integration/HifzCalculationTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Exceptions\AppException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\DivisionType;
use App\Models\RevisionTargetUnit;
use App\Services\AuthService;
use App\Services\HifzCalculationService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$email = 'engine-test@example.com';

try {
    $auth = new AuthService();
    $registered = $auth->register(
        ['email' => $email, 'password' => 'Password123!', 'display_name' => 'Engine Test'],
        '127.0.0.1',
        'test'
    );
    $userId = (int) $registered['user']['id'];

    $hifz = new HifzCalculationService();

    checkThrows(
        'boundary without memorization state → 404',
        fn () => $hifz->currentBoundary($userId),
        NotFoundException::class
    );

    Database::run(
        'INSERT INTO memorization_states
             (user_id, memorized_start_page, current_boundary_page, status, last_boundary_changed_at, created_at, updated_at)
         VALUES (?, 1, 5, \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        [$userId]
    );

    // --- memorized range ----------------------------------------------------
    $range = $hifz->memorizedRange($userId);
    checkEquals('range: start', 1, $range['memorized_start_page']);
    checkEquals('range: boundary', 5, $range['current_boundary_page']);
    checkEquals('range: page count', 5, $range['page_count']);
    checkEquals('range: total pages', 16, $range['total_pages']);
    checkEquals('range: percent', 31.25, $range['percent_memorized']);
    checkEquals('range: status', 'active', $range['status']);

    checkEquals('current boundary', 5, $hifz->currentBoundary($userId));

    // --- current page -------------------------------------------------------
    $details = $hifz->currentPageDetails($userId);
    checkEquals('current page number', 5, $details['page_number']);
    checkEquals('current page location', [2, 1, 2, 2], [
        $details['start_surah'],
        $details['start_ayah'],
        $details['end_surah'],
        $details['end_ayah'],
    ]);
    checkEquals('current page divisions', ['juz' => 1, 'hizb' => 2, 'rub' => 3], [
        'juz' => $details['divisions']['juz']['division_number'],
        'hizb' => $details['divisions']['hizb']['division_number'],
        'rub' => $details['divisions']['rub']['division_number'],
    ]);

    // --- memorized membership ----------------------------------------------
    check('page 1 memorized', $hifz->isPageMemorized($userId, 1));
    check('boundary page memorized', $hifz->isPageMemorized($userId, 5));
    check('page 6 not memorized', !$hifz->isPageMemorized($userId, 6));
    checkThrows(
        'page outside dataset → 422',
        fn () => $hifz->isPageMemorized($userId, 17),
        ValidationException::class,
        'page'
    );

    // --- revision targets (never past the boundary) ------------------------
    $targets = $hifz->revisionTargets($userId, RevisionTargetUnit::Page, 2.0);
    checkEquals('page target segments', [[1, 2], [3, 4], [5, 5]], array_map(
        static fn (array $segment): array => [$segment['start_page'], $segment['end_page']],
        $targets['segments']
    ));
    checkEquals('page target segment count', 3, $targets['segment_count']);
    checkEquals('final segment may be smaller', 1, $targets['segments'][2]['page_count']);

    $targets = $hifz->revisionTargets($userId, RevisionTargetUnit::Hizb, 1.0);
    checkEquals('hizb target clamped to boundary', [[1, 4], [5, 5]], array_map(
        static fn (array $segment): array => [$segment['start_page'], $segment['end_page']],
        $targets['segments']
    ));

    $targets = $hifz->revisionTargets($userId, RevisionTargetUnit::Juz, 1.0);
    checkEquals('juz target never past boundary', [[1, 5]], array_map(
        static fn (array $segment): array => [$segment['start_page'], $segment['end_page']],
        $targets['segments']
    ));

    $targets = $hifz->revisionTargets($userId, RevisionTargetUnit::Rub, 1.0);
    checkEquals('rub target segments', [[1, 2], [3, 4], [5, 5]], array_map(
        static fn (array $segment): array => [$segment['start_page'], $segment['end_page']],
        $targets['segments']
    ));

    checkThrows(
        'invalid target amount → 422',
        fn () => $hifz->revisionTargets($userId, RevisionTargetUnit::Page, 0.0),
        ValidationException::class,
        'amount'
    );

    // --- division coverage --------------------------------------------------
    $coverage = $hifz->divisionCoverage($userId, DivisionType::Hizb);
    checkEquals('hizb: fully covered count', 1, $coverage['fully_covered_count']);
    checkEquals('hizb: partially covered count', 1, $coverage['partially_covered_count']);
    checkEquals('hizb: full is hizb 1', 1, (int) $coverage['fully_covered'][0]['division_number']);
    checkEquals('hizb: partial is hizb 2', 2, (int) $coverage['partially_covered'][0]['division_number']);

    $coverage = $hifz->divisionCoverage($userId, DivisionType::Juz);
    checkEquals('juz: no fully covered juz yet', 0, $coverage['fully_covered_count']);
    checkEquals('juz: juz 1 partially covered', [1], array_map(
        static fn (array $row): int => (int) $row['division_number'],
        $coverage['partially_covered']
    ));

    $coverage = $hifz->divisionCoverage($userId, DivisionType::Rub);
    checkEquals('rub: fully covered', [1, 2], array_map(
        static fn (array $row): int => (int) $row['division_number'],
        $coverage['fully_covered']
    ));
    checkEquals('rub: partially covered', [3], array_map(
        static fn (array $row): int => (int) $row['division_number'],
        $coverage['partially_covered']
    ));

    // --- boundary movement recalculates everything -------------------------
    Database::run(
        'UPDATE memorization_states SET current_boundary_page = 16, last_boundary_changed_at = UTC_TIMESTAMP() WHERE user_id = ?',
        [$userId]
    );

    $range = $hifz->memorizedRange($userId);
    checkEquals('after move: page count', 16, $range['page_count']);
    checkEquals('after move: percent', 100.0, $range['percent_memorized']);

    $targets = $hifz->revisionTargets($userId, RevisionTargetUnit::Page, 5.0);
    checkEquals('after move: page target segments', [[1, 5], [6, 10], [11, 15], [16, 16]], array_map(
        static fn (array $segment): array => [$segment['start_page'], $segment['end_page']],
        $targets['segments']
    ));

    $coverage = $hifz->divisionCoverage($userId, DivisionType::Juz);
    checkEquals('after move: both juz fully covered', 2, $coverage['fully_covered_count']);

    // --- corrupted boundary fails closed -----------------------------------
    Database::run(
        'UPDATE memorization_states SET current_boundary_page = 99 WHERE user_id = ?',
        [$userId]
    );
    checkThrows(
        'boundary beyond dataset fails closed',
        fn () => $hifz->memorizedRange($userId),
        AppException::class
    );
} finally {
    Database::run('DELETE FROM users WHERE email = ?', [$email]);
    clearCanonicalTables();
}

exit(summary('HifzCalculation'));
