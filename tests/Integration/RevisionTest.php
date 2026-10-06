<?php

declare(strict_types=1);

/**
 * Integration tests: revision system (Prompt 10) — plans, cycles, daily
 * segments, sessions (open/progress/finish/resume), derived missed days,
 * target changes, growth-aware cycle generation, pause/resume and
 * history preservation (superseded rows are never destroyed).
 * Run: php tests/Integration/RevisionTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\RevisionPlanStatus;
use App\Models\RevisionSessionStatus;
use App\Services\AuthService;
use App\Services\MemorizationProgressService;
use App\Services\RevisionService;
use App\Services\RevisionSessionService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$mainEmail = 'revision-test@example.com';
$bEmail = 'revision-test-2@example.com';
$cEmail = 'revision-test-3@example.com';

// Idempotent re-runs: drop leftovers from a previously interrupted run.
Database::run('DELETE FROM users WHERE email IN (?, ?, ?)', [$mainEmail, $bEmail, $cEmail]);

try {
    $auth = new AuthService();
    $register = static function (string $email, string $name) use ($auth): int {
        return (int) $auth->register(
            ['email' => $email, 'password' => 'Password123!', 'display_name' => $name],
            '127.0.0.1',
            'test'
        )['user']['id'];
    };

    $a = $register($mainEmail, 'Revision A');
    $b = $register($bEmail, 'Revision B');
    $c = $register($cEmail, 'Revision C');

    $progress = new MemorizationProgressService();
    $revision = new RevisionService();
    $sessions = new RevisionSessionService();

    $today = gmdate('Y-m-d');
    $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
    $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));

    $progress->establish($a, 1, 12);
    $progress->establish($b, 1, 8);

    // === plan creation & segmentation =======================================
    checkEquals('plans: starts empty', [], $revision->listPlans($a));

    $p = $revision->createPlan($a, 'page', 4, 'Main cycle');
    $planId = $p['plan']['id'];

    checkEquals('create: status active', 'active', $p['plan']['status']);
    checkEquals('create: name', 'Main cycle', $p['plan']['name']);
    checkEquals('create: daily amount', 4.0, $p['plan']['daily_amount']);
    checkEquals('create: range start', 1, $p['plan']['range_start_page']);
    checkEquals('create: range end', 12, $p['plan']['range_end_page']);
    checkEquals('create: boundary snapshot', 12, $p['plan']['boundary_page_snapshot']);
    checkEquals('create: current cycle number', 1, $p['plan']['current_cycle_number']);
    checkEquals('create: one cycle', 1, count($p['cycles']));
    checkEquals('create: cycle pending', 'pending', $p['current_cycle']['status']);
    checkEquals(
        'create: cycle range',
        [1, 12],
        [$p['current_cycle']['range_start_page'], $p['current_cycle']['range_end_page']]
    );
    checkCovers('create: segments cover the range', $p['segments'], 1, 12);
    checkEquals('create: three segments', 3, count($p['segments']));
    checkEquals(
        'create: all pending',
        ['pending', 'pending', 'pending'],
        array_column($p['segments'], 'status')
    );
    checkEquals(
        'create: dates consecutive',
        [$today, $tomorrow],
        [$p['segments'][0]['scheduled_date'], $p['segments'][1]['scheduled_date']]
    );
    checkEquals('create: first is today', true, $p['segments'][0]['is_today']);
    checkEquals('create: second not today', false, $p['segments'][1]['is_today']);
    checkEquals('create: no missed days', 0, $p['missed_count']);
    checkEquals('create: no open session', null, $p['in_progress_session']);

    checkThrows(
        'second plan → 422 plan',
        fn () => $revision->createPlan($a, 'page', 1, 'Other'),
        ValidationException::class,
        'plan'
    );
    checkThrows(
        'no memorization state → 404',
        fn () => $revision->createPlan($c, 'page', 1, null),
        NotFoundException::class
    );

    // Defaults come from user settings (page / 1.0).
    $pb = $revision->createPlan($b, null, null, null);
    checkEquals('b: default unit', 'page', $pb['plan']['target_unit']);
    checkEquals('b: default amount', 1.0, $pb['plan']['daily_amount']);
    checkEquals('b: own range', [1, 8], [$pb['plan']['range_start_page'], $pb['plan']['range_end_page']]);
    checkEquals('b: eight segments', 8, count($pb['segments']));
    checkEquals('b: name null', null, $pb['plan']['name']);

    // === plan status machine ================================================
    checkThrows(
        'active → completed blocked',
        fn () => $revision->changeStatus($a, $planId, RevisionPlanStatus::Completed),
        ValidationException::class,
        'status'
    );
    $same = $revision->changeStatus($a, $planId, RevisionPlanStatus::Active);
    checkEquals('same status idempotent', 'active', $same['plan']['status']);

    // === sessions: open, progress, finish, resume ===========================
    $seg1 = $p['segments'][0]['id'];

    checkThrows(
        'unknown segment → 404',
        fn () => $sessions->start($a, 999999, null),
        NotFoundException::class
    );

    $s1 = $sessions->start($a, $seg1, null)['session'];
    checkEquals('session: status in progress', 'partial', $s1['status']);
    checkEquals('session: not finished', null, $s1['ended_at']);
    checkEquals('session: total pages', 4, $s1['total_pages']);
    checkEquals('session: no progress yet', 0, $s1['pages_completed']);
    checkEquals('session: no last page', null, $s1['last_page_reached']);
    checkEquals('session: no resume', null, $s1['resumes_session_id']);
    checkEquals('display at start: current page', 1, $s1['current_page']);
    checkEquals('display at start: remaining', 4, $s1['pages_remaining']);
    checkEquals('display at start: percent', 0, $s1['percent_complete']);

    $d = $revision->planDetail($a, $planId);
    checkEquals('start: cycle active', 'active', $d['current_cycle']['status']);
    checkEquals('start: cycle started_at set', true, $d['current_cycle']['started_at'] !== null);
    checkEquals('start: segment active', 'active', $d['segments'][0]['status']);
    checkEquals('detail: open session exposed', $s1['id'], $d['in_progress_session']['id']);
    checkEquals('detail: open session display', 1, $d['in_progress_session']['current_page']);

    checkThrows(
        'second open session → 422 session',
        fn () => $sessions->start($a, $seg1, null),
        ValidationException::class,
        'session'
    );

    $pr = $sessions->progress($a, $s1['id'], 3)['session'];
    checkEquals('progress: pages derived', 3, $pr['pages_completed']);
    checkEquals('progress: last page stored', 3, $pr['last_page_reached']);
    checkEquals('display after progress: current page', 4, $pr['current_page']);
    checkEquals('display after progress: remaining', 1, $pr['pages_remaining']);
    checkEquals('display after progress: percent', 75, $pr['percent_complete']);

    checkThrows(
        'progress backward → 422',
        fn () => $sessions->progress($a, $s1['id'], 2),
        ValidationException::class,
        'last_page_reached'
    );
    checkThrows(
        'progress outside segment → 422',
        fn () => $sessions->progress($a, $s1['id'], 99),
        ValidationException::class,
        'last_page_reached'
    );

    $f1 = $sessions->finish($a, $s1['id'], RevisionSessionStatus::Partial, 3, null, 'phone call');
    checkEquals('finish partial: status', 'partial', $f1['session']['status']);
    checkEquals('finish partial: pages', 3, $f1['session']['pages_completed']);
    checkEquals('finish partial: last page', 3, $f1['session']['last_page_reached']);
    checkEquals('finish partial: notes kept', 'phone call', $f1['session']['notes']);
    check(
        'finish partial: duration recorded',
        is_int($f1['session']['duration_seconds']) && $f1['session']['duration_seconds'] >= 0
    );
    checkEquals('finish partial: segment stays active', 'active', $f1['segment']['status']);
    checkEquals('finish partial: cycle not settled', false, $f1['cycle_completed']);
    checkEquals('display partial: current page', 4, $f1['session']['current_page']);
    checkEquals('display partial: remaining', 1, $f1['session']['pages_remaining']);
    checkEquals('display partial: percent', 75, $f1['session']['percent_complete']);

    checkThrows(
        'finish again → 422 status',
        fn () => $sessions->finish($a, $s1['id'], RevisionSessionStatus::Completed, null, null, null),
        ValidationException::class,
        'status'
    );
    checkThrows(
        'progress finished session → 422',
        fn () => $sessions->progress($a, $s1['id'], 4),
        ValidationException::class,
        'status'
    );

    // --- resume rules -------------------------------------------------------
    checkThrows(
        'resume unknown session → 422',
        fn () => $sessions->start($a, $seg1, 999999),
        ValidationException::class,
        'resumes_session_id'
    );

    // User B's session as cross-user resume bait (also covers interrupted).
    $bseg1 = $pb['segments'][0]['id'];
    $bs = $sessions->start($b, $bseg1, null)['session'];
    $bf = $sessions->finish($b, $bs['id'], RevisionSessionStatus::Interrupted, 1, 'incoming call', null);
    checkEquals('finish interrupted: status', 'interrupted', $bf['session']['status']);
    checkEquals('finish interrupted: reason kept', 'incoming call', $bf['session']['interruption_reason']);

    checkThrows(
        'resume other user session → 422',
        fn () => $sessions->start($a, $seg1, $bs['id']),
        ValidationException::class,
        'resumes_session_id'
    );

    $s2 = $sessions->start($a, $seg1, $s1['id'])['session'];
    checkEquals('resume: links prior session', $s1['id'], $s2['resumes_session_id']);
    checkEquals('resume: inherits pages', 3, $s2['pages_completed']);
    checkEquals('resume: inherits last page', 3, $s2['last_page_reached']);

    $f2 = $sessions->finish($a, $s2['id'], RevisionSessionStatus::Completed, null, null, null);
    checkEquals('finish completed: forces segment end', 4, $f2['session']['last_page_reached']);
    checkEquals('finish completed: full pages', 4, $f2['session']['pages_completed']);
    checkEquals('finish completed: segment completed', 'completed', $f2['segment']['status']);
    check('finish completed: completed_at set', $f2['segment']['completed_at'] !== null);
    checkEquals('finish completed: cycle still open', false, $f2['cycle_completed']);
    checkEquals('display completed: current page', 4, $f2['session']['current_page']);
    checkEquals('display completed: remaining', 0, $f2['session']['pages_remaining']);
    checkEquals('display completed: percent', 100, $f2['session']['percent_complete']);

    checkThrows(
        'start on completed segment → 422',
        fn () => $sessions->start($a, $seg1, null),
        ValidationException::class,
        'segment'
    );

    // === missed days (derived from scheduled_date) ==========================
    Database::run(
        'UPDATE revision_segments s
            JOIN revision_cycles c ON c.id = s.cycle_id
            JOIN revision_plans p ON p.id = c.plan_id
            SET s.scheduled_date = ?
          WHERE p.user_id = ? AND c.cycle_number = 1 AND s.segment_number = 2',
        [$yesterday, $a]
    );

    $d = $revision->planDetail($a, $planId);
    checkEquals('missed: counted', 1, $d['missed_count']);
    checkEquals('missed: flag', true, $d['segments'][1]['is_missed']);
    checkEquals('missed: today flag cleared', false, $d['segments'][1]['is_today']);

    $seg2 = $d['segments'][1]['id'];
    $s3 = $sessions->start($a, $seg2, null)['session'];
    $f3 = $sessions->finish($a, $s3['id'], RevisionSessionStatus::Completed, null, null, null);
    checkEquals('late completion: allowed', 'completed', $f3['segment']['status']);
    $d = $revision->planDetail($a, $planId);
    checkEquals('late completion: missed cleared', 0, $d['missed_count']);

    // === target change preserves finished work ==============================
    $ut = $revision->updateTarget($a, $planId, 'page', 2);
    checkEquals('target: regenerated count', 2, $ut['segments_regenerated']);
    checkEquals('target: amount stored', 2.0, $ut['plan']['daily_amount']);
    checkEquals('target: finished segment kept', $seg1, $ut['segments'][0]['id']);
    checkEquals('target: kept status', 'completed', $ut['segments'][0]['status']);
    checkEquals('target: second kept', $seg2, $ut['segments'][1]['id']);
    checkEquals('target: four segments now', 4, count($ut['segments']));
    checkEquals(
        'target: numbering continues',
        [3, 4],
        [$ut['segments'][2]['segment_number'], $ut['segments'][3]['segment_number']]
    );
    checkEquals('target: tail split start', [9, 10], [$ut['segments'][2]['start_page'], $ut['segments'][2]['end_page']]);
    checkEquals('target: tail split end', [11, 12], [$ut['segments'][3]['start_page'], $ut['segments'][3]['end_page']]);
    checkEquals('target: regenerated from today', $today, $ut['segments'][2]['scheduled_date']);
    checkEquals('target: cycle segment count', 4, $ut['current_cycle']['segment_count']);

    // Finish the regenerated tail → cycle settles.
    $sT = $sessions->start($a, $ut['segments'][2]['id'], null)['session'];
    $sessions->finish($a, $sT['id'], RevisionSessionStatus::Completed, null, null, null);
    $afterTail = $revision->skipSegment($a, $ut['segments'][3]['id']);
    checkEquals('tail skipped', 'skipped', $afterTail['segments'][3]['status']);
    checkEquals('cycle settles', 'completed', $afterTail['current_cycle']['status']);
    checkEquals('plan stays active', 'active', $afterTail['plan']['status']);

    $again = $revision->skipSegment($a, $ut['segments'][3]['id']);
    checkEquals('skip again idempotent', 'skipped', $again['segments'][3]['status']);
    checkThrows(
        'skip completed segment → 422',
        fn () => $revision->skipSegment($a, $seg1),
        ValidationException::class,
        'status'
    );

    // Config-only change once nothing is pending.
    $cfg = $revision->updateTarget($a, $planId, 'page', 5);
    checkEquals('settled cycle: no regeneration', 0, $cfg['segments_regenerated']);
    checkEquals('settled cycle: config stored', 5.0, $cfg['plan']['daily_amount']);
    checkEquals('settled cycle: segments untouched', 4, count($cfg['segments']));

    // === memorization growth → next cycle uses the current boundary =========
    $progress->markMemorized($a, 13, 14);
    checkEquals('growth: boundary', 14, $progress->state($a)['current_boundary_page']);

    $g = $revision->generateCycle($a, $planId, false);
    $cycle2 = $g['current_cycle'];
    checkEquals('cycle2: number', 2, $cycle2['cycle_number']);
    checkEquals('cycle2: status pending', 'pending', $cycle2['status']);
    checkEquals('cycle2: current range', [1, 14], [$cycle2['range_start_page'], $cycle2['range_end_page']]);
    checkEquals('cycle2: boundary snapshot', 14, $cycle2['boundary_page_snapshot']);
    checkEquals('plan: current cycle number', 2, $g['plan']['current_cycle_number']);
    checkCovers('cycle2: segments cover grown range', $g['segments'], 1, 14);
    checkEquals('cycle2: three segments at target 5', 3, count($g['segments']));
    checkEquals('cycle2: final segment smaller', 4, $g['segments'][2]['page_count']);
    checkEquals('history: two cycles kept', 2, count($g['cycles']));
    checkEquals('history: cycle1 still completed', 'completed', $g['cycles'][0]['status']);

    checkThrows(
        'generate on pending cycle → 422',
        fn () => $revision->generateCycle($a, $planId, false),
        ValidationException::class,
        'cycle'
    );

    $sup = $revision->generateCycle($a, $planId, true);
    $cycle3 = $sup['current_cycle'];
    checkEquals('regenerate: superseded id', $cycle2['id'], $sup['superseded_cycle_id']);
    checkEquals('regenerate: cycle3 number', 3, $cycle3['cycle_number']);
    checkEquals('regenerate: three cycles kept', 3, count($sup['cycles']));
    checkEquals('regenerate: cycle2 superseded', 'superseded', $sup['cycles'][1]['status']);
    checkEquals(
        'regenerate: cycle2 segments kept',
        3,
        (int) Database::scalar('SELECT COUNT(*) FROM revision_segments WHERE cycle_id = ?', [$cycle2['id']])
    );

    // === target change with an entirely pending cycle =======================
    $ut2 = $revision->updateTarget($a, $planId, 'page', 6);
    checkEquals('all-pending: regenerated', 3, $ut2['segments_regenerated']);
    checkEquals('all-pending: numbering restarts', [1, 2, 3], array_column($ut2['segments'], 'segment_number'));
    checkCovers('all-pending: covers range', $ut2['segments'], 1, 14);
    checkEquals('all-pending: dates from today', $today, $ut2['segments'][0]['scheduled_date']);
    checkEquals('all-pending: segment count', 3, $ut2['current_cycle']['segment_count']);

    // === pause / resume, range fit, regeneration ============================
    $c3seg1 = $ut2['segments'][0]['id'];
    $s4 = $sessions->start($a, $c3seg1, null)['session'];

    checkThrows(
        'pause with open session → 422 session',
        fn () => $revision->changeStatus($a, $planId, RevisionPlanStatus::Paused),
        ValidationException::class,
        'session'
    );

    $sessions->finish($a, $s4['id'], RevisionSessionStatus::Partial, 3, null, null);
    $paused = $revision->changeStatus($a, $planId, RevisionPlanStatus::Paused);
    checkEquals('pause: status', 'paused', $paused['plan']['status']);

    checkThrows(
        'session on paused plan → 422 plan',
        fn () => $sessions->start($a, $ut2['segments'][1]['id'], null),
        ValidationException::class,
        'plan'
    );

    $resumed = $revision->changeStatus($a, $planId, RevisionPlanStatus::Active);
    checkEquals('resume: status', 'active', $resumed['plan']['status']);

    // Shrink the boundary → the plan no longer fits → auto-paused.
    $progress->correctBoundary($a, 6, true);
    $afterShrink = $revision->planDetail($a, $planId);
    checkEquals('shrink: plan auto-paused', 'paused', $afterShrink['plan']['status']);
    checkEquals('shrink: plan range snapshot kept', 12, $afterShrink['plan']['range_end_page']);

    checkThrows(
        'resume non-fitting cycle → 422 cycle',
        fn () => $revision->changeStatus($a, $planId, RevisionPlanStatus::Active),
        ValidationException::class,
        'cycle'
    );

    $regen = $revision->generateCycle($a, $planId, true);
    checkEquals('regen after shrink: range', [1, 6], [$regen['current_cycle']['range_start_page'], $regen['current_cycle']['range_end_page']]);
    checkCovers('regen after shrink: covers', $regen['segments'], 1, 6);
    checkEquals('regen after shrink: one segment at target 6', 1, count($regen['segments']));
    checkEquals('regen after shrink: superseded cycle3', $cycle3['id'], $regen['superseded_cycle_id']);
    checkEquals('regen after shrink: four cycles', 4, count($regen['cycles']));

    $resumed2 = $revision->changeStatus($a, $planId, RevisionPlanStatus::Active);
    checkEquals('resume after regenerate', 'active', $resumed2['plan']['status']);

    // === plan completion ====================================================
    $toPaused = $revision->changeStatus($a, $planId, RevisionPlanStatus::Paused);
    checkEquals('final pause', 'paused', $toPaused['plan']['status']);
    $completedPlan = $revision->changeStatus($a, $planId, RevisionPlanStatus::Completed);
    checkEquals('plan completed', 'completed', $completedPlan['plan']['status']);
    check('plan completed_at set', $completedPlan['plan']['completed_at'] !== null);

    checkThrows(
        'completed → target change blocked',
        fn () => $revision->updateTarget($a, $planId, 'page', 2),
        ValidationException::class,
        'status'
    );
    checkThrows(
        'completed → generate blocked',
        fn () => $revision->generateCycle($a, $planId, false),
        ValidationException::class,
        'status'
    );
    checkThrows(
        'completed → active blocked',
        fn () => $revision->changeStatus($a, $planId, RevisionPlanStatus::Active),
        ValidationException::class,
        'status'
    );
    checkThrows(
        'session on completed plan → 422 plan',
        fn () => $sessions->start($a, $regen['segments'][0]['id'], null),
        ValidationException::class,
        'plan'
    );

    // A new plan is allowed once the old one is completed.
    $p2 = $revision->createPlan($a, 'page', 2, 'Second');
    checkEquals('new plan: active', 'active', $p2['plan']['status']);
    checkEquals('new plan: current range', [1, 6], [$p2['plan']['range_start_page'], $p2['plan']['range_end_page']]);
    checkCovers('new plan: segments', $p2['segments'], 1, 6);
    checkEquals('new plan: three segments', 3, count($p2['segments']));

    // === ownership isolation =================================================
    checkThrows(
        'other user plan → 404',
        fn () => $revision->planDetail($b, $planId),
        NotFoundException::class
    );
    checkThrows(
        'other user cycle → 404',
        fn () => $revision->cycleDetail($b, $cycle3['id']),
        NotFoundException::class
    );
    checkThrows(
        'other user segment skip → 404',
        fn () => $revision->skipSegment($b, $c3seg1),
        NotFoundException::class
    );
    checkThrows(
        'other user session progress → 404',
        fn () => $sessions->progress($b, $s4['id'], 3),
        NotFoundException::class
    );
    checkEquals('b: own plans only', 1, count($revision->listPlans($b)));
    checkEquals('b: own plan id', $pb['plan']['id'], $revision->listPlans($b)[0]['id']);

    // === history ============================================================
    $histA = $sessions->listSessions($a, 3)['sessions'];
    checkEquals('history: limit respected', 3, count($histA));
    checkEquals('history: newest first', $s4['id'], $histA[0]['id']);
    check('history: segment context attached', isset($histA[0]['segment']['start_page']));
    checkEquals('history: display current page', 4, $histA[0]['current_page']);
    checkEquals('history: display percent', 50, $histA[0]['percent_complete']);
    $histB = $sessions->listSessions($b, 10)['sessions'];
    checkEquals('history: b isolated', 1, count($histB));
    checkEquals('history: b session id', $bs['id'], $histB[0]['id']);
    checkEquals('history: interruption reason readable', 'incoming call', $histB[0]['interruption_reason']);

    // Superseded cycles stay readable (history is never destroyed).
    $supDetail = $revision->cycleDetail($a, $cycle2['id']);
    checkEquals('superseded cycle readable', 'superseded', $supDetail['cycle']['status']);
    checkEquals('superseded cycle segments readable', 3, count($supDetail['segments']));
} finally {
    Database::run('DELETE FROM users WHERE email IN (?, ?, ?)', [$mainEmail, $bEmail, $cEmail]);
}

exit(summary('Revision'));
