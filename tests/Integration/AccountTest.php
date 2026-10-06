<?php

declare(strict_types=1);

/**
 * Integration tests: privacy/data controls (Prompts 18 & 23) — profile
 * edits, password change with full session revocation, the personal-data
 * export (JSON datasets, vocabulary subsets, sectioned CSV, no secrets)
 * and soft-delete account removal (identity
 * anonymized, sessions revoked, Hifz data retained pseudonymously, the
 * tombstone can never sign in again).
 * Run: php tests/Integration/AccountTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Exceptions\HttpException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\FlipCardService;
use App\Services\MemorizationProgressService;
use App\Services\TaskService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$aEmail = 'account-test@example.com';
$bEmail = 'account-test-2@example.com';

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

    $a = $register($aEmail, 'Account A');
    $b = $register($bEmail, 'Account B');

    // Some personal data to export: memorized state + one daily task.
    (new MemorizationProgressService())->establish($a, 1, 12);
    $taskTypeId = (int) Database::scalar("SELECT id FROM task_types WHERE slug = 'murajaah'");
    (new TaskService())->create($a, ['task_type_id' => $taskTypeId]);

    $sessionCount = static fn (int $userId): int =>
        (int) Database::scalar('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$userId]);

    // === profile update ======================================================
    $profile = $auth->updateProfile($a, ['display_name' => '  Renamed  ', 'email' => 'account-renamed@example.com']);
    checkEquals('profile: display_name trimmed + stored', 'Renamed', $profile['display_name']);
    checkEquals('profile: email stored lowercase', 'account-renamed@example.com', $profile['email']);
    checkEquals('profile: id stable', $a, $profile['id']);
    checkEquals(
        'profile: raw row matches',
        ['Renamed', 'account-renamed@example.com'],
        [
            (string) Database::scalar('SELECT display_name FROM users WHERE id = ?', [$a]),
            (string) Database::scalar('SELECT email FROM users WHERE id = ?', [$a]),
        ]
    );

    checkThrows(
        'profile: duplicate email 422',
        fn () => $auth->updateProfile($a, ['email' => $bEmail]),
        ValidationException::class,
        'email'
    );
    checkThrows(
        'profile: empty email 422',
        fn () => $auth->updateProfile($a, ['email' => '']),
        ValidationException::class,
        'email'
    );
    checkThrows(
        'profile: missing user 404',
        fn () => $auth->updateProfile(999999999, []),
        NotFoundException::class
    );
    checkEquals(
        'profile: rejected updates persist nothing',
        'Renamed',
        (string) Database::scalar('SELECT display_name FROM users WHERE id = ?', [$a])
    );

    $noOp = $auth->updateProfile($a, []);
    checkEquals('profile: no-op keeps display_name', 'Renamed', $noOp['display_name']);
    checkEquals('profile: no-op keeps email', 'account-renamed@example.com', $noOp['email']);

    // === password change (revokes every session) ============================
    checkEquals('password: session exists before change', 1, $sessionCount($a));
    checkThrows(
        'password: wrong current 422',
        fn () => $auth->changePassword($a, ['current_password' => 'WrongPass99', 'new_password' => 'Another789!']),
        ValidationException::class,
        'current_password'
    );
    checkThrows(
        'password: same password 422',
        fn () => $auth->changePassword($a, ['current_password' => 'Password123!', 'new_password' => 'Password123!']),
        ValidationException::class,
        'new_password'
    );
    $auth->changePassword($a, ['current_password' => 'Password123!', 'new_password' => 'NewSecret456!']);
    checkEquals('password: every session revoked', 0, $sessionCount($a));
    checkThrows(
        'password: old password rejected after change',
        fn () => $auth->login(['email' => 'account-renamed@example.com', 'password' => 'Password123!']),
        HttpException::class
    );
    $login = $auth->login(['email' => 'account-renamed@example.com', 'password' => 'NewSecret456!']);
    checkEquals('password: new password signs in', $a, (int) $login['user']['id']);

    // === personal-data export ===============================================
    // One flip card so both referenced-vocabulary subsets are non-empty.
    (new FlipCardService())->create($a, [
        'surah_number' => 1,
        'ayah_number' => 1,
        'page_number' => 1,
        'category_id' => (int) Database::scalar("SELECT id FROM flip_card_categories WHERE slug = 'verse_mistake'"),
        'error_note' => 'export fixture',
    ]);
    $export = (new AccountService())->export($a);
    checkEquals('export: format marker', 'rafeequl-hifz-export-v1', $export['format']);
    check('export: exported_at present', isset($export['exported_at']) && is_string($export['exported_at']));
    checkEquals('export: profile id', $a, $export['profile']['id'] ?? null);
    checkEquals(
        'export: profile has no secret fields',
        ['id', 'email', 'display_name', 'status', 'email_verified_at', 'last_login_at', 'created_at', 'updated_at'],
        array_keys($export['profile'] ?? [])
    );
    checkEquals('export: memorization state included', $a, $export['memorization_state']['user_id'] ?? null);
    check('export: daily tasks included', count($export['daily_tasks'] ?? []) >= 1);
    check('export: settings included', isset($export['settings']['locale']));
    $exportJson = json_encode($export, JSON_THROW_ON_ERROR);
    check('export: no password hash', !str_contains($exportJson, 'password_hash'));
    check('export: no session token', !str_contains($exportJson, 'token_hash'));
    checkEquals('export: excludes other users', false, str_contains($exportJson, $bEmail));

    // --- Prompt 23: referenced-vocabulary subsets (import resolution aids) ----
    checkEquals('export: task_types subset = referenced ids', [$taskTypeId], array_column($export['task_types'] ?? [], 'id'));
    check('export: task_types subset carries slugs', in_array('murajaah', array_column($export['task_types'] ?? [], 'slug'), true));
    checkEquals('export: flip categories subset = 1', 1, count($export['flip_card_categories'] ?? []));
    checkEquals('export: flip category slug', 'verse_mistake', $export['flip_card_categories'][0]['slug'] ?? null);

    // --- Prompt 23: sectioned CSV rendering of the same arrays ---------------
    $csvService = new AccountService();
    $csv = $csvService->exportCsv($a);
    check('csv: starts with UTF-8 BOM', str_starts_with($csv, "\xEF\xBB\xBF"));
    checkEquals('csv: one section per dataset', 17, substr_count($csv, '# dataset: '));
    check('csv: profile section present', str_contains($csv, '# dataset: profile'));
    check(
        'csv: vocabulary sections present',
        str_contains($csv, '# dataset: task_types') && str_contains($csv, '# dataset: flip_card_categories')
    );
    $csvLines = explode("\n", $csv);
    $markerPos = null;
    foreach ($csvLines as $lineIndex => $line) {
        if (str_contains($line, '# dataset: daily_tasks')) {
            $markerPos = $lineIndex;
            break;
        }
    }
    check('csv: daily_tasks section located', $markerPos !== null);
    checkEquals(
        'csv: header row matches JSON keys',
        array_keys($export['daily_tasks'][0]),
        str_getcsv($csvLines[$markerPos + 1])
    );
    check('csv: no password hash', !str_contains($csv, 'password_hash'));
    check('csv: no session token', !str_contains($csv, 'token_hash'));
    checkEquals('csv: excludes other users', false, str_contains($csv, $bEmail));
    $filtered = $csvService->exportCsv($a, 'daily_tasks');
    checkEquals('csv: dataset filter keeps one section', 1, substr_count($filtered, '# dataset: '));
    check('csv: dataset filter keeps requested section', str_contains($filtered, '# dataset: daily_tasks'));
    check('csv: dataset filter drops others', !str_contains($filtered, '# dataset: profile'));
    checkThrows(
        'csv: unknown dataset 422',
        fn () => $csvService->exportCsv($a, 'bogus'),
        ValidationException::class,
        'dataset'
    );

    // === account deletion (soft delete + anonymization) =====================
    $account = new AccountService();
    checkThrows(
        'delete: wrong password 422',
        fn () => $account->delete($a, 'WrongPass99'),
        ValidationException::class,
        'password'
    );
    checkEquals('delete: rejected attempt changes nothing', 'active', (string) Database::scalar('SELECT status FROM users WHERE id = ?', [$a]));

    $memorizedBefore = (int) Database::scalar('SELECT COUNT(*) FROM memorization_states WHERE user_id = ?', [$a]);
    $tasksBefore = (int) Database::scalar('SELECT COUNT(*) FROM daily_tasks WHERE user_id = ?', [$a]);
    $account->delete($a, 'NewSecret456!');

    checkEquals('delete: status is deleted', 'deleted', (string) Database::scalar('SELECT status FROM users WHERE id = ?', [$a]));
    checkEquals(
        'delete: email anonymized',
        'deleted+' . $a . '@deleted.invalid',
        (string) Database::scalar('SELECT email FROM users WHERE id = ?', [$a])
    );
    checkEquals('delete: display_name cleared', '', (string) Database::scalar('SELECT display_name FROM users WHERE id = ?', [$a]));
    checkEquals('delete: sessions revoked', 0, $sessionCount($a));
    checkEquals(
        'delete: hifz data retained pseudonymously',
        $memorizedBefore,
        (int) Database::scalar('SELECT COUNT(*) FROM memorization_states WHERE user_id = ?', [$a])
    );
    checkEquals(
        'delete: tasks retained',
        $tasksBefore,
        (int) Database::scalar('SELECT COUNT(*) FROM daily_tasks WHERE user_id = ?', [$a])
    );
    checkThrows(
        'delete: tombstone cannot sign in (old email)',
        fn () => $auth->login(['email' => 'account-renamed@example.com', 'password' => 'NewSecret456!']),
        HttpException::class
    );
    checkThrows(
        'delete: tombstone cannot sign in (anonymized email)',
        fn () => $auth->login(['email' => 'deleted+' . $a . '@deleted.invalid', 'password' => 'NewSecret456!']),
        HttpException::class
    );

    // === isolation: user B is untouched =====================================
    checkEquals('isolation: B still active', 'active', (string) Database::scalar('SELECT status FROM users WHERE id = ?', [$b]));
    $loginB = $auth->login(['email' => $bEmail, 'password' => 'Password123!']);
    checkEquals('isolation: B still signs in', $b, (int) $loginB['user']['id']);
} finally {
    Database::run('DELETE FROM users WHERE email IN (?, ?)', [$aEmail, $bEmail]);
}

    summary('Account (Prompts 18 & 23)');
