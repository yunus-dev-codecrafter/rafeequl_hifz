<?php

declare(strict_types=1);

/**
 * Integration tests: daily productivity (Prompt 14) — seeded vocabulary,
 * day view + server-owned summary math, the terminal completion machine,
 * edit rules, history, ownership and the hard isolation from Hifz data.
 * Run: php tests/Integration/TaskTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\TaskStatus;
use App\Services\AuthService;
use App\Services\MemorizationProgressService;
use App\Services\TaskService;
use App\Validators\CreateTaskValidator;
use App\Validators\UpdateTaskValidator;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$aEmail = 'task-test@example.com';
$bEmail = 'task-test-2@example.com';

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

    $a = $register($aEmail, 'Task A');
    $b = $register($bEmail, 'Task B');

    $svc = new TaskService();
    $today = gmdate('Y-m-d');

    $typeId = static fn (string $slug): int =>
        (int) Database::scalar('SELECT id FROM task_types WHERE slug = ?', [$slug]);
    $taskCount = static fn (int $userId): int =>
        (int) Database::scalar('SELECT COUNT(*) FROM daily_tasks WHERE user_id = ?', [$userId]);
    $completionRows = static fn (int $taskId): int =>
        (int) Database::scalar('SELECT COUNT(*) FROM task_completions WHERE task_id = ?', [$taskId]);
    $create = static fn (int $userId, int $typeId, array $extra = []): array =>
        $svc->create($userId, $extra + ['task_type_id' => $typeId])['task'];
    $taskById = static fn (array $day, int $taskId): array =>
        array_values(array_filter($day['tasks'], static fn (array $t): bool => $t['id'] === $taskId))[0];

    // === seeded vocabulary ===================================================
    $types = $svc->types()['types'];
    checkEquals('types: 5 active', 5, count($types));
    checkEquals(
        'types: slugs in sort order',
        ['murajaah', 'rabt', 'new_memorization', 'flip_card_review', 'personal'],
        array_column($types, 'slug')
    );
    checkEquals(
        'types: quran vs general',
        ['quran', 'quran', 'quran', 'quran', 'general'],
        array_column($types, 'category')
    );
    checkEquals(
        'types: default durations',
        [30, 20, 45, 15, 30],
        array_column($types, 'default_duration_minutes')
    );

    // === create rules ========================================================
    $tNew = $create($a, $typeId('new_memorization'), [
        'scheduled_date' => '2026-09-30',
        'duration_minutes' => 60,
        'notes' => 'after isha',
    ]);
    checkEquals('create: explicit date stored', '2026-09-30', $tNew['scheduled_date']);
    checkEquals('create: duration stored', 60, $tNew['duration_minutes']);
    checkEquals('create: notes stored', 'after isha', $tNew['notes']);
    checkEquals('create: starts pending', 'pending', $tNew['status']);
    checkEquals('create: no completion yet', null, $tNew['completion']);

    checkThrows(
        'create: general without title 422',
        fn () => $create($a, $typeId('personal')),
        ValidationException::class,
        'title'
    );

    $tPersonal = $create($a, $typeId('personal'), [
        'title' => 'Weekly review',
        'scheduled_date' => '2026-09-30',
    ]);
    checkEquals('create: general title stored', 'Weekly review', $tPersonal['title']);
    checkEquals('create: general default duration', 30, $tPersonal['duration_minutes']);

    Database::run("UPDATE task_types SET is_active = 0 WHERE slug = 'flip_card_review'");
    checkThrows(
        'create: inactive type 422',
        fn () => $create($a, $typeId('flip_card_review')),
        ValidationException::class,
        'task_type_id'
    );
    Database::run("UPDATE task_types SET is_active = 1 WHERE slug = 'flip_card_review'");

    checkThrows(
        'create: unknown type 422',
        fn () => $create($a, 99999),
        ValidationException::class,
        'task_type_id'
    );
    $validated = static fn (array $input): array => (new CreateTaskValidator())->validate($input);
    checkThrows(
        'create: zero duration 422',
        fn () => $svc->create($a, $validated(['task_type_id' => $typeId('rabt'), 'duration_minutes' => 0])),
        ValidationException::class,
        'duration_minutes'
    );
    checkThrows(
        'create: duration beyond day 422',
        fn () => $svc->create($a, $validated(['task_type_id' => $typeId('rabt'), 'duration_minutes' => 1441])),
        ValidationException::class,
        'duration_minutes'
    );
    checkEquals('failed creates leave no rows', 2, $taskCount($a));

    $t1 = $create($a, $typeId('murajaah'));
    checkEquals('create: date defaults to today', $today, $t1['scheduled_date']);
    checkEquals('create: duration defaults to type default', 30, $t1['duration_minutes']);

    // === day view + summary ==================================================
    $t2 = $create($a, $typeId('rabt'), ['scheduled_date' => $today, 'duration_minutes' => 20]);
    $t3 = $create($a, $typeId('personal'), [
        'title' => 'Call teacher',
        'scheduled_date' => $today,
        'duration_minutes' => 15,
    ]);

    $day = $svc->day($a, null);
    checkEquals('day: default date is today', $today, $day['date']);
    checkEquals('day: three tasks', 3, count($day['tasks']));
    checkEquals('day: summary totals', [3, 0, 0, 3, 0], [
        $day['summary']['total'],
        $day['summary']['completed'],
        $day['summary']['active'],
        $day['summary']['pending'],
        $day['summary']['skipped'],
    ]);
    checkEquals('day: planned minutes summed', 65, $day['summary']['planned_minutes']);
    checkEquals('day: percent starts at zero', 0.0, $day['summary']['completion_percent']);

    $empty = $svc->day($a, '2000-01-01');
    checkEquals('day: other date empty', 0, $empty['summary']['total']);
    checkEquals('day: empty percent is float zero', 0.0, $empty['summary']['completion_percent']);

    $otherDay = $svc->day($a, '2026-09-30');
    checkEquals('day: explicit-date tasks isolated', 2, $otherDay['summary']['total']);

    // --- status machine ------------------------------------------------------
    $started = $svc->changeStatus($a, $t1['id'], TaskStatus::Active, ['status' => 'active'])['task'];
    checkEquals('start: pending → active', 'active', $started['status']);
    $afterStart = $svc->day($a, $today)['summary'];
    checkEquals('start: day counts', [1, 2], [$afterStart['active'], $afterStart['pending']]);

    $done = $svc->changeStatus($a, $t1['id'], TaskStatus::Completed, [
        'status' => 'completed',
        'actual_duration_seconds' => 1200,
        'note' => 'finished early',
    ])['task'];
    checkEquals('complete: status applied', 'completed', $done['status']);
    check('complete: completed_at stamped', $done['completed_at'] !== null);
    checkEquals('complete: actual duration stored', 1200, $done['actual_duration_seconds']);
    checkEquals('complete: completion record embedded', [1200, 'finished early'], [
        $done['completion']['duration_seconds'],
        $done['completion']['note'],
    ]);
    checkEquals('complete: one completion row', 1, $completionRows($t1['id']));
    checkEquals('complete: percent after one of three', 33.33, $svc->day($a, $today)['summary']['completion_percent']);

    $again = $svc->changeStatus($a, $t1['id'], TaskStatus::Completed, ['status' => 'completed'])['task'];
    checkEquals('complete again: idempotent status', 'completed', $again['status']);
    checkEquals('complete again: still one completion row', 1, $completionRows($t1['id']));

    foreach (['active', 'pending', 'skipped'] as $blocked) {
        checkThrows(
            'completed terminal: → ' . $blocked . ' 422',
            fn () => $svc->changeStatus($a, $t1['id'], TaskStatus::from($blocked), ['status' => $blocked]),
            ValidationException::class,
            'status'
        );
    }

    $svc->changeStatus($a, $t3['id'], TaskStatus::Skipped, ['status' => 'skipped']);
    $summary = $svc->day($a, $today)['summary'];
    checkEquals('skip: counted separately', [1, 1, 1], [$summary['completed'], $summary['skipped'], $summary['pending']]);
    checkEquals('skip: percent ignores skipped', 33.33, $summary['completion_percent']);

    $svc->changeStatus($a, $t3['id'], TaskStatus::Pending, ['status' => 'pending']);
    $summary = $svc->day($a, $today)['summary'];
    checkEquals('revert skip: back to pending', [0, 2], [$summary['skipped'], $summary['pending']]);

    $svc->changeStatus($a, $t2['id'], TaskStatus::Completed, ['status' => 'completed']);
    checkEquals('two of three', 66.67, $svc->day($a, $today)['summary']['completion_percent']);

    $svc->changeStatus($a, $t3['id'], TaskStatus::Completed, ['status' => 'completed']);
    $summary = $svc->day($a, $today)['summary'];
    checkEquals('three of three', 100.0, $summary['completion_percent']);
    checkEquals('all completed counts', [3, 3], [$summary['completed'], $summary['total']]);

    $t4 = $create($a, $typeId('rabt'), ['scheduled_date' => $today]);
    $svc->changeStatus($a, $t4['id'], TaskStatus::Completed, ['status' => 'completed']);
    $summary = $svc->day($a, $today)['summary'];
    checkEquals('pending → completed allowed', [4, 4, 100.0], [$summary['completed'], $summary['total'], $summary['completion_percent']]);

    $t5 = $create($a, $typeId('murajaah'), ['scheduled_date' => $today]);
    $svc->changeStatus($a, $t5['id'], TaskStatus::Skipped, ['status' => 'skipped']);
    $svc->changeStatus($a, $t5['id'], TaskStatus::Active, ['status' => 'active']);
    $summary = $svc->day($a, $today)['summary'];
    checkEquals('skipped → active allowed', [1, 4, 80.0], [$summary['active'], $summary['completed'], $summary['completion_percent']]);

    // === history =============================================================
    $hist = $svc->history($a, '2026-09-01', $today, 100);
    checkEquals('history: all seven tasks', 7, count($hist['tasks']));
    checkEquals(
        'history: newest day first',
        [$today, $today, $today, $today, $today, '2026-09-30', '2026-09-30'],
        array_column($hist['tasks'], 'scheduled_date')
    );
    checkEquals('history: limit respected', 3, count($svc->history($a, '2026-09-01', $today, 3)['tasks']));
    checkThrows(
        'history: from after to 422',
        fn () => $svc->history($a, $today, '2026-09-01', 10),
        ValidationException::class,
        'from'
    );

    $bTask = $create($b, $typeId('personal'), ['title' => 'B only', 'scheduled_date' => $today]);
    checkEquals('history: isolated from b', 1, count($svc->history($b, '2026-09-01', $today, 100)['tasks']));
    checkEquals('day: isolated from b', 1, count($svc->day($b, $today)['tasks']));

    // === edits ===============================================================
    $edited = $svc->update($a, $t5['id'], [
        'title' => 'Night revision',
        'duration_minutes' => 45,
        'scheduled_date' => '2026-01-01',
    ])['task'];
    checkEquals('edit: title applied', 'Night revision', $edited['title']);
    checkEquals('edit: duration applied', 45, $edited['duration_minutes']);
    checkEquals('edit: scheduled date immutable', $today, $edited['scheduled_date']);
    checkEquals('edit: status untouched', 'active', $edited['status']);

    $completedAtBefore = $taskById($svc->day($a, $today), $t1['id'])['completed_at'];
    $editCompleted = $svc->update($a, $t1['id'], ['notes' => 'retro note'])['task'];
    checkEquals('edit: completed task editable', 'retro note', $editCompleted['notes']);
    checkEquals('edit: completion state untouched', ['completed', $completedAtBefore], [
        $editCompleted['status'],
        $editCompleted['completed_at'],
    ]);
    checkEquals('edit: completion row untouched', 1, $completionRows($t1['id']));

    checkThrows(
        'edit: switch to general needs title 422',
        fn () => $svc->update($a, $tNew['id'], ['task_type_id' => $typeId('personal')]),
        ValidationException::class,
        'title'
    );
    $switched = $svc->update($a, $tNew['id'], [
        'task_type_id' => $typeId('personal'),
        'title' => 'Reclassified',
    ])['task'];
    checkEquals('edit: type switch applied', ['personal', 'general', 'Reclassified'], [
        $switched['type_slug'],
        $switched['type_category'],
        $switched['title'],
    ]);
    checkEquals('edit: duration preserved on switch', 60, $switched['duration_minutes']);

    checkThrows(
        'edit: unknown type 422',
        fn () => $svc->update($a, $tNew['id'], ['task_type_id' => 99999]),
        ValidationException::class,
        'task_type_id'
    );
    checkThrows(
        'edit: zero duration 422',
        fn () => $svc->update(
            $a,
            $tNew['id'],
            (new UpdateTaskValidator())->validate(['duration_minutes' => 0])
        ),
        ValidationException::class,
        'duration_minutes'
    );

    // === Hifz isolation ======================================================
    $progress = new MemorizationProgressService();
    $progress->establish($a, 1, 10);
    $progress->markMemorized($a, 11, 13);

    $snapshot = static fn (int $userId): array => [
        'states' => Database::fetchAll('SELECT * FROM memorization_states WHERE user_id = ?', [$userId]),
        'history' => Database::fetchAll('SELECT * FROM memorization_history WHERE user_id = ? ORDER BY id', [$userId]),
        'boundary' => Database::fetchAll('SELECT * FROM memorization_boundary_history WHERE user_id = ? ORDER BY id', [$userId]),
        'plans' => Database::fetchAll('SELECT COUNT(*) AS c FROM revision_plans WHERE user_id = ?', [$userId]),
    ];
    $before = $snapshot($a);

    // full task churn: create → start → complete → edit → delete
    $churn = $create($a, $typeId('rabt'), ['scheduled_date' => $today, 'duration_minutes' => 25]);
    $svc->changeStatus($a, $churn['id'], TaskStatus::Active, ['status' => 'active']);
    $svc->changeStatus($a, $churn['id'], TaskStatus::Completed, [
        'status' => 'completed',
        'actual_duration_seconds' => 900,
    ]);
    $svc->update($a, $churn['id'], ['title' => 'Churned']);
    $svc->update($a, $t2['id'], ['notes' => 'edited during churn']);
    $svc->delete($a, $churn['id']);

    checkEquals('hifz: task churn leaves state/history/boundary/plans untouched', $before, $snapshot($a));

    // === ownership isolation =================================================
    checkThrows('isolation: b reads a\'s task 404', fn () => $svc->detail($b, $t1['id']), NotFoundException::class);
    checkThrows('isolation: b edits a\'s task 404', fn () => $svc->update($b, $t1['id'], ['title' => 'x']), NotFoundException::class);
    checkThrows(
        'isolation: b changes a\'s status 404',
        fn () => $svc->changeStatus($b, $t1['id'], TaskStatus::Skipped, ['status' => 'skipped']),
        NotFoundException::class
    );
    checkThrows('isolation: b deletes a\'s task 404', fn () => $svc->delete($b, $t1['id']), NotFoundException::class);
    checkEquals('isolation: a\'s tasks intact', 7, $taskCount($a));

    // === explicit delete =====================================================
    $deleted = $svc->delete($a, $t3['id']);
    checkEquals('delete: acknowledges', ['task_id' => $t3['id'], 'deleted' => true], $deleted);
    checkEquals('delete: completion history cascades', 0, $completionRows($t3['id']));
    checkEquals('delete: task row gone', 6, $taskCount($a));
    checkThrows('delete: gone afterwards', fn () => $svc->detail($a, $t3['id']), NotFoundException::class);
    checkThrows('delete: double delete 404', fn () => $svc->delete($a, $t3['id']), NotFoundException::class);

    $summary = $svc->day($a, $today)['summary'];
    checkEquals('delete: day summary recalculated', [4, 3, 75.0], [
        $summary['total'],
        $summary['completed'],
        $summary['completion_percent'],
    ]);
    checkEquals('delete: siblings keep their history', 1, $completionRows($t1['id']));
} finally {
    Database::run("UPDATE task_types SET is_active = 1 WHERE slug = 'flip_card_review'");
    Database::run('DELETE FROM users WHERE email IN (?, ?)', [$aEmail, $bEmail]);
}

exit(summary('Task'));
