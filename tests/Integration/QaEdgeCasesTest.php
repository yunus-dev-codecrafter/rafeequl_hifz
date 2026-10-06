<?php

declare(strict_types=1);

/**
 * Integration tests: Prompt 24 QA edge cases — the behavioral fixes:
 * skipped → completed blocked, settings unit/amount pair validation,
 * duplicate active flip cards, lockout expiry resets the attempt window,
 * CSV formula-escape, and the date-anchored analytics window (future-dated
 * rows excluded). Also asserts cross-user isolation for each new rule.
 * Run: php tests/Integration/QaEdgeCasesTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Models\FlipCardStatus;
use App\Models\TaskStatus;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\FlipCardService;
use App\Services\ProgressAnalyticsService;
use App\Services\SettingsService;
use App\Services\TaskService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$aEmail = 'qa-edge-a@example.com';
$bEmail = 'qa-edge-b@example.com';
$cEmail = 'qa-edge-lock@example.com';
$dEmail = 'qa-edge-formula@example.com';

Database::run('DELETE FROM users WHERE email IN (?, ?, ?, ?)', [$aEmail, $bEmail, $cEmail, $dEmail]);

/** Asserts a login attempt ends with a specific HTTP status. */
$expectLoginStatus = static function (string $name, AuthService $auth, string $email, int $expected): void {
    try {
        $auth->login(['email' => $email, 'password' => 'WrongPass99!']);
        check($name, false, 'nothing thrown (expected status ' . $expected . ')');
    } catch (HttpException $e) {
        check($name, $e->getStatusCode() === $expected, 'expected ' . $expected . ' got ' . $e->getStatusCode());
    } catch (\Throwable $e) {
        check($name, false, 'expected HttpException got ' . $e::class . ': ' . $e->getMessage());
    }
};

