<?php

declare(strict_types=1);

/**
 * Unit tests: Prompt 18 settings/account validators (no database needed).
 * Run: php tests/Unit/SettingsValidatorsTest.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Exceptions\ValidationException;
use App\Validators\ChangePasswordValidator;
use App\Validators\DeleteAccountValidator;
use App\Validators\UpdateProfileValidator;
use App\Validators\UpdateSettingsValidator;
use App\Validators\Validator;

/** Asserts validation fails with the first error on $field. */
function expectErrors(string $name, Validator $validator, array $input, string $field): void
{
    try {
        $validator->validate($input);
        check($name, false, 'nothing thrown');
        return;
    } catch (ValidationException $e) {
        $errors = $e->getErrors();
        $actual = $errors[0]['field'] ?? null;
        check($name, $actual === $field, 'expected field ' . $field . ' got ' . json_encode($errors));
    }
}

// --- settings ---------------------------------------------------------------
$settings = new UpdateSettingsValidator();

checkEquals(
    'settings: empty payload keeps every field null',
    ['theme' => null, 'sound_enabled' => null, 'screen_awake_enabled' => null, 'locale' => null, 'daily_revision_unit' => null, 'daily_revision_amount' => null],
    $settings->validate([])
);
checkEquals(
    'settings: full payload sanitized',
    ['theme' => 'light', 'sound_enabled' => 1, 'screen_awake_enabled' => 0, 'locale' => 'ar', 'daily_revision_unit' => 'rub', 'daily_revision_amount' => 1.5],
    $settings->validate([
        'theme' => '  light  ',
        'sound_enabled' => true,
        'screen_awake_enabled' => false,
        'locale' => 'ar',
        'daily_revision_unit' => 'rub',
        'daily_revision_amount' => '1.5',
    ])
);
checkEquals(
    'settings: string booleans coerced',
    1,
    $settings->validate(['sound_enabled' => 'true'])['sound_enabled']
);
checkEquals(
    'settings: string false coerced',
    0,
    $settings->validate(['screen_awake_enabled' => '0'])['screen_awake_enabled']
);
checkEquals(
    'settings: integer amount stays int',
    2,
    $settings->validate(['daily_revision_amount' => '2'])['daily_revision_amount']
);
checkEquals(
    'settings: empty string normalized to keep-current (null)',
    null,
    $settings->validate(['theme' => ''])['theme']
);
checkEquals(
    'settings: whitespace-only string normalized to keep-current (null)',
    null,
    $settings->validate(['locale' => '   '])['locale']
);

expectErrors('settings: bad theme', $settings, ['theme' => 'blue'], 'theme');
expectErrors('settings: bad locale', $settings, ['locale' => 'fr'], 'locale');
expectErrors('settings: bad unit', $settings, ['daily_revision_unit' => 'aya'], 'daily_revision_unit');
expectErrors('settings: amount below floor', $settings, ['daily_revision_amount' => 0], 'daily_revision_amount');
expectErrors('settings: amount above ceiling', $settings, ['daily_revision_amount' => 10000], 'daily_revision_amount');
expectErrors('settings: non-numeric amount', $settings, ['daily_revision_amount' => 'abc'], 'daily_revision_amount');
expectErrors('settings: non-boolean sound', $settings, ['sound_enabled' => 'maybe'], 'sound_enabled');

// --- profile ----------------------------------------------------------------
$profile = new UpdateProfileValidator();

checkEquals(
    'profile: empty payload keeps fields null',
    ['display_name' => null, 'email' => null],
    $profile->validate([])
);
checkEquals(
    'profile: full payload sanitized',
    ['display_name' => 'Yusuf', 'email' => 'user@example.com'],
    $profile->validate(['display_name' => '  Yusuf  ', 'email' => 'user@example.com'])
);

expectErrors('profile: bad email', $profile, ['email' => 'nope'], 'email');
expectErrors('profile: overlong name', $profile, ['display_name' => str_repeat('n', 101)], 'display_name');
expectErrors('profile: overlong email', $profile, ['email' => str_repeat('e', 189) . '@example.com'], 'email');

// --- password change --------------------------------------------------------
$password = new ChangePasswordValidator();

expectErrors('password: missing current', $password, [], 'current_password');
expectErrors('password: missing new', $password, ['current_password' => 'Password123!'], 'new_password');
expectErrors('password: short new', $password, ['current_password' => 'Password123!', 'new_password' => 'Short7!'], 'new_password');
expectErrors('password: long new', $password, ['current_password' => 'Password123!', 'new_password' => str_repeat('a', 73)], 'new_password');
checkEquals(
    'password: valid passthrough trimmed',
    ['current_password' => 'Password123!', 'new_password' => 'NewSecret456!'],
    $password->validate(['current_password' => '  Password123!  ', 'new_password' => 'NewSecret456!'])
);

// --- delete account ---------------------------------------------------------
$delete = new DeleteAccountValidator();

expectErrors('delete: missing password', $delete, [], 'password');
expectErrors('delete: short password', $delete, ['password' => 'Short7!'], 'password');
checkEquals(
    'delete: valid passthrough trimmed',
    ['password' => 'Password123!'],
    $delete->validate(['password' => ' Password123! '])
);

summary('Settings validators (Prompt 18)');
