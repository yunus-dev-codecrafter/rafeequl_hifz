<?php

declare(strict_types=1);

/**
 * Integration tests: user preferences (Prompt 18) — defaults on register,
 * the auto/light/dark ↔ auto/day/night mapping, partial updates, the
 * revision-default boundary (defaults feed only plan creation, existing
 * plans are never rewritten) and the hard isolation from Hifz tables.
 * Run: php tests/Integration/SettingsTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Services\MemorizationProgressService;
use App\Services\RevisionService;
use App\Services\SettingsService;
use App\Validators\UpdateSettingsValidator;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$aEmail = 'settings-test@example.com';
$bEmail = 'settings-test-2@example.com';

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

    $a = $register($aEmail, 'Settings A');
    $b = $register($bEmail, 'Settings B');

    $svc = new SettingsService();
    $validator = new UpdateSettingsValidator();
    $update = static fn (int $userId, array $input): array =>
        $svc->update($userId, $validator->validate($input));
    $rawTheme = static fn (int $userId): string =>
        (string) Database::scalar('SELECT theme FROM user_settings WHERE user_id = ?', [$userId]);

    // === defaults after register ============================================
    $defaults = $svc->show($a);
    checkEquals('defaults: theme auto', 'auto', $defaults['theme']);
    checkEquals('defaults: sound on', true, $defaults['sound_enabled']);
    checkEquals('defaults: screen awake off', false, $defaults['screen_awake_enabled']);
    checkEquals('defaults: locale ar (Arabic-first)', 'ar', $defaults['locale']);
    checkEquals('defaults: revision unit page', 'page', $defaults['daily_revision_unit']);
    checkEquals('defaults: revision amount 1', 1.0, $defaults['daily_revision_amount']);
    checkEquals('defaults: show is stable', $defaults, $svc->show($a));

    // === theme mapping (API auto|light|dark ↔ DB auto|day|night) ============
    checkEquals('theme: light represented as light', 'light', $update($a, ['theme' => 'light'])['theme']);
    checkEquals('theme: light stored as day', 'day', $rawTheme($a));
    $update($a, ['theme' => 'dark']);
    checkEquals('theme: dark stored as night', 'night', $rawTheme($a));
    checkEquals('theme: dark represented as dark', 'dark', $svc->show($a)['theme']);
    $update($a, ['theme' => 'auto']);
    checkEquals('theme: auto stored as auto', 'auto', $rawTheme($a));
    checkEquals('theme: auto represented as auto', 'auto', $svc->show($a)['theme']);

    // Same-value write is an idempotent no-op (still 200 + representation).
    $update($a, ['theme' => 'dark']);
    checkEquals('theme: same-value write idempotent', 'dark', $update($a, ['theme' => 'dark'])['theme']);

    // === partial updates ====================================================
    $update($a, ['theme' => 'auto']);
    $before = $svc->show($a);
    $after = $update($a, ['sound_enabled' => false]);
    checkEquals('partial: sound flips to false', false, $after['sound_enabled']);
    checkEquals('partial: theme untouched', $before['theme'], $after['theme']);
    checkEquals('partial: locale untouched', $before['locale'], $after['locale']);
    checkEquals('partial: revision defaults untouched', $before['daily_revision_unit'], $after['daily_revision_unit']);
    checkEquals('awake: toggles on', true, $update($a, ['screen_awake_enabled' => true])['screen_awake_enabled']);
    checkEquals('locale: en persists', 'en', $update($a, ['locale' => 'en'])['locale']);
    checkEquals('locale: back to ar', 'ar', $update($a, ['locale' => 'ar'])['locale']);

    // Empty string = keep current (validator passes it through untouched).
    $keep = $update($a, ['theme' => '', 'sound_enabled' => '']);
    checkEquals('empty theme keeps current', $before['theme'], $keep['theme']);
    checkEquals('empty sound keeps current', $after['sound_enabled'], $keep['sound_enabled']);

    // Amount rounding: only what was provided changes, rounded to 3 dp.
    $rounded = $update($a, ['daily_revision_amount' => '1.5678']);
    checkEquals('amount rounded to 3 dp', 1.568, $rounded['daily_revision_amount']);

    // Validation is enforced before persistence (same path as the controller).
    checkThrows('invalid theme 422', fn () => $update($a, ['theme' => 'blue']), ValidationException::class, 'theme');
    checkThrows('invalid locale 422', fn () => $update($a, ['locale' => 'fr']), ValidationException::class, 'locale');
    checkThrows('invalid unit 422', fn () => $update($a, ['daily_revision_unit' => 'aya']), ValidationException::class, 'daily_revision_unit');
    checkThrows('amount below floor 422', fn () => $update($a, ['daily_revision_amount' => 0]), ValidationException::class, 'daily_revision_amount');
    checkEquals('failed validation persists nothing', $before['daily_revision_unit'], $svc->show($a)['daily_revision_unit']);

    // === isolation: user B is untouched =====================================
    checkEquals('isolation: B theme default', 'auto', $svc->show($b)['theme']);
    checkEquals('isolation: B locale default', 'ar', $svc->show($b)['locale']);
    checkEquals('isolation: B revision default', 'page', $svc->show($b)['daily_revision_unit']);
    checkEquals('isolation: B amount default', 1.0, $svc->show($b)['daily_revision_amount']);

    // === revision consequence: defaults feed only future plan creation ======
    $progress = new MemorizationProgressService();
    $revision = new RevisionService();
    $progress->establish($a, 1, 12);
    $progress->establish($b, 1, 8);

    $update($a, ['daily_revision_unit' => 'hizb', 'daily_revision_amount' => '2']);
    $planA = $revision->createPlan($a, null, null, null);
    checkEquals('plan: falls back to unit hizb', 'hizb', $planA['plan']['target_unit']);
    checkEquals('plan: falls back to amount 2', 2.0, $planA['plan']['daily_amount']);

    $planB = $revision->createPlan($b, 'page', 4, 'Main cycle');
    checkEquals('plan: explicit target beats defaults', 'page', $planB['plan']['target_unit']);
    checkEquals('plan: explicit amount beats defaults', 4.0, $planB['plan']['daily_amount']);

    // A preference change never rewrites an existing plan.
    $update($a, ['daily_revision_unit' => 'juz', 'daily_revision_amount' => '5']);
    checkEquals(
        'plan: existing plan not rewritten by preference change',
        'hizb',
        (string) Database::scalar('SELECT target_unit FROM revision_plans WHERE id = ?', [$planA['plan']['id']])
    );
    checkEquals(
        'plan: existing amount not rewritten',
        2.0,
        (float) Database::scalar('SELECT daily_amount FROM revision_plans WHERE id = ?', [$planA['plan']['id']])
    );

    // === boundary: settings writes touch no Hifz tables =====================
    $hifzCount = static fn (): array => [
        (int) Database::scalar('SELECT COUNT(*) FROM memorization_states WHERE user_id = ?', [$a]),
        (int) Database::scalar('SELECT COUNT(*) FROM memorization_history WHERE user_id = ?', [$a]),
        (int) Database::scalar('SELECT COUNT(*) FROM revision_plans WHERE user_id = ?', [$a]),
        (int) Database::scalar('SELECT COUNT(*) FROM revision_cycles WHERE user_id = ?', [$a]),
        (int) Database::scalar('SELECT COUNT(*) FROM revision_segments WHERE user_id = ?', [$a]),
    ];
    $beforeCounts = $hifzCount();
    $update($a, ['theme' => 'dark', 'daily_revision_unit' => 'rub']);
    checkEquals('boundary: settings update touches no Hifz tables', $beforeCounts, $hifzCount());
} finally {
    Database::run('DELETE FROM users WHERE email IN (?, ?)', [$aEmail, $bEmail]);
}

summary('Settings (Prompt 18)');