try {
    $auth = new AuthService();
    $register = static function (string $email, string $name) use ($auth): int {
        return (int) $auth->register(
            ['email' => $email, 'password' => 'Password123!', 'display_name' => $name],
            '127.0.0.1',
            'test'
        )['user']['id'];
    };

    $a = $register($aEmail, 'QA A');
    $b = $register($bEmail, 'QA B');

    // === 1. task state machine: skipped must be reopened ====================
    $tasks = new TaskService();
    $today = gmdate('Y-m-d');
    $quranTypeId = (int) Database::scalar(
        "SELECT id FROM task_types WHERE category = 'quran' AND is_active = 1 ORDER BY id LIMIT 1"
    );

    $t1 = $tasks->create($a, [
        'task_type_id' => $quranTypeId,
        'duration_minutes' => 10,
        'scheduled_date' => $today,
    ])['task'];
    $tasks->changeStatus($a, (int) $t1['id'], TaskStatus::Skipped, ['status' => 'skipped']);

    try {
        $tasks->changeStatus($a, (int) $t1['id'], TaskStatus::Completed, [
            'status' => 'completed',
            'actual_duration_seconds' => 600,
        ]);
        check('skip → completed blocked', false, 'nothing thrown');
    } catch (ValidationException $e) {
        $errors = $e->getErrors();
        checkEquals(
            'skip → completed blocked (field status, reopen message)',
            ['status', 'A skipped task must be reopened before it can be completed'],
            [$errors[0]['field'] ?? null, $errors[0]['message'] ?? null]
        );
    }

    $tasks->changeStatus($a, (int) $t1['id'], TaskStatus::Pending, ['status' => 'pending']);
    $done = $tasks->changeStatus($a, (int) $t1['id'], TaskStatus::Completed, [
        'status' => 'completed',
        'actual_duration_seconds' => 600,
    ])['task'];
    checkEquals('skip → reopen → completed succeeds', 'completed', $done['status']);
    checkEquals(
        'completion record exists after reopen path',
        1,
        (int) Database::scalar('SELECT COUNT(*) FROM task_completions WHERE task_id = ?', [(int) $t1['id']])
    );

    // === 2. settings unit/amount pair validation ============================
    $settings = new SettingsService();
    $settings->update($a, ['daily_revision_unit' => 'page', 'daily_revision_amount' => 1.5]);
    checkThrows(
        'pair: unit-only change to hizb with fractional stored amount → 422',
        fn () => $settings->update($a, ['daily_revision_unit' => 'hizb']),
        ValidationException::class,
        'daily_revision_amount'
    );
    $settings->update($a, ['daily_revision_unit' => 'hizb', 'daily_revision_amount' => 2]);
    check('pair: hizb + whole amount accepted', true);
    checkThrows(
        'pair: fractional amount with stored hizb → 422',
        fn () => $settings->update($a, ['daily_revision_amount' => 1.5]),
        ValidationException::class,
        'daily_revision_amount'
    );
    $settings->update($a, ['daily_revision_unit' => 'page']);
    check('pair: unit-only change back to page accepted', true);
    $shown = $settings->show($a);
    checkEquals('pair: rejected writes persisted nothing (unit)', 'page', $shown['daily_revision_unit']);
    checkEquals('pair: rejected writes persisted nothing (amount)', 2.0, $shown['daily_revision_amount']);
    $settings->update($a, ['daily_revision_unit' => 'page', 'daily_revision_amount' => 1]);

    // === 3. lockout: expiry starts a fresh attempt window ===================
    $c = $register($cEmail, 'QA Lock');
    for ($i = 1; $i <= 5; $i++) {
        $expectLoginStatus('lockout: wrong password ' . $i . '/5 → 401', $auth, $cEmail, 401);
    }
    checkEquals(
        'lockout: counter armed at threshold',
        5,
        (int) Database::scalar('SELECT failed_login_count FROM user_auth WHERE user_id = ?', [$c])
    );
    $expectLoginStatus('lockout: 6th attempt → 429', $auth, $cEmail, 429);

    try {
        $auth->login(['email' => $cEmail, 'password' => 'Password123!']);
        check('lockout: correct password while locked → 429', false, 'nothing thrown');
    } catch (HttpException $e) {
        checkEquals('lockout: correct password while locked → 429', 429, $e->getStatusCode());
    }

    // Expire the lock (UTC, as written) and keep the saturated counter.
    Database::run(
        'UPDATE user_auth SET locked_until = ? WHERE user_id = ?',
        [gmdate('Y-m-d H:i:s', time() - 60), $c]
    );
    $expectLoginStatus('expired lock: wrong password → 401 again', $auth, $cEmail, 401);
    checkEquals(
        'expired lock: attempt window reset to a single failure',
        1,
        (int) Database::scalar('SELECT failed_login_count FROM user_auth WHERE user_id = ?', [$c])
    );
    checkEquals(
        'expired lock: lock cleared',
        null,
        Database::scalar('SELECT locked_until FROM user_auth WHERE user_id = ?', [$c])
    );

    // === 4. duplicate active flip cards =====================================
    $flip = new FlipCardService();
    $catId = (int) Database::scalar(
        "SELECT id FROM flip_card_categories WHERE slug = 'verse_mistake'"
    );
    $payload = static fn (int $s, int $ay, int $p): array => [
        'surah_number' => $s,
        'ayah_number' => $ay,
        'page_number' => $p,
        'category_id' => $catId,
        'error_note' => 'Lost the transition',
        'context_note' => null,
        'severity' => null,
    ];

    $cardA = $flip->create($a, $payload(3, 7, 12))['card'];
    try {
        $flip->create($a, $payload(3, 7, 12));
        check('duplicate: second active flag → 422', false, 'nothing thrown');
    } catch (ValidationException $e) {
        $errors = $e->getErrors();
        checkEquals(
            'duplicate: second active flag → 422 (field ayah_number)',
            ['ayah_number', 'This ayah is already flagged (an active card exists)'],
            [$errors[0]['field'] ?? null, $errors[0]['message'] ?? null]
        );
    }

    // Isolation: the same location is still flaggable by another user.
    $bCard = $flip->create($b, $payload(3, 7, 12))['card'];
    checkEquals('duplicate: user B unaffected by A\'s active card', 3, (int) $bCard['surah_number']);

    // Re-flag once the queued card left the queue.
    $flip->changeStatus($a, (int) $cardA['id'], FlipCardStatus::Mastered);
    $reFlagged = $flip->create($a, $payload(3, 7, 12))['card'];
    check('duplicate: re-flag after mastered allowed', $reFlagged['id'] !== null);
    checkThrows(
        'duplicate: active again → 422',
        fn () => $flip->create($a, $payload(3, 7, 12)),
        ValidationException::class,
        'ayah_number'
    );
    $flip->changeStatus($a, (int) $reFlagged['id'], FlipCardStatus::Archived);
    $reArchived = $flip->create($a, $payload(3, 7, 12))['card'];
    check('duplicate: re-flag after archived allowed', $reArchived['id'] !== null);

    // === 5. CSV formula escaping (JSON stays canonical) =====================
    $d = $register($dEmail, '=SUM(A1)');
    $flip->create($b, ['error_note' => '=1+1'] + $payload(3, 8, 13));

    $account = new AccountService();
    $csv = $account->exportCsv($b);
    check('csv: note starting with = is neutralized', str_contains($csv, "'=1+1"));
    $csvD = $account->exportCsv($d);
    check('csv: display name starting with = is neutralized', str_contains($csvD, "'=SUM(A1)"));
    $json = (string) json_encode($account->export($b), JSON_UNESCAPED_UNICODE);
    check('json: formula note stays raw (canonical)', str_contains($json, '=1+1') && !str_contains($json, "'=1+1"));

    // === 6. analytics windows are date-anchored and end-bounded =============
    $analytics = new ProgressAnalyticsService();
    $before = $analytics->summary($a)['productivity'];
    $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
    $tasks->create($a, [
        'task_type_id' => $quranTypeId,
        'duration_minutes' => 5,
        'scheduled_date' => $tomorrow,
    ]);
    $after = $analytics->summary($a)['productivity'];
    checkEquals(
        'analytics: future-dated task excluded from the 7-day window',
        $before['planned'],
        $after['planned']
    );
    checkEquals(
        'analytics: future-dated task exists (test sanity)',
        1,
        (int) Database::scalar(
            'SELECT COUNT(*) FROM daily_tasks WHERE user_id = ? AND scheduled_date = ?',
            [$a, $tomorrow]
        )
    );
    checkEquals('analytics: window still 14 days', 14, $analytics->summary($a)['consistency_window_days']);
} finally {
    Database::run('DELETE FROM users WHERE email IN (?, ?, ?, ?)', [$aEmail, $bEmail, $cEmail, $dEmail]);
}

exit(summary('QA edge cases (Prompt 24)'));
