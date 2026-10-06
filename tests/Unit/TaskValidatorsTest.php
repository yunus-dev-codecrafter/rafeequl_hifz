<?php

declare(strict_types=1);

/**
 * Unit tests: Prompt 14 daily task validators (no database needed).
 * Run: php tests/Unit/TaskValidatorsTest.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Exceptions\ValidationException;
use App\Validators\CreateTaskValidator;
use App\Validators\TaskDayQueryValidator;
use App\Validators\TaskHistoryValidator;
use App\Validators\TaskStatusValidator;
use App\Validators\UpdateTaskValidator;
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
        return;
    }
}

// --- create ------------------------------------------------------------------
$create = new CreateTaskValidator();

checkEquals(
    'create: full payload sanitized',
    ['task_type_id' => 3, 'title' => 'Daily portion', 'duration_minutes' => 45, 'scheduled_date' => '2026-10-05', 'notes' => 'after fajr'],
    $create->validate(['task_type_id' => '3', 'title' => '  Daily portion  ', 'duration_minutes' => '45', 'scheduled_date' => '2026-10-05', 'notes' => '  after fajr  '])
);
checkEquals(
    'create: only type is required',
    ['task_type_id' => 1, 'title' => null, 'duration_minutes' => null, 'scheduled_date' => null, 'notes' => null],
    $create->validate(['task_type_id' => 1])
);
checkEquals(
    'create: boundary duration accepted',
    ['task_type_id' => 1, 'title' => null, 'duration_minutes' => 1, 'scheduled_date' => null, 'notes' => null],
    $create->validate(['task_type_id' => 1, 'duration_minutes' => 1])
);
checkEquals(
    'create: max duration accepted',
    ['task_type_id' => 1, 'title' => null, 'duration_minutes' => 1440, 'scheduled_date' => null, 'notes' => null],
    $create->validate(['task_type_id' => 1, 'duration_minutes' => 1440])
);

expectErrors('create: missing type', $create, [], 'task_type_id');
expectErrors('create: zero type', $create, ['task_type_id' => 0], 'task_type_id');
expectErrors('create: non-integer type', $create, ['task_type_id' => 'x'], 'task_type_id');
expectErrors('create: zero duration', $create, ['task_type_id' => 1, 'duration_minutes' => 0], 'duration_minutes');
expectErrors('create: duration beyond day', $create, ['task_type_id' => 1, 'duration_minutes' => 1441], 'duration_minutes');
expectErrors('create: overlong title', $create, ['task_type_id' => 1, 'title' => str_repeat('t', 151)], 'title');
expectErrors('create: bad date', $create, ['task_type_id' => 1, 'scheduled_date' => 'tomorrow'], 'scheduled_date');
expectErrors('create: overlong notes', $create, ['task_type_id' => 1, 'notes' => str_repeat('n', 256)], 'notes');

// --- update ------------------------------------------------------------------
$update = new UpdateTaskValidator();

checkEquals(
    'update: full payload sanitized',
    ['task_type_id' => 2, 'title' => 'Renamed', 'duration_minutes' => 25, 'notes' => 'moved slot'],
    $update->validate(['task_type_id' => '2', 'title' => '  Renamed  ', 'duration_minutes' => '25', 'notes' => '  moved slot  '])
);
checkEquals(
    'update: empty payload keeps everything',
    ['task_type_id' => null, 'title' => null, 'duration_minutes' => null, 'notes' => null],
    $update->validate([])
);

expectErrors('update: zero type', $update, ['task_type_id' => 0], 'task_type_id');
expectErrors('update: zero duration', $update, ['duration_minutes' => 0], 'duration_minutes');
expectErrors('update: overlong title', $update, ['title' => str_repeat('t', 151)], 'title');
expectErrors('update: overlong notes', $update, ['notes' => str_repeat('n', 256)], 'notes');

// --- status ------------------------------------------------------------------
$status = new TaskStatusValidator();

checkEquals(
    'status: full payload sanitized',
    ['status' => 'completed', 'actual_duration_seconds' => 1200, 'note' => 'finished early'],
    $status->validate(['status' => 'completed', 'actual_duration_seconds' => '1200', 'note' => '  finished early  '])
);
checkEquals(
    'status: completion fields optional',
    ['status' => 'active', 'actual_duration_seconds' => null, 'note' => null],
    $status->validate(['status' => 'active'])
);
checkEquals('status: pending accepted', 'pending', $status->validate(['status' => 'pending'])['status']);
checkEquals('status: skipped accepted', 'skipped', $status->validate(['status' => 'skipped'])['status']);

expectErrors('status: missing', $status, [], 'status');
expectErrors('status: unsupported value', $status, ['status' => 'paused'], 'status');
expectErrors('status: negative duration', $status, ['status' => 'completed', 'actual_duration_seconds' => -1], 'actual_duration_seconds');
expectErrors('status: duration beyond bound', $status, ['status' => 'completed', 'actual_duration_seconds' => 86401], 'actual_duration_seconds');
expectErrors('status: non-integer duration', $status, ['status' => 'completed', 'actual_duration_seconds' => 'fast'], 'actual_duration_seconds');
expectErrors('status: overlong note', $status, ['status' => 'completed', 'note' => str_repeat('n', 256)], 'note');

// --- day query ---------------------------------------------------------------
$day = new TaskDayQueryValidator();

checkEquals('day: date accepted', ['date' => '2026-10-05'], $day->validate(['date' => '2026-10-05']));
checkEquals('day: absent date stays null', ['date' => null], $day->validate([]));
expectErrors('day: bad date', $day, ['date' => '05-10-2026'], 'date');

// --- history query -----------------------------------------------------------
$history = new TaskHistoryValidator();

checkEquals(
    'history: range sanitized',
    ['from' => '2026-09-01', 'to' => '2026-10-05', 'limit' => 30],
    $history->validate(['from' => '2026-09-01', 'to' => '2026-10-05', 'limit' => '30'])
);
checkEquals(
    'history: limit optional',
    ['from' => '2026-10-01', 'to' => '2026-10-05', 'limit' => null],
    $history->validate(['from' => '2026-10-01', 'to' => '2026-10-05'])
);

expectErrors('history: missing from', $history, ['to' => '2026-10-05'], 'from');
expectErrors('history: missing to', $history, ['from' => '2026-10-01'], 'to');
expectErrors('history: bad from', $history, ['from' => 'yesterday', 'to' => '2026-10-05'], 'from');
expectErrors('history: zero limit', $history, ['from' => '2026-10-01', 'to' => '2026-10-05', 'limit' => 0], 'limit');
expectErrors('history: limit beyond bound', $history, ['from' => '2026-10-01', 'to' => '2026-10-05', 'limit' => 101], 'limit');

exit(summary('TaskValidators'));
