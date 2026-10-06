<?php

declare(strict_types=1);

/**
 * Unit tests: Prompt 09 progress validators (no database needed).
 * Run: php tests/Unit/ProgressValidatorsTest.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Exceptions\ValidationException;
use App\Validators\CorrectBoundaryValidator;
use App\Validators\EstablishStateValidator;
use App\Validators\MarkMemorizedValidator;
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

// --- establish -------------------------------------------------------------
$est = new EstablishStateValidator();

checkEquals(
    'establish: valid payload sanitized to integers',
    ['memorized_start_page' => 1, 'current_boundary_page' => 5, 'note' => null],
    $est->validate(['memorized_start_page' => 1, 'current_boundary_page' => '5'])
);
checkEquals(
    'establish: optional note passes',
    ['memorized_start_page' => 3, 'current_boundary_page' => 9, 'note' => 'from paper list'],
    $est->validate(['memorized_start_page' => '3', 'current_boundary_page' => 9, 'note' => '  from paper list  '])
);

expectErrors('establish: missing start page', $est, ['current_boundary_page' => 4], 'memorized_start_page');
expectErrors('establish: missing boundary', $est, ['memorized_start_page' => 1], 'current_boundary_page');
expectErrors('establish: non-integer boundary', $est, ['memorized_start_page' => 1, 'current_boundary_page' => 'abc'], 'current_boundary_page');
expectErrors('establish: zero start page', $est, ['memorized_start_page' => 0, 'current_boundary_page' => 4], 'memorized_start_page');
expectErrors('establish: negative boundary', $est, ['memorized_start_page' => 1, 'current_boundary_page' => -2], 'current_boundary_page');
expectErrors('establish: overlong note', $est, ['memorized_start_page' => 1, 'current_boundary_page' => 4, 'note' => str_repeat('x', 256)], 'note');

// --- correct boundary ------------------------------------------------------
$correct = new CorrectBoundaryValidator();

checkEquals(
    'correct: valid payload with confirmation',
    ['current_boundary_page' => 6, 'confirm' => 1, 'note' => null],
    $correct->validate(['current_boundary_page' => '6', 'confirm' => true])
);
checkEquals(
    'correct: string confirmation coerced',
    ['current_boundary_page' => 6, 'confirm' => 1, 'note' => null],
    $correct->validate(['current_boundary_page' => 6, 'confirm' => 'true'])
);
checkEquals(
    'correct: explicit false stays 0 (service rejects it)',
    ['current_boundary_page' => 6, 'confirm' => 0, 'note' => null],
    $correct->validate(['current_boundary_page' => 6, 'confirm' => false])
);
checkEquals(
    'correct: confirmation may be omitted (service rejects it)',
    ['current_boundary_page' => 6, 'confirm' => null, 'note' => null],
    $correct->validate(['current_boundary_page' => 6])
);

expectErrors('correct: missing boundary', $correct, ['confirm' => true], 'current_boundary_page');
expectErrors('correct: non-integer boundary', $correct, ['current_boundary_page' => 'x', 'confirm' => true], 'current_boundary_page');
expectErrors('correct: non-boolean confirm', $correct, ['current_boundary_page' => 6, 'confirm' => 'maybe'], 'confirm');
expectErrors('correct: overlong note', $correct, ['current_boundary_page' => 6, 'confirm' => 1, 'note' => str_repeat('y', 256)], 'note');

// --- mark memorized --------------------------------------------------------
$mark = new MarkMemorizedValidator();

checkEquals(
    'mark: valid payload sanitized to integers',
    ['start_page' => 5, 'end_page' => 7, 'note' => 'test'],
    $mark->validate(['start_page' => '5', 'end_page' => 7, 'note' => ' test '])
);
checkEquals(
    'mark: note may be omitted',
    ['start_page' => 5, 'end_page' => 5, 'note' => null],
    $mark->validate(['start_page' => 5, 'end_page' => 5])
);

expectErrors('mark: missing start page', $mark, ['end_page' => 7], 'start_page');
expectErrors('mark: missing end page', $mark, ['start_page' => 5], 'end_page');
expectErrors('mark: non-integer start page', $mark, ['start_page' => 'five', 'end_page' => 7], 'start_page');
expectErrors('mark: zero end page', $mark, ['start_page' => 5, 'end_page' => 0], 'end_page');
expectErrors('mark: overlong note', $mark, ['start_page' => 5, 'end_page' => 7, 'note' => str_repeat('z', 256)], 'note');

exit(summary('ProgressValidators'));
