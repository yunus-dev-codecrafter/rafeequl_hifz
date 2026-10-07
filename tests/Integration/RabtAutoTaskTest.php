<?php

declare(strict_types=1);

/**
 * Integration tests: the automatic daily Rabt (ربط) task written after every
 * memorization boundary change (Prompt 12, plan section 7). Covers the seed
 * it depends on, the three write call sites, idempotency across mixed write
 * types, the "already exists" paths (manual and completed) and the rule that
 * a boundary-less user never gets a task.
 * Run: php tests/Integration/RabtAutoTaskTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Models\TaskStatus;
use App\Repositories\TaskRepository;
use App\Services\AuthService;
use App\Services\MemorizationProgressService;
use App\Services\TaskService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$emails = [
    'rabt-auto-a@example.com',
    'rabt-auto-b@example.com',
    'rabt-auto-c@example.com',
];

// Idempotent re-runs: drop leftovers from a previously interrupted run.
Database::run('DELETE FROM users WHERE email IN (?, ?, ?)', $emails);

try {
    $auth = new AuthService();
    $register = static function (string $email, string $name) use ($auth): int {
        return (int) $auth->register(
            ['email' => $email, 'password' => 'Password123!', 'display_name' => $name],
            '127.0.0.1',
            'test'
        )['user']['id'];
    };

    $a = $register($emails[0], 'Rabt Auto A');
    $b = $register($emails[1], 'Rabt Auto B');
    $c = $register($emails[2], 'Rabt Auto C');

    $progress = new MemorizationProgressService();
    $tasks = new TaskService();
    $repo = new TaskRepository();

    /** Today's Rabt rows for a user (UTC date, same rule as the repository). */
    $rabtToday = static function (int $userId): array {
        return Database::fetchAll(
            'SELECT t.id, t.title, t.status, t.scheduled_date, t.duration_minutes
               FROM daily_tasks t
               JOIN task_types k ON k.id = t.task_type_id
              WHERE t.user_id = ? AND k.slug = ? AND t.scheduled_date = UTC_DATE()
              ORDER BY t.id',
            [$userId, TaskRepository::RABT_SLUG]
        );
    };

    // === the seed this whole fix hangs on (test 2.13) =====================
    $type = $repo->findTypeBySlug(TaskRepository::RABT_SLUG) ?? [];
    check('seed: rabt task type exists', $type !== []);
    checkEquals('seed: rabt type is active', 1, (int) ($type['is_active'] ?? 0));
    checkEquals('seed: rabt category is quran', 'quran', (string) ($type['category'] ?? ''));
    checkEquals('seed: rabt default duration is 20 minutes', 20, (int) ($type['default_duration_minutes'] ?? 0));
    $rabtTypeId = (int) ($type['id'] ?? 0);

    // === a user who has never written a boundary gets no task =============
    checkEquals('fresh user: no rabt task yet', 0, count($rabtToday($c)));

    // === establish creates it (tests 2.1 / 2.4 range) =====================
    $progress->establish($a, 1, 10);
    $rows = $rabtToday($a);
    $row = $rows[0] ?? [];
    checkEquals('establish: one rabt task for today', 1, count($rows));
    checkEquals('establish: pending status', 'pending', $row['status'] ?? null);
    checkEquals('establish: scheduled for today (UTC)', gmdate('Y-m-d'), $row['scheduled_date'] ?? null);
    checkEquals('establish: seeded default duration', 20, (int) ($row['duration_minutes'] ?? 0));
    check('establish: quran category needs no title', array_key_exists('title', $row) && $row['title'] === null);

    // === markMemorized on the same day does not duplicate (2.7 / 2.8) =====
    $progress->markMemorized($a, 11, 13);
    checkEquals('mark after establish: still one rabt task', 1, count($rabtToday($a)));

    $progress->markMemorized($a, 14, 15);
    checkEquals('second mark same day: still one rabt task', 1, count($rabtToday($a)));

    // === correctBoundary also runs the write path (test 2.6) ==============
    $progress->correctBoundary($a, 12, true);
    checkEquals('boundary shrink: still one rabt task', 1, count($rabtToday($a)));

    // === a manually created task for today wins (test 2.11) ===============
    $manual = $tasks->create($b, ['task_type_id' => $rabtTypeId])['task'];
    checkEquals('manual: one rabt task before establish', 1, count($rabtToday($b)));

    $progress->establish($b, 1, 10);
    checkEquals('manual task present: no duplicate created', 1, count($rabtToday($b)));
    checkEquals(
        'manual task untouched by establish',
        (int) $manual['id'],
        (int) (($rabtToday($b)[0] ?? [])['id'] ?? 0)
    );

    // === a completed task for today still counts (test 2.12) ==============
    $tasks->changeStatus($b, (int) $manual['id'], TaskStatus::Completed, ['status' => 'completed']);
    $progress->markMemorized($b, 11, 13);
    $rows = $rabtToday($b);
    checkEquals('completed task: still one rabt task', 1, count($rows));
    checkEquals('completed task: stays completed', 'completed', $rows[0]['status'] ?? null);

    // === ensureRabtTaskForToday contract ==================================
    checkEquals('ensure: creates when absent', true, $tasks->ensureRabtTaskForToday($c));
    checkEquals('ensure: one task after create', 1, count($rabtToday($c)));
    checkEquals('ensure: second call is a no-op', false, $tasks->ensureRabtTaskForToday($c));
    checkEquals('ensure: still one task', 1, count($rabtToday($c)));

    // establish on top of an already-ensured day must not add a second one.
    $progress->establish($c, 1, 16);
    checkEquals('establish after ensure: still one rabt task', 1, count($rabtToday($c)));

    // === the auto task never leaks across users ===========================
    $ids = array_merge(
        array_column($rabtToday($a), 'id'),
        array_column($rabtToday($b), 'id'),
        array_column($rabtToday($c), 'id')
    );
    checkEquals('isolation: one task per user', 3, count($ids));
    checkEquals('isolation: three distinct task rows', 3, count(array_unique($ids)));
} finally {
    Database::run('DELETE FROM users WHERE email IN (?, ?, ?)', $emails);
}

exit(summary('Rabt auto task'));
