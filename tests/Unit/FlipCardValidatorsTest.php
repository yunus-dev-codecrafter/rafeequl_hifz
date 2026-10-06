<?php

declare(strict_types=1);

/**
 * Unit tests: Prompt 13 flip card validators (no database needed).
 * Run: php tests/Unit/FlipCardValidatorsTest.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Exceptions\ValidationException;
use App\Validators\FlipCardCreateValidator;
use App\Validators\FlipCardQueryValidator;
use App\Validators\FlipCardReviewValidator;
use App\Validators\FlipCardStatusValidator;
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

// --- create (flag an error) --------------------------------------------------
$create = new FlipCardCreateValidator();

$full = [
    'surah_number' => 3,
    'ayah_number' => 7,
    'page_number' => 12,
    'category_id' => 2,
    'error_note' => 'Skipped the opening word',
    'context_note' => 'During murajaah',
    'severity' => 'high',
];
checkEquals(
    'create: full payload sanitized',
    $full,
    $create->validate([
        'surah_number' => '3',
        'ayah_number' => '7',
        'page_number' => '12',
        'category_id' => '2',
        'error_note' => '  Skipped the opening word  ',
        'context_note' => '  During murajaah  ',
        'severity' => 'high',
    ])
);
checkEquals(
    'create: optionals omitted stay null',
    [
        'surah_number' => 1,
        'ayah_number' => 1,
        'page_number' => 1,
        'category_id' => 1,
        'error_note' => 'Forgot the verse',
        'context_note' => null,
        'severity' => null,
    ],
    $create->validate([
        'surah_number' => 1,
        'ayah_number' => 1,
        'page_number' => 1,
        'category_id' => 1,
        'error_note' => 'Forgot the verse',
    ])
);
checkEquals(
    'create: low severity accepted',
    'low',
    $create->validate([
        'surah_number' => 2,
        'ayah_number' => 3,
        'page_number' => 6,
        'category_id' => 3,
        'error_note' => 'x',
        'severity' => 'low',
    ])['severity']
);

expectErrors('create: missing surah', $create, ['ayah_number' => 1, 'page_number' => 1, 'category_id' => 1, 'error_note' => 'x'], 'surah_number');
expectErrors('create: missing ayah', $create, ['surah_number' => 1, 'page_number' => 1, 'category_id' => 1, 'error_note' => 'x'], 'ayah_number');
expectErrors('create: missing page', $create, ['surah_number' => 1, 'ayah_number' => 1, 'category_id' => 1, 'error_note' => 'x'], 'page_number');
expectErrors('create: missing category', $create, ['surah_number' => 1, 'ayah_number' => 1, 'page_number' => 1, 'error_note' => 'x'], 'category_id');
expectErrors('create: missing note', $create, ['surah_number' => 1, 'ayah_number' => 1, 'page_number' => 1, 'category_id' => 1], 'error_note');
expectErrors('create: whitespace-only note', $create, ['surah_number' => 1, 'ayah_number' => 1, 'page_number' => 1, 'category_id' => 1, 'error_note' => '   '], 'error_note');
expectErrors('create: zero surah', $create, ['surah_number' => 0, 'ayah_number' => 1, 'page_number' => 1, 'category_id' => 1, 'error_note' => 'x'], 'surah_number');
expectErrors('create: negative ayah', $create, ['surah_number' => 1, 'ayah_number' => -1, 'page_number' => 1, 'category_id' => 1, 'error_note' => 'x'], 'ayah_number');
expectErrors('create: non-integer page', $create, ['surah_number' => 1, 'ayah_number' => 1, 'page_number' => 'twelve', 'category_id' => 1, 'error_note' => 'x'], 'page_number');
expectErrors('create: non-integer category', $create, ['surah_number' => 1, 'ayah_number' => 1, 'page_number' => 1, 'category_id' => 'x', 'error_note' => 'x'], 'category_id');
expectErrors('create: overlong note', $create, ['surah_number' => 1, 'ayah_number' => 1, 'page_number' => 1, 'category_id' => 1, 'error_note' => str_repeat('n', 501)], 'error_note');
expectErrors('create: overlong context', $create, ['surah_number' => 1, 'ayah_number' => 1, 'page_number' => 1, 'category_id' => 1, 'error_note' => 'x', 'context_note' => str_repeat('c', 501)], 'context_note');
expectErrors('create: unsupported severity', $create, ['surah_number' => 1, 'ayah_number' => 1, 'page_number' => 1, 'category_id' => 1, 'error_note' => 'x', 'severity' => 'critical'], 'severity');

// --- review ------------------------------------------------------------------
$review = new FlipCardReviewValidator();

checkEquals(
    'review: full payload sanitized',
    ['result' => 'forgotten', 'duration_seconds' => 45, 'notes' => 'mixed up with 2:3'],
    $review->validate(['result' => 'forgotten', 'duration_seconds' => '45', 'notes' => '  mixed up with 2:3  '])
);
checkEquals(
    'review: optionals omitted stay null',
    ['result' => 'recalled', 'duration_seconds' => null, 'notes' => null],
    $review->validate(['result' => 'recalled'])
);
checkEquals(
    'review: partial accepted',
    'partial',
    $review->validate(['result' => 'partial'])['result']
);
checkEquals(
    'review: zero duration accepted',
    ['result' => 'recalled', 'duration_seconds' => 0, 'notes' => null],
    $review->validate(['result' => 'recalled', 'duration_seconds' => 0])
);

expectErrors('review: missing result', $review, [], 'result');
expectErrors('review: unsupported result', $review, ['result' => 'maybe'], 'result');
expectErrors('review: negative duration', $review, ['result' => 'recalled', 'duration_seconds' => -1], 'duration_seconds');
expectErrors('review: duration beyond bound', $review, ['result' => 'recalled', 'duration_seconds' => 86401], 'duration_seconds');
expectErrors('review: non-integer duration', $review, ['result' => 'recalled', 'duration_seconds' => 'fast'], 'duration_seconds');
expectErrors('review: overlong notes', $review, ['result' => 'recalled', 'notes' => str_repeat('n', 256)], 'notes');

// --- status ------------------------------------------------------------------
$status = new FlipCardStatusValidator();

checkEquals('status: active', ['status' => 'active'], $status->validate(['status' => 'active']));
checkEquals('status: in_review', ['status' => 'in_review'], $status->validate(['status' => 'in_review']));
checkEquals('status: mastered', ['status' => 'mastered'], $status->validate(['status' => 'mastered']));
checkEquals('status: archived', ['status' => 'archived'], $status->validate(['status' => 'archived']));

expectErrors('status: missing', $status, [], 'status');
expectErrors('status: unsupported value', $status, ['status' => 'queued'], 'status');

// --- query (list/queue) ------------------------------------------------------
$query = new FlipCardQueryValidator();

checkEquals(
    'query: full query sanitized',
    ['limit' => 10, 'status' => 'active', 'category_id' => 3],
    $query->validate(['limit' => '10', 'status' => 'active', 'category_id' => '3'])
);
checkEquals(
    'query: absent values stay null',
    ['limit' => null, 'status' => null, 'category_id' => null],
    $query->validate([])
);
checkEquals(
    'query: boundary limit accepted',
    ['limit' => 100, 'status' => null, 'category_id' => null],
    $query->validate(['limit' => 100])
);

expectErrors('query: zero limit', $query, ['limit' => 0], 'limit');
expectErrors('query: limit beyond bound', $query, ['limit' => 101], 'limit');
expectErrors('query: non-integer limit', $query, ['limit' => 'ten'], 'limit');
expectErrors('query: fractional limit', $query, ['limit' => 2.5], 'limit');
expectErrors('query: unsupported status filter', $query, ['status' => 'queued'], 'status');
expectErrors('query: zero category', $query, ['category_id' => 0], 'category_id');

exit(summary('FlipCardValidators'));
