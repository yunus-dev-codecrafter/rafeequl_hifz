<?php

declare(strict_types=1);

/**
 * Unit tests: Prompt 10 revision validators (no database needed).
 * Run: php tests/Unit/RevisionValidatorsTest.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Exceptions\ValidationException;
use App\Validators\CreatePlanValidator;
use App\Validators\FinishSessionValidator;
use App\Validators\GenerateCycleValidator;
use App\Validators\PlanStatusValidator;
use App\Validators\SegmentStatusValidator;
use App\Validators\SessionProgressValidator;
use App\Validators\StartSessionValidator;
use App\Validators\UpdateTargetValidator;
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

// --- create plan ------------------------------------------------------------
$create = new CreatePlanValidator();

checkEquals(
    'create: full payload sanitized',
    ['target_unit' => 'page', 'daily_amount' => 4.5, 'name' => 'Main cycle'],
    $create->validate(['target_unit' => 'page', 'daily_amount' => '4.5', 'name' => '  Main cycle  '])
);
checkEquals(
    'create: omitted fields stay null (defaults apply later)',
    ['target_unit' => null, 'daily_amount' => null, 'name' => null],
    $create->validate([])
);
checkEquals(
    'create: integer amount passes',
    ['target_unit' => 'hizb', 'daily_amount' => 2, 'name' => null],
    $create->validate(['target_unit' => 'hizb', 'daily_amount' => 2])
);
checkEquals(
    'create: all units accepted',
    ['target_unit' => 'rub', 'daily_amount' => 1, 'name' => null],
    $create->validate(['target_unit' => 'rub', 'daily_amount' => 1])
);

expectErrors('create: unsupported unit', $create, ['target_unit' => 'verse'], 'target_unit');
expectErrors('create: zero amount', $create, ['daily_amount' => 0], 'daily_amount');
expectErrors('create: negative amount', $create, ['daily_amount' => -1], 'daily_amount');
expectErrors('create: non-numeric amount', $create, ['daily_amount' => 'lots'], 'daily_amount');
expectErrors('create: amount beyond schema bound', $create, ['daily_amount' => 10000], 'daily_amount');
expectErrors('create: overlong name', $create, ['name' => str_repeat('n', 121)], 'name');

// --- update target ----------------------------------------------------------
$update = new UpdateTargetValidator();

checkEquals(
    'target: valid payload sanitized',
    ['target_unit' => 'juz', 'daily_amount' => 1],
    $update->validate(['target_unit' => 'juz', 'daily_amount' => '1'])
);

expectErrors('target: missing unit', $update, ['daily_amount' => 2], 'target_unit');
expectErrors('target: missing amount', $update, ['target_unit' => 'page'], 'daily_amount');
expectErrors('target: unsupported unit', $update, ['target_unit' => 'verse', 'daily_amount' => 1], 'target_unit');
expectErrors('target: zero amount', $update, ['target_unit' => 'page', 'daily_amount' => 0], 'daily_amount');

// --- plan status ------------------------------------------------------------
$status = new PlanStatusValidator();

checkEquals('status: active', ['status' => 'active'], $status->validate(['status' => 'active']));
checkEquals('status: paused', ['status' => 'paused'], $status->validate(['status' => 'paused']));
checkEquals('status: completed', ['status' => 'completed'], $status->validate(['status' => 'completed']));

expectErrors('status: missing', $status, [], 'status');
expectErrors('status: unsupported value', $status, ['status' => 'archived'], 'status');

// --- generate cycle ---------------------------------------------------------
$generate = new GenerateCycleValidator();

checkEquals('generate: omitted regenerate', ['regenerate' => null], $generate->validate([]));
checkEquals('generate: regenerate true coerced', ['regenerate' => 1], $generate->validate(['regenerate' => true]));
checkEquals('generate: regenerate false coerced', ['regenerate' => 0], $generate->validate(['regenerate' => false]));
checkEquals('generate: string flag coerced', ['regenerate' => 1], $generate->validate(['regenerate' => 'true']));

expectErrors('generate: non-boolean flag', $generate, ['regenerate' => 'maybe'], 'regenerate');

// --- segment status ---------------------------------------------------------
$segment = new SegmentStatusValidator();

checkEquals('segment: skip accepted', ['status' => 'skipped'], $segment->validate(['status' => 'skipped']));

expectErrors('segment: missing status', $segment, [], 'status');
expectErrors('segment: non-skip status', $segment, ['status' => 'completed'], 'status');

// --- start session ----------------------------------------------------------
$start = new StartSessionValidator();

checkEquals(
    'start: valid payload sanitized',
    ['segment_id' => 7, 'resumes_session_id' => 3],
    $start->validate(['segment_id' => '7', 'resumes_session_id' => '3'])
);
checkEquals(
    'start: resume may be omitted',
    ['segment_id' => 7, 'resumes_session_id' => null],
    $start->validate(['segment_id' => 7])
);

expectErrors('start: missing segment', $start, [], 'segment_id');
expectErrors('start: zero segment', $start, ['segment_id' => 0], 'segment_id');
expectErrors('start: non-integer segment', $start, ['segment_id' => 'abc'], 'segment_id');
expectErrors('start: non-integer resume', $start, ['segment_id' => 1, 'resumes_session_id' => 'x'], 'resumes_session_id');

// --- session progress -------------------------------------------------------
$progress = new SessionProgressValidator();

checkEquals('progress: valid payload', ['last_page_reached' => 4], $progress->validate(['last_page_reached' => '4']));

expectErrors('progress: missing page', $progress, [], 'last_page_reached');
expectErrors('progress: non-integer page', $progress, ['last_page_reached' => 'four'], 'last_page_reached');
expectErrors('progress: zero page', $progress, ['last_page_reached' => 0], 'last_page_reached');

// --- finish session ---------------------------------------------------------
$finish = new FinishSessionValidator();

checkEquals(
    'finish: completed payload',
    ['status' => 'completed', 'last_page_reached' => null, 'interruption_reason' => null, 'notes' => null],
    $finish->validate(['status' => 'completed'])
);
checkEquals(
    'finish: interrupted with reason',
    ['status' => 'interrupted', 'last_page_reached' => 3, 'interruption_reason' => 'visitor', 'notes' => 'resume later'],
    $finish->validate(['status' => 'interrupted', 'last_page_reached' => '3', 'interruption_reason' => ' visitor ', 'notes' => ' resume later '])
);
checkEquals(
    'finish: partial may be omitted',
    ['status' => 'partial', 'last_page_reached' => null, 'interruption_reason' => null, 'notes' => null],
    $finish->validate(['status' => 'partial'])
);

expectErrors('finish: missing status', $finish, [], 'status');
expectErrors('finish: unsupported status', $finish, ['status' => 'started'], 'status');
expectErrors('finish: non-integer page', $finish, ['status' => 'partial', 'last_page_reached' => 'x'], 'last_page_reached');
expectErrors('finish: overlong reason', $finish, ['status' => 'interrupted', 'interruption_reason' => str_repeat('r', 191)], 'interruption_reason');
expectErrors('finish: overlong notes', $finish, ['status' => 'partial', 'notes' => str_repeat('n', 256)], 'notes');

exit(summary('RevisionValidators'));
