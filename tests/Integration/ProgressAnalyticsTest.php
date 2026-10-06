<?php

declare(strict_types=1);

/**
 * Integration tests: progress analytics (Prompt 22) — the read-only
 * dashboard snapshot: memorization figures, revision consistency window
 * (14 days), completed segments, ربط tasks, flip-card counts, weekly
 * productivity (7 days) and the merged recent-activity feed.
 *
 * Design rules verified here: the snapshot never writes, windows are
 * computed against the server's UTC date, all arithmetic is server-side,
 * and one user's activity never leaks into another user's numbers.
 *
 * Run: php tests/Integration/ProgressAnalyticsTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Services\AuthService;
use App\Services\MemorizationProgressService;
use App\Services\ProgressAnalyticsService;
use App\Services\RevisionService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$aEmail = 'progress-test@example.com';
$bEmail = 'progress-test-2@example.com';

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

    $a = $register($aEmail, 'Progress A');
    $b = $register($bEmail, 'Progress B');

    $svc = new ProgressAnalyticsService();
    $progress = new MemorizationProgressService();
    $revision = new RevisionService();

    // Fixed UTC day labels relative to now (windows use the server UTC date).
    $yesterday = gmdate('Y-m-d', time() - 86400);
    $threeDaysAgo = gmdate('Y-m-d', time() - 3 * 86400);
    $fiveDaysAgo = gmdate('Y-m-d', time() - 5 * 86400);
    $tenDaysAgo = gmdate('Y-m-d', time() - 10 * 86400);
    $twentyDaysAgo = gmdate('Y-m-d', time() - 20 * 86400);
    $now = gmdate('Y-m-d H:i:s');
    $nowMinusHour = gmdate('Y-m-d H:i:s', time() - 3600);
    $nowMinusTwoHours = gmdate('Y-m-d H:i:s', time() - 7200);

    // === fresh user: everything zero, nothing fabricated ==================
    $fresh = $svc->summary($a);
    checkEquals('fresh: window is 14 days', 14, $fresh['consistency_window_days']);
    check('fresh: generated_at present', is_string($fresh['generated_at']) && $fresh['generated_at'] !== '');
    checkEquals('fresh: not established', false, $fresh['memorization']['established']);
    checkEquals('fresh: page count null', null, $fresh['memorization']['page_count']);
    checkEquals('fresh: percent null', null, $fresh['memorization']['percent_memorized']);
    checkEquals('fresh: boundary null', null, $fresh['memorization']['current_boundary_page']);
    checkEquals('fresh: memorized in window 0', 0, $fresh['memorization']['pages_last_14_days']);
    checkEquals('fresh: sessions in window 0', 0, $fresh['revision']['sessions_last_14_days']);
    checkEquals('fresh: sessions total 0', 0, $fresh['revision']['sessions_total']);
    checkEquals('fresh: active days 0', 0, $fresh['revision']['active_days_last_14_days']);
    checkEquals('fresh: consistency 0.0', 0.0, $fresh['revision']['consistency_percent']);
    checkEquals('fresh: segments 0/0', [0, 0], [$fresh['revision']['segments_completed'], $fresh['revision']['segments_total']]);
    checkEquals('fresh: rabt tasks 0', 0, $fresh['rabt']['tasks_completed_last_14_days']);
    checkEquals('fresh: no cards', [0, 0, 0, 0, 0, 0], [
        $fresh['flip_cards']['active'],
        $fresh['flip_cards']['in_review'],
        $fresh['flip_cards']['mastered'],
        $fresh['flip_cards']['archived'],
        $fresh['flip_cards']['total'],
        $fresh['flip_cards']['pending_review'],
    ]);
    checkEquals('fresh: productivity window 7', 7, $fresh['productivity']['window_days']);
    checkEquals('fresh: planned 0', 0, $fresh['productivity']['planned']);
    checkEquals('fresh: completed 0', 0, $fresh['productivity']['completed']);
    checkEquals('fresh: week percent 0.0', 0.0, $fresh['productivity']['percent']);
    checkEquals('fresh: no recent activity', [], $fresh['recent_activity']);

    // === memorization: establish 1-10, mark 11-15 =========================
    $progress->establish($a, 1, 10);
    $progress->markMemorized($a, 11, 15);

    $mem = $svc->summary($a)['memorization'];
    checkEquals('mem: established', true, $mem['established']);
    checkEquals('mem: range start', 1, $mem['memorized_start_page']);
    checkEquals('mem: boundary advanced to 15', 15, $mem['current_boundary_page']);
    checkEquals('mem: page count 15', 15, $mem['page_count']);
    checkEquals('mem: percent = 15/16 pages', 93.75, $mem['percent_memorized']);
    checkEquals('mem: 5 pages in the 14-day window', 5, $mem['pages_last_14_days']);

    // The snapshot is strictly read-only.
    $historyRows = static fn (): int =>
        (int) Database::scalar('SELECT COUNT(*) FROM memorization_history WHERE user_id = ?', [$a]);
    $before = $historyRows();
    $svc->summary($a);
    checkEquals('read-only: summary writes nothing', $before, $historyRows());

    // === revision: plan segments + backdated sessions =====================
    $plan = $revision->createPlan($a, 'page', 5, 'Analytics plan');
    $segments = $plan['segments'];
    checkEquals('plan: three segments of 5 pages', 3, count($segments));
    $segmentId = (int) $segments[0]['id'];

    Database::run(
        "UPDATE revision_segments SET status = 'completed', completed_at = UTC_TIMESTAMP()
          WHERE id = ?",
        [$segments[2]['id']]
    );

    $insertSession = static function (string $startedAt, string $status, int $completed, int $total) use ($a, $segmentId): void {
        Database::run(
            "INSERT INTO revision_sessions
                (segment_id, user_id, resumes_session_id, status, total_pages,
                 pages_completed, last_page_reached, started_at, ended_at,
                 duration_seconds, interruption_reason, notes, created_at, updated_at)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 60, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$segmentId, $a, $status, $total, $completed, $completed, $startedAt, $startedAt]
        );
    };

    // Inside the 14-day window: yesterday x2 (one day) + 3 days ago.
    $insertSession($yesterday . ' 09:00:00', 'completed', 5, 5);
    $insertSession($yesterday . ' 18:00:00', 'partial', 3, 5);
    $insertSession($threeDaysAgo . ' 10:00:00', 'completed', 5, 5);
    // Outside the window (20 days ago): counted only in the lifetime total.
    $insertSession($twentyDaysAgo . ' 10:00:00', 'completed', 4, 5);

    $rev = $svc->summary($a)['revision'];
    checkEquals('revision: 3 sessions in window', 3, $rev['sessions_last_14_days']);
    checkEquals('revision: 4 sessions lifetime', 4, $rev['sessions_total']);
    checkEquals('revision: 2 distinct active days', 2, $rev['active_days_last_14_days']);
    checkEquals('revision: consistency = 2/14 percent', 14.29, $rev['consistency_percent']);
    checkEquals('revision: 1 of 3 segments completed', [1, 3], [$rev['segments_completed'], $rev['segments_total']]);

    // === daily tasks (productivity window = last 7 UTC days) ==============
    $typeId = static fn (string $slug): int =>
        (int) Database::scalar('SELECT id FROM task_types WHERE slug = ?', [$slug]);
    $insertTask = static function (string $slug, string $date, string $status, ?string $completedAt) use ($a, $typeId): void {
        Database::run(
            "INSERT INTO daily_tasks
                (user_id, task_type_id, title, scheduled_date, duration_minutes,
                 status, completed_at, actual_duration_seconds, notes, created_at, updated_at)
             VALUES (?, ?, NULL, ?, 30, ?, ?, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$a, $typeId($slug), $date, $status, $completedAt]
        );
    };

    $insertTask('murajaah', gmdate('Y-m-d'), 'completed', $nowMinusHour);
    $insertTask('rabt', gmdate('Y-m-d'), 'pending', null);
    $insertTask('personal', gmdate('Y-m-d'), 'skipped', null);
    $insertTask('rabt', $threeDaysAgo, 'completed', $threeDaysAgo . ' 11:00:00');
    $insertTask('personal', $tenDaysAgo, 'completed', $tenDaysAgo . ' 11:00:00');

    $week = $svc->summary($a)['productivity'];
    // Window covers today..today-6: the day-10 task is outside it.
    checkEquals('week: 4 planned tasks', 4, $week['planned']);
    checkEquals('week: 2 completed tasks', 2, $week['completed']);
    checkEquals('week: percent 50.0', 50.0, $week['percent']);

    $rabt = $svc->summary($a)['rabt'];
    // Only the day-3 ربط task is both completed and inside 14 days
    // (the day-10 one predates the window; today's is still pending).
    checkEquals('rabt: 1 completed task in window', 1, $rabt['tasks_completed_last_14_days']);

    // === flip cards (raw rows: statuses drive the counts) =================
    $categoryId = (int) Database::scalar('SELECT id FROM flip_card_categories ORDER BY id LIMIT 1');
    $insertCard = static function (string $status) use ($a, $categoryId): int {
        return Database::insert(
            "INSERT INTO flip_cards
                (user_id, category_id, surah_number, ayah_number, page_number,
                 error_note, context_note, severity, status, review_count,
                 last_reviewed_at, next_review_at, mastered_at, created_at, updated_at)
             VALUES (?, ?, 1, 1, 1, 'note', NULL, 'medium', ?, 0, NULL, NULL, NULL,
                     UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$a, $categoryId, $status]
        );
    };
    $cardA = $insertCard('active');
    $insertCard('active');
    $insertCard('in_review');
    $insertCard('mastered');
    $insertCard('archived');

    Database::run(
        'INSERT INTO flip_card_reviews (card_id, user_id, reviewed_at, result, duration_seconds, notes, created_at)
         VALUES (?, ?, ?, \'recalled\', NULL, NULL, UTC_TIMESTAMP())',
        [$cardA, $a, $nowMinusTwoHours]
    );
    Database::run(
        'INSERT INTO flip_card_reviews (card_id, user_id, reviewed_at, result, duration_seconds, notes, created_at)
         VALUES (?, ?, ?, \'forgotten\', NULL, NULL, UTC_TIMESTAMP())',
        [$cardA, $a, $twentyDaysAgo . ' 12:00:00']
    );

    $flip = $svc->summary($a)['flip_cards'];
    checkEquals('flip: 2 active', 2, $flip['active']);
    checkEquals('flip: 1 in review', 1, $flip['in_review']);
    checkEquals('flip: 1 mastered', 1, $flip['mastered']);
    checkEquals('flip: 1 archived', 1, $flip['archived']);
    checkEquals('flip: total 5', 5, $flip['total']);
    checkEquals('flip: pending review = active + in_review', 3, $flip['pending_review']);

    // === recent activity: merge by time, newest first, capped at 5 ========
    // Age four of the five memorization rows so several streams can show up.
    Database::run(
        'UPDATE memorization_history
            SET memorized_at = ?
          WHERE user_id = ? AND page_number IN (12, 13, 14, 15)',
        [$fiveDaysAgo . ' 12:00:00', $a]
    );

    $recent = $svc->summary($a)['recent_activity'];
    checkEquals('recent: capped at 5', 5, count($recent));
    $times = array_column($recent, 'occurred_at');
    $expectedOrder = $times;
    rsort($expectedOrder);
    checkEquals('recent: newest first', $expectedOrder, $times);
    checkEquals('recent: head is the newest memorization', 'memorization', $recent[0]['type']);
    checkEquals('recent: head is page 11', 11, $recent[0]['page_number']);
    $types = array_values(array_unique(array_column($recent, 'type')));
    sort($types);
    checkEquals('recent: merges memorization, tasks, flip and revision', ['flip', 'memorization', 'revision', 'task'], $types);
    foreach ($recent as $event) {
        check('recent: event has a type and time', ($event['type'] ?? '') !== '' && ($event['occurred_at'] ?? '') !== '');
    }

    // === isolation: user B sees only their own (empty) numbers ============
    $bSummary = $svc->summary($b);
    checkEquals('isolation: B not established', false, $bSummary['memorization']['established']);
    checkEquals('isolation: B sessions 0', 0, $bSummary['revision']['sessions_total']);
    checkEquals('isolation: B segments 0', [0, 0], [$bSummary['revision']['segments_completed'], $bSummary['revision']['segments_total']]);
    checkEquals('isolation: B cards 0', 0, $bSummary['flip_cards']['total']);
    checkEquals('isolation: B planned 0', 0, $bSummary['productivity']['planned']);
    checkEquals('isolation: B rabt 0', 0, $bSummary['rabt']['tasks_completed_last_14_days']);
    checkEquals('isolation: B recent empty', [], $bSummary['recent_activity']);
    checkEquals('isolation: A still has her numbers', 4, $svc->summary($a)['revision']['sessions_total']);
} finally {
    Database::run('DELETE FROM users WHERE email IN (?, ?)', [$aEmail, $bEmail]);
}

summary('Progress analytics (Prompt 22)');
