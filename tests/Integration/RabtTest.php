<?php

declare(strict_types=1);

/**
 * Integration tests: rolling Rabt (ربط) window (Prompt 12) — derivation
 * from the memorized range, automatic sliding whenever the boundary
 * moves (mark / correction), the fewer-than-30 fallback and the
 * never-unmemorized invariant.
 * Run: php tests/Integration/RabtTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Exceptions\NotFoundException;
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

$aEmail = 'rabt-test@example.com';
$bEmail = 'rabt-test-2@example.com';

// Idempotent re-runs: drop leftovers from a previously interrupted run.
Database::run('DELETE FROM users WHERE email IN (?, ?)', [$aEmail, $bEmail]);

try {
    $auth = new AuthService();
    $register = static function (string $email, string $name) use ($auth): int {
        return (int) $auth->register(
            ['email' => $email, 'password' => 'Password123!', 'display_name' => $name],
            '127.0.0.1',
            'test'
        )['user']['id'];
    };

    $a = $register($aEmail, 'Rabt A');
    $b = $register($bEmail, 'Rabt B');

    $progress = new MemorizationProgressService();
    $hifz = new HifzCalculationService();

    // === no state fails closed =============================================
    checkThrows(
        'no memorization state → 404',
        fn () => $hifz->rabtRange($a),
        NotFoundException::class
    );

    // === fewer than 30 memorized pages → use them all ======================
    $progress->establish($a, 1, 10);
    $r = $hifz->rabtRange($a);
    checkEquals('establish: start at memorized start', 1, $r['start_page']);
    checkEquals('establish: end at boundary', 10, $r['end_page']);
    checkEquals('establish: count', 10, $r['page_count']);
    checkEquals('establish: cap reported', 30, $r['max_pages']);
    checkEquals('establish: memorized page count', 10, $r['memorized_page_count']);
    checkEquals('establish: pages listed', array_values(range(1, 10)), $r['pages']);

    $small = $hifz->rabtRange($a, 5);
    checkEquals('window seam: start', 6, $small['start_page']);
    checkEquals('window seam: end', 10, $small['end_page']);
    checkEquals('window seam: count', 5, $small['page_count']);

    // === newest memorized page enters, oldest leaves =======================
    $progress->markMemorized($a, 11, 13);
    $r = $hifz->rabtRange($a);
    checkEquals('after mark: still under the cap', [1, 13], [$r['start_page'], $r['end_page']]);
    checkEquals('after mark: memorized count grows', 13, $r['memorized_page_count']);
    $small = $hifz->rabtRange($a, 5);
    checkEquals('after mark: window slides forward', [9, 13], [$small['start_page'], $small['end_page']]);
    checkEquals('after mark: window pages', array_values(range(9, 13)), $small['pages']);

    $progress->markMemorized($a, 14, 16);
    $r = $hifz->rabtRange($a);
    checkEquals('dataset edge: whole range still under cap', [1, 16], [$r['start_page'], $r['end_page']]);
    checkEquals('dataset edge: count', 16, $r['page_count']);
    $small = $hifz->rabtRange($a, 5);
    checkEquals('dataset edge: window slides again', [12, 16], [$small['start_page'], $small['end_page']]);

    // === invariants: memorized only, inside the window, capped =============
    $inside = true;
    foreach ($small['pages'] as $page) {
        if (!is_int($page) || $page < $small['start_page'] || $page > $small['end_page']) {
            $inside = false;
        }
    }
    check('window pages are inside the window', $inside);
    checkEquals('page_count matches pages', $small['page_count'], count($small['pages']));
    check('window never exceeds the cap', count($r['pages']) <= HifzCalculationService::MAX_RABT_PAGES);
    checkEquals(
        'window never below memorized start',
        true,
        $r['start_page'] >= 1
    );

    // === boundary shrink removes pages from the window =====================
    $progress->correctBoundary($a, 12, true);
    $r = $hifz->rabtRange($a);
    checkEquals('shrink: end follows the boundary', 12, $r['end_page']);
    checkEquals('shrink: whole range', [1, 12], [$r['start_page'], $r['end_page']]);
    $small = $hifz->rabtRange($a, 5);
    checkEquals('shrink: window follows', [8, 12], [$small['start_page'], $small['end_page']]);
    check('shrink: no unmemorized page kept', max($small['pages']) <= 12);
    checkEquals('shrink: memorized count updated', 12, $r['memorized_page_count']);

    // === growth moves the window forward again =============================
    $progress->markMemorized($a, 13, 14);
    $small = $hifz->rabtRange($a, 5);
    checkEquals('grow: window follows the boundary', [10, 14], [$small['start_page'], $small['end_page']]);
    $r = $hifz->rabtRange($a);
    checkEquals('grow: whole range', [1, 14], [$r['start_page'], $r['end_page']]);

    // === starting near the boundary → everything available =================
    $progress->establish($b, 14, 16);
    $rb = $hifz->rabtRange($b);
    checkEquals('b: start kept (fewer than 30)', 14, $rb['start_page']);
    checkEquals('b: count', 3, $rb['page_count']);
    checkEquals('b: pages', [14, 15, 16], $rb['pages']);

    // === ownership isolation ===============================================
    $ra = $hifz->rabtRange($a);
    checkEquals('isolation: a untouched by b', 14, $ra['end_page']);
    checkEquals('isolation: a window size', 14, $ra['page_count']);
    check('isolation: windows differ', $ra['start_page'] !== $rb['start_page']);
} finally {
    Database::run('DELETE FROM users WHERE email IN (?, ?)', [$aEmail, $bEmail]);
}

exit(summary('Rabt'));
