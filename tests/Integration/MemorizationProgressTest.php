<?php

declare(strict_types=1);

/**
 * Integration tests: memorization progress (Prompt 09) — establish,
 * next page, explicit marking, confirmed boundary correction, append-only
 * history and dependent recalculation.
 * Run: php tests/Integration/MemorizationProgressTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Exceptions\AppException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Services\HifzCalculationService;
use App\Services\MemorizationProgressService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

/** Counts a user's rows in a whitelisted progress table. */
$countRows = static function (string $table, int $userId): int {
    static $allowed = ['memorization_states', 'memorization_history', 'memorization_boundary_history'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('table not allowed: ' . $table);
    }
    return (int) Database::scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE user_id = ?', [$userId]);
};

$email = 'progress-test@example.com';
$secondEmail = 'progress-test-2@example.com';

// Idempotent re-runs: drop leftovers from a previously interrupted run.
Database::run('DELETE FROM users WHERE email IN (?, ?)', [$email, $secondEmail]);

try {
    $auth = new AuthService();
    $userId = (int) $auth->register(
        ['email' => $email, 'password' => 'Password123!', 'display_name' => 'Progress Test'],
        '127.0.0.1',
        'test'
    )['user']['id'];
    $secondUserId = (int) $auth->register(
        ['email' => $secondEmail, 'password' => 'Password123!', 'display_name' => 'Progress Test 2'],
        '127.0.0.1',
        'test'
    )['user']['id'];

    $progress = new MemorizationProgressService();
    $hifz = new HifzCalculationService();

    // --- not established ----------------------------------------------------
    $state = $progress->state($userId);
    checkEquals('not established: flag', false, $state['established']);
    checkEquals('not established: boundary null', null, $state['current_boundary_page']);
    checkEquals('not established: next page null', null, $state['next_page_to_memorize']);
    checkEquals('not established: progress null', null, $state['progress']);

    checkThrows(
        'mark without state → 404',
        fn () => $progress->markMemorized($userId, 1, 2),
        NotFoundException::class
    );
    checkThrows(
        'correct without state → 404',
        fn () => $progress->correctBoundary($userId, 3, true),
        NotFoundException::class
    );

    // --- reading never writes ----------------------------------------------
    $progress->state($userId);
    $progress->state($userId);
    checkEquals('reading writes no state row', 0, $countRows('memorization_states', $userId));
    checkEquals('reading writes no page history', 0, $countRows('memorization_history', $userId));
    checkEquals('reading writes no boundary history', 0, $countRows('memorization_boundary_history', $userId));

    // --- establish ----------------------------------------------------------
    checkThrows(
        'establish reversed range → 422',
        fn () => $progress->establish($userId, 5, 4),
        ValidationException::class,
        'current_boundary_page'
    );
    checkThrows(
        'establish boundary beyond dataset → 422',
        fn () => $progress->establish($userId, 1, 17),
        ValidationException::class,
        'current_boundary_page'
    );
    checkThrows(
        'establish start beyond dataset → 422',
        fn () => $progress->establish($userId, 17, 17),
        ValidationException::class,
        'memorized_start_page'
    );

    $established = $progress->establish($userId, 1, 4, 'initial state');
    checkEquals('establish: flag', true, $established['state']['established']);
    checkEquals('establish: start page', 1, $established['state']['memorized_start_page']);
    checkEquals('establish: boundary', 4, $established['state']['current_boundary_page']);
    checkEquals('establish: next page to memorize', 5, $established['state']['next_page_to_memorize']);
    checkEquals('establish: boundary change has no previous', null, $established['boundary_change']['previous_boundary_page']);
    checkEquals('establish: boundary change reason', 'manual', $established['boundary_change']['reason']);
    checkEquals('establish: percent recalculated', 25.0, $established['state']['progress']['percent_memorized']);
    checkEquals('establish: one boundary history row', 1, $countRows('memorization_boundary_history', $userId));
    checkEquals('establish fabricates no page history', 0, $countRows('memorization_history', $userId));

    checkThrows(
        're-establish → 422',
        fn () => $progress->establish($userId, 1, 4),
        ValidationException::class,
        'established'
    );

    // --- explicit marking ---------------------------------------------------
    $marked = $progress->markMemorized($userId, 5, 7, 'new pages');
    checkEquals('mark: boundary advanced', 7, $marked['state']['current_boundary_page']);
    checkEquals('mark: next page follows boundary', 8, $marked['state']['next_page_to_memorize']);
    checkEquals('mark: advanced flag', true, $marked['marked']['boundary_advanced']);
    checkEquals('mark: previous boundary reported', 4, $marked['marked']['previous_boundary_page']);
    checkEquals('mark: page count', 3, $marked['marked']['page_count']);
    checkEquals('mark: one history row per page', 3, $countRows('memorization_history', $userId));
    checkEquals('mark: boundary history appended', 2, $countRows('memorization_boundary_history', $userId));
    checkEquals('mark: progress recalculated', 43.75, $marked['state']['progress']['percent_memorized']);
    checkEquals('mark: last page memorized stamped', true, $marked['state']['last_page_memorized_at'] !== null);

    // Re-marking appends again — history records every record event.
    $progress->markMemorized($userId, 7, 7);
    checkEquals('re-mark appends another row', 4, $countRows('memorization_history', $userId));
    checkEquals('re-mark does not move the boundary', 7, $progress->state($userId)['current_boundary_page']);
    checkEquals('re-mark adds no boundary history', 2, $countRows('memorization_boundary_history', $userId));

    checkThrows(
        'mark gap past boundary → 422',
        fn () => $progress->markMemorized($userId, 10, 11),
        ValidationException::class,
        'start_page'
    );
    checkThrows(
        'mark reversed range → 422',
        fn () => $progress->markMemorized($userId, 7, 5),
        ValidationException::class,
        'range'
    );
    checkThrows(
        'mark end beyond dataset → 422',
        fn () => $progress->markMemorized($userId, 8, 17),
        ValidationException::class,
        'end_page'
    );

    // A second user with a different range cannot mark below its own start.
    $progress->establish($secondUserId, 3, 5);
    checkThrows(
        'mark below own range start → 422',
        fn () => $progress->markMemorized($secondUserId, 1, 2),
        ValidationException::class,
        'start_page'
    );
    checkEquals('second user state is independent', 5, $progress->state($secondUserId)['current_boundary_page']);
    checkEquals('second user has its own history', 0, $countRows('memorization_history', $secondUserId));

    // --- confirmed boundary correction --------------------------------------
    checkThrows(
        'correct without confirmation → 422',
        fn () => $progress->correctBoundary($userId, 5, false),
        ValidationException::class,
        'confirm'
    );
    checkThrows(
        'correct boundary beyond dataset → 422',
        fn () => $progress->correctBoundary($userId, 17, true),
        ValidationException::class,
        'current_boundary_page'
    );

    // Dependent system: an active plan reaching past the new range must pause.
    Database::run(
        'INSERT INTO revision_plans
                (user_id, name, target_unit, daily_amount, range_start_page, range_end_page,
                 boundary_page_snapshot, status, current_cycle_number, created_at, updated_at)
         VALUES (?, ?, \'page\', 2.000, 1, 10, 7, \'active\', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        [$userId, 'Plan beyond boundary']
    );
    Database::run(
        'INSERT INTO revision_plans
                (user_id, name, target_unit, daily_amount, range_start_page, range_end_page,
                 boundary_page_snapshot, status, current_cycle_number, created_at, updated_at)
         VALUES (?, ?, \'page\', 1.000, 1, 4, 4, \'active\', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        [$userId, 'Plan inside boundary']
    );

    $historyBefore = $countRows('memorization_history', $userId);
    $corrected = $progress->correctBoundary($userId, 5, true, 'boundary was overstated');
    checkEquals('correct: new boundary', 5, $corrected['state']['current_boundary_page']);
    checkEquals('correct: previous kept in change', 7, $corrected['boundary_change']['previous_boundary_page']);
    checkEquals('correct: one plan paused', 1, $corrected['revision_plans_paused']);
    checkEquals('correct: next page follows', 6, $corrected['state']['next_page_to_memorize']);
    checkEquals('correct: progress recalculated', 31.25, $corrected['state']['progress']['percent_memorized']);
    checkEquals(
        'correct: exceeding plan paused',
        'paused',
        (string) Database::scalar(
            'SELECT status FROM revision_plans WHERE user_id = ? AND name = ?',
            [$userId, 'Plan beyond boundary']
        )
    );
    checkEquals(
        'correct: fitting plan untouched',
        'active',
        (string) Database::scalar(
            'SELECT status FROM revision_plans WHERE user_id = ? AND name = ?',
            [$userId, 'Plan inside boundary']
        )
    );
    checkEquals('correct never rewrites page history', $historyBefore, $countRows('memorization_history', $userId));
    checkEquals('correct appends boundary history', 3, $countRows('memorization_boundary_history', $userId));

    // No-op correction: nothing written, no confirmation-driven side effects.
    $boundaryRows = $countRows('memorization_boundary_history', $userId);
    $noop = $progress->correctBoundary($userId, 5, true);
    checkEquals('no-op: boundary_change null', null, $noop['boundary_change']);
    checkEquals('no-op: no history row appended', $boundaryRows, $countRows('memorization_boundary_history', $userId));
    checkEquals('no-op: state unchanged', 5, $noop['state']['current_boundary_page']);

    // Forward correction grows the boundary without pausing anything.
    $grown = $progress->correctBoundary($userId, 9, true);
    checkEquals('grow: boundary', 9, $grown['state']['current_boundary_page']);
    checkEquals('grow: no plans paused', 0, $grown['revision_plans_paused']);
    checkEquals('grow: boundary history appended', 4, $countRows('memorization_boundary_history', $userId));
    checkEquals(
        'grow: fitting plan still active',
        'active',
        (string) Database::scalar(
            'SELECT status FROM revision_plans WHERE user_id = ? AND name = ?',
            [$userId, 'Plan inside boundary']
        )
    );

    // --- append-only history stays verbatim ---------------------------------
    $rows = Database::fetchAll(
        'SELECT previous_boundary_page, new_boundary_page, reason
           FROM memorization_boundary_history
          WHERE user_id = ?
          ORDER BY id',
        [$userId]
    );
    checkEquals('boundary history keeps every previous value', [
        [null, 4, 'manual'],
        [4, 7, 'manual'],
        [7, 5, 'manual'],
        [5, 9, 'manual'],
    ], array_map(
        static fn (array $row): array => [
            $row['previous_boundary_page'] === null ? null : (int) $row['previous_boundary_page'],
            (int) $row['new_boundary_page'],
            (string) $row['reason'],
        ],
        $rows
    ));

    // --- boundary reaching the dataset edge ---------------------------------
    Database::run(
        'UPDATE memorization_states SET current_boundary_page = 16 WHERE user_id = ?',
        [$userId]
    );
    checkEquals('next page null at dataset edge', null, $progress->state($userId)['next_page_to_memorize']);

    // --- corrupted boundary fails closed ------------------------------------
    Database::run(
        'UPDATE memorization_states SET current_boundary_page = 99 WHERE user_id = ?',
        [$userId]
    );
    checkThrows(
        'state with boundary beyond dataset fails closed',
        fn () => $progress->state($userId),
        AppException::class
    );
    checkThrows(
        'next page with boundary beyond dataset fails closed',
        fn () => $hifz->nextPageToMemorize($userId),
        AppException::class
    );
} finally {
    Database::run('DELETE FROM users WHERE email = ?', [$email]);
    Database::run('DELETE FROM users WHERE email = ?', [$secondEmail]);
    clearCanonicalTables();
}

exit(summary('MemorizationProgress'));
