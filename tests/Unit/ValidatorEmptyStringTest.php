<?php

declare(strict_types=1);

/**
 * Unit tests: Prompt 24 empty-input contract of the Validator root.
 *
 * An optional field given `""` (or whitespace only) means "not provided"
 * and normalizes to null; fields listed in rejectEmpty() reject present-
 * empty input with 422 instead (e.g. a cleared profile email). Required
 * fields keep failing on empty input. No database needed.
 * Run: php tests/Unit/ValidatorEmptyStringTest.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Exceptions\ValidationException;
use App\Validators\CreateTaskValidator;
use App\Validators\FlipCardQueryValidator;
use App\Validators\RegisterValidator;
use App\Validators\TaskDayQueryValidator;
use App\Validators\TaskHistoryValidator;
use App\Validators\UpdateProfileValidator;
use App\Validators\UpdateSettingsValidator;
use App\Validators\Validator;

/** Asserts validation fails with an error on $field (optionally an exact message). */
function expectField(string $name, Validator $validator, array $input, string $field, ?string $message = null): void
{
    try {
        $validator->validate($input);
        check($name, false, 'nothing thrown');
        return;
    } catch (ValidationException $e) {
        $errors = $e->getErrors();
        $actual = $errors[0]['field'] ?? null;
        $ok = $actual === $field
            && ($message === null || ($errors[0]['message'] ?? null) === $message);
        check($name, $ok, 'expected field ' . $field . ($message !== null ? ' / ' . $message : '')
            . ' got ' . json_encode($errors));
    }
}

// --- optional settings fields: empty/whitespace == absent ---------------------
$settings = new UpdateSettingsValidator();
checkEquals(
    'settings: every empty string normalizes to null (keep-current)',
    ['theme' => null, 'sound_enabled' => null, 'screen_awake_enabled' => null, 'locale' => null, 'daily_revision_unit' => null, 'daily_revision_amount' => null],
    $settings->validate([
        'theme' => '',
        'sound_enabled' => '',
        'screen_awake_enabled' => '',
        'locale' => '',
        'daily_revision_unit' => '',
        'daily_revision_amount' => '',
    ])
);
checkEquals(
    'settings: whitespace-only string normalizes to null',
    null,
    $settings->validate(['theme' => '   '])['theme']
);
checkEquals(
    'settings: whitespace-only amount normalizes to null',
    null,
    $settings->validate(['daily_revision_amount' => "\t\n"])['daily_revision_amount']
);
checkEquals(
    'settings: valid values still pass through',
    'dark',
    $settings->validate(['theme' => ' dark '])['theme']
);

// --- profile: rejectEmpty() field vs ordinary optional field ------------------
$profile = new UpdateProfileValidator();
expectField(
    'profile: empty email rejected (rejectEmpty)',
    $profile,
    ['email' => ''],
    'email',
    'Email must be a valid email address'
);
expectField(
    'profile: whitespace email rejected (rejectEmpty)',
    $profile,
    ['email' => '   '],
    'email',
    'Email must be a valid email address'
);
checkEquals(
    'profile: empty display_name is keep-current (null)',
    null,
    $profile->validate(['display_name' => ''])['display_name']
);
checkEquals(
    'profile: absent email stays null',
    null,
    $profile->validate([])['email']
);
checkEquals(
    'profile: valid email passes',
    'User@Example.com',
    $profile->validate(['email' => 'User@Example.com'])['email']
);

// --- required fields still fail on empty input --------------------------------
$register = new RegisterValidator();
expectField('register: empty email is required', $register, ['email' => '', 'password' => 'Password123!'], 'email');
expectField('register: whitespace email is required', $register, ['email' => '  ', 'password' => 'Password123!'], 'email');
expectField('register: empty password is required', $register, ['email' => 'a@b.co', 'password' => ''], 'password');

// --- query/payload validators: empty optional params become null ---------------
$day = new TaskDayQueryValidator();
checkEquals('day query: empty date normalizes to null (defaults today)', null, $day->validate(['date' => ''])['date']);
checkEquals('day query: whitespace date normalizes to null', null, $day->validate(['date' => '  '])['date']);
checkEquals('day query: valid date passes', '2026-01-15', $day->validate(['date' => '2026-01-15'])['date']);
expectField('day query: malformed date still rejected', $day, ['date' => '15-01-2026'], 'date');

$history = new TaskHistoryValidator();
expectField('history: empty from stays required', $history, ['from' => '', 'to' => '2026-01-31'], 'from');
checkEquals('history: empty limit normalizes to null (default applies)', null, $history->validate([
    'from' => '2026-01-01', 'to' => '2026-01-31', 'limit' => '',
])['limit']);

$create = new CreateTaskValidator();
checkEquals('create: empty scheduled_date normalizes to null (defaults today)', null, $create->validate([
    'task_type_id' => 1, 'scheduled_date' => '',
])['scheduled_date']);
checkEquals('create: whitespace notes normalize to null', null, $create->validate([
    'task_type_id' => 1, 'notes' => '   ',
])['notes']);

$query = new FlipCardQueryValidator();
checkEquals('flip query: empty limit normalizes to null', null, $query->validate(['limit' => ''])['limit']);
checkEquals('flip query: empty status normalizes to null', null, $query->validate(['status' => ''])['status']);
checkEquals('flip query: empty category normalizes to null', null, $query->validate(['category_id' => ''])['category_id']);
expectField('flip query: malformed status still rejected', $query, ['status' => 'queued'], 'status');

exit(summary('Validator empty-input contract (Prompt 24)'));
