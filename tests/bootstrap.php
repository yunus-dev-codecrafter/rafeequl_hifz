<?php

declare(strict_types=1);

/**
 * Shared harness for the calculation test scripts (Prompt 08).
 * Each *Test.php file requires this, runs checks, ends with exit(summary()).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

// Test scripts are CLI tools: make every warning loud and every escape a
// non-zero exit, so a broken run can never look green.
ini_set('display_errors', '1');
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, 'UNCAUGHT: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
});

/** @var array{passed: int, failed: int} */
$GLOBALS['test_results'] = ['passed' => 0, 'failed' => 0];

function check(string $name, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['test_results']['passed']++;
        echo "ok    {$name}\n";
        return;
    }
    $GLOBALS['test_results']['failed']++;
    echo 'FAIL  ' . $name . ($detail !== '' ? '  ' . $detail : '') . "\n";
}

function checkEquals(string $name, mixed $expected, mixed $actual): void
{
    check(
        $name,
        $expected === $actual,
        'expected ' . json_encode($expected) . ' got ' . json_encode($actual)
    );
}

/**
 * Asserts $callback throws $exceptionClass; with $field, also asserts the
 * first envelope error carries that field (HttpException subclasses).
 */
function checkThrows(string $name, callable $callback, string $exceptionClass, ?string $field = null): void
{
    try {
        $callback();
    } catch (\Throwable $e) {
        if (!($e instanceof $exceptionClass)) {
            check($name, false, 'expected ' . $exceptionClass . ' got ' . $e::class . ': ' . $e->getMessage());
            return;
        }
        if ($field !== null) {
            if (!($e instanceof \App\Exceptions\HttpException)) {
                check($name, false, 'field check needs HttpException, got ' . $e::class);
                return;
            }
            $errors = $e->getErrors();
            $actualField = $errors[0]['field'] ?? null;
            check($name, $actualField === $field, 'expected field ' . $field . ' got ' . json_encode($actualField));
            return;
        }
        check($name, true);
        return;
    }
    check($name, false, 'nothing thrown (expected ' . $exceptionClass . ')');
}

/** Asserts segments tile exactly [start..end] with consistent page counts. */
function checkCovers(string $name, array $segments, int $start, int $end): void
{
    $cursor = $start;
    $ok = $segments !== [];
    foreach ($segments as $index => $segment) {
        $ok = $ok
            && $segment['segment_number'] === $index + 1
            && $segment['start_page'] === $cursor
            && $segment['end_page'] >= $segment['start_page']
            && $segment['page_count'] === $segment['end_page'] - $segment['start_page'] + 1;
        $cursor = $segment['end_page'] + 1;
    }
    if ($ok && $segments !== []) {
        $last = $segments[array_key_last($segments)];
        $ok = $last['end_page'] === $end;
    }
    check($name, $ok, json_encode($segments));
}

function summary(string $suite): int
{
    $passed = $GLOBALS['test_results']['passed'];
    $failed = $GLOBALS['test_results']['failed'];
    echo "----\n";
    echo $suite . ': ' . $passed . '/' . ($passed + $failed) . " passed\n";
    return $failed > 0 ? 1 : 0;
}

// ---------------------------------------------------------------------------
// Database-backed test support (integration tests only).
// ---------------------------------------------------------------------------

/** Refuses to touch anything unless the target is an explicit test database. */
function requireTestingDatabase(): void
{
    $databaseName = (string) \App\Helpers\Config::get('database', 'database', '');
    $isTestEnv = defined('APP_ENV') && APP_ENV === 'testing';
    $isTestDatabase = str_ends_with($databaseName, '_test');

    if (!$isTestEnv && !$isTestDatabase) {
        fwrite(STDERR, "refusing: database '{$databaseName}' is not an explicit test target (set APP_ENV=testing or a *_test database name)\n");
        exit(2);
    }
}

function databaseAvailable(): bool
{
    try {
        \App\Database::run('SELECT 1');
        return true;
    } catch (\Throwable) {
        \App\Database::reset();
        return false;
    }
}

/** Removes all canonical rows (children first) — test databases only. */
function clearCanonicalTables(): void
{
    requireTestingDatabase();
    \App\Database::run('DELETE FROM quran_divisions');
    // Self-FK on quran_division_types: children (rub → hizb → juz) first.
    \App\Database::run("DELETE FROM quran_division_types WHERE type_key = 'rub'");
    \App\Database::run("DELETE FROM quran_division_types WHERE type_key = 'hizb'");
    \App\Database::run("DELETE FROM quran_division_types WHERE type_key = 'juz'");
    \App\Database::run('DELETE FROM quran_page_ayahs');
    \App\Database::run('DELETE FROM quran_pages');
    \App\Database::run('DELETE FROM quran_ayahs');
    \App\Database::run('DELETE FROM quran_surahs');
    \App\Database::run('DELETE FROM quran_dataset_meta');
}

/**
 * dataset_version as recorded in another database's quran_dataset_meta,
 * or null when that database/table is unreachable. Used by the canonical
 * tests to decide between running and SKIPPED (prompt 24A §9).
 */
function canonicalDatasetVersion(string $database): ?string
{
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
        return null;
    }
    try {
        $version = \App\Database::scalar(
            "SELECT meta_value FROM `{$database}`.quran_dataset_meta WHERE meta_key = 'dataset_version'"
        );
        return $version === null || $version === '' ? null : (string) $version;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Copies the canonical quran_* tables from $database into the connected
 * test database (cross-database INSERT SELECT, FK-safe order, explicit
 * column lists from QURAN_IMPORT_COLUMNS). Test databases only - never
 * writes to the canonical database itself.
 *
 * Requires tools/quran-data/lib.php to be loaded first.
 */
function loadCanonicalFixture(string $database): void
{
    requireTestingDatabase();
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
        throw new InvalidArgumentException("invalid canonical database name: {$database}");
    }
    if (!defined('QURAN_IMPORT_COLUMNS')) {
        throw new LogicException('loadCanonicalFixture requires tools/quran-data/lib.php');
    }
    clearCanonicalTables();
    $copy = static function (string $table, string $orderBy = '') use ($database): void {
        $columns = \QURAN_IMPORT_COLUMNS[$table];
        $cols = '`' . implode('`,`', $columns) . '`';
        \App\Database::run(
            "INSERT INTO `{$table}` ({$cols}) SELECT {$cols} FROM `{$database}`.`{$table}`"
            . ($orderBy !== '' ? " ORDER BY {$orderBy}" : '')
        );
    };
    // FK-safe order; division_types self-FK needs juz (parent) inserted first.
    $copy('quran_surahs');
    $copy('quran_ayahs');
    $copy('quran_pages');
    $copy('quran_page_ayahs');
    $copy('quran_division_types', "FIELD(type_key, 'juz', 'hizb', 'rub')");
    $copy('quran_divisions');
    $copy('quran_dataset_meta');
}

/**
 * Derives rows from the synthetic page map, verifies the fixture against
 * the same structural invariants the real importer upholds (data-architecture
 * §5), then inserts everything in FK-safe order.
 */
function loadSyntheticFixture(): void
{
    requireTestingDatabase();

    /** @var array<string, mixed> $fixture */
    $fixture = require BASE_PATH . '/tests/fixtures/synthetic_structure.php';

    $errors = [];
    $ayahs = [];          // 's:a' => ['surah','ayah','index']
    $ayahFirstPage = [];  // 's:a' => first page it appears on
    $ayahPageLists = [];  // 's:a' => unique pages in order
    $pageStarts = [];     // page => 's:a'
    $pageEnds = [];       // page => 's:a'
    $segments = [];       // derived segment rows (flags filled in below)
    $surahSpans = [];     // surah => ['count','min','max']

    $ordinal = 0;
    $segmentIndex = 0;
    $expectedPage = 1;

    foreach ($fixture['page_segments'] as $pageKey => $keys) {
        $page = (int) $pageKey;
        if ($page !== $expectedPage) {
            $errors[] = "page numbering must be contiguous from 1 (saw {$page}, expected {$expectedPage})";
        }
        $expectedPage = $page + 1;

        $order = 0;
        foreach ($keys as $key) {
            $parts = explode(':', (string) $key);
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                $errors[] = "malformed location '{$key}' on page {$page}";
                continue;
            }
            $surah = (int) $parts[0];
            $ayah = (int) $parts[1];
            $order++;
            $segmentIndex++;

            if (!isset($ayahs[$key])) {
                $ordinal++;
                $ayahs[$key] = ['surah' => $surah, 'ayah' => $ayah, 'index' => $ordinal];
                $ayahFirstPage[$key] = $page;
                $ayahPageLists[$key] = [$page];
            } elseif (end($ayahPageLists[$key]) !== $page) {
                $ayahPageLists[$key][] = $page;
            }

            if (!isset($surahSpans[$surah])) {
                $surahSpans[$surah] = ['count' => 0, 'min' => $page, 'max' => $page];
            }
            $surahSpans[$surah]['count']++;
            $surahSpans[$surah]['min'] = min($surahSpans[$surah]['min'], $page);
            $surahSpans[$surah]['max'] = max($surahSpans[$surah]['max'], $page);

            $segments[] = [
                'page_number' => $page,
                'surah_number' => $surah,
                'ayah_number' => $ayah,
                'segment_order' => $order,
                'segment_index' => $segmentIndex,
                'continues_previous' => 0,
                'continues_next' => 0,
            ];
        }

        if ($order === 0) {
            $errors[] = "page {$page} has no segments";
            continue;
        }
        $pageStarts[$page] = $segments[count($segments) - $order]['surah_number'] . ':' . $segments[count($segments) - $order]['ayah_number'];
        $pageEnds[$page] = end($segments)['surah_number'] . ':' . end($segments)['ayah_number'];
    }

    // Continuations: an ayah may repeat only when it straddles a page break.
    foreach ($ayahPageLists as $key => $pages) {
        if (count($pages) > 2) {
            $errors[] = "ayah {$key} appears on " . count($pages) . ' pages (max 2)';
        } elseif (count($pages) === 2) {
            if ($pages[1] !== $pages[0] + 1) {
                $errors[] = "ayah {$key} repeats on non-adjacent pages " . $pages[0] . '/' . $pages[1];
            }
            if ($pageEnds[$pages[0]] !== $key || $pageStarts[$pages[1]] !== $key) {
                $errors[] = "continuation of {$key} must straddle the page break exactly";
            }
        }
    }

    // Ordinal density + surah-monotonic order.
    $ordered = $ayahs;
    uasort($ordered, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
    $position = 0;
    $lastSurah = 0;
    foreach ($ordered as $row) {
        $position++;
        if ($row['index'] !== $position) {
            $errors[] = 'ayah_index must be dense from 1 (gap at ' . $position . ')';
            break;
        }
        if ($row['surah'] < $lastSurah) {
            $errors[] = 'ayah_index order must follow surah order';
            break;
        }
        $lastSurah = $row['surah'];
    }

    // Distinct ayah counts per surah (segments may repeat a straddling ayah).
    foreach ($ordered as $row) {
        $surahSpans[$row['surah']]['ayah_count'] = ($surahSpans[$row['surah']]['ayah_count'] ?? 0) + 1;
    }

    // Divisions: declared pages must equal the ayahs' first pages; ranges
    // must be contiguous in ordinal space; nesting and counts must hold.
    $divisionTypes = [];
    foreach ($fixture['division_types'] as [$typeKey, $nameArabic, $nameEnglish, $parentType, $perParent, $countExpected]) {
        $divisionTypes[$typeKey] = [
            'name_arabic' => $nameArabic,
            'name_english' => $nameEnglish,
            'parent_type' => $parentType,
            'per_parent' => $perParent,
            'count_expected' => $countExpected,
            'rows' => [],
        ];
    }

    foreach ($fixture['divisions'] as [$type, $number, $parentType, $parentNumber, $startKey, $endKey, $startPage, $endPage]) {
        if (!isset($ayahs[$startKey]) || !isset($ayahs[$endKey])) {
            $errors[] = "division {$type} {$number} points at an unknown location";
            continue;
        }
        if ($ayahFirstPage[$startKey] !== (int) $startPage || $ayahFirstPage[$endKey] !== (int) $endPage) {
            $errors[] = "division {$type} {$number} declared pages disagree with its ayahs' first pages";
        }
        $divisionTypes[$type]['rows'][(int) $number] = [
            'number' => (int) $number,
            'parent_type' => $parentType,
            'parent_number' => $parentNumber,
            'start_key' => $startKey,
            'end_key' => $endKey,
            'start_index' => $ayahs[$startKey]['index'],
            'end_index' => $ayahs[$endKey]['index'],
            'start_page' => (int) $startPage,
            'end_page' => (int) $endPage,
        ];
    }

    foreach ($divisionTypes as $typeKey => $type) {
        $rows = $type['rows'];
        ksort($rows);
        if (count($rows) !== (int) $type['count_expected']) {
            $errors[] = "division type {$typeKey}: expected " . $type['count_expected'] . ' rows, found ' . count($rows);
        }
        if (array_keys($rows) !== range(1, count($rows))) {
            $errors[] = "division type {$typeKey}: numbering must be 1..n per type";
        }
        $previous = null;
        foreach ($rows as $row) {
            if ($previous !== null) {
                $expected = [$previous['end_index'], $previous['end_index'] + 1];
                if (!in_array($row['start_index'], $expected, true)) {
                    $errors[] = "division {$typeKey} {$row['number']} does not follow the previous division in ordinal space";
                }
            }
            $previous = $row;
        }
    }

    foreach ($fixture['divisions'] as [$type, $number, $parentType, $parentNumber, $startKey, $endKey]) {
        if ($parentType === null) {
            continue;
        }
        if (!isset($divisionTypes[$parentType]['rows'][(int) $parentNumber])) {
            $errors[] = "division {$type} {$number} has an unknown parent {$parentType} {$parentNumber}";
            continue;
        }
        $parent = $divisionTypes[$parentType]['rows'][(int) $parentNumber];
        $child = $divisionTypes[$type]['rows'][(int) $number] ?? null;
        if ($child === null) {
            continue;
        }
        if ($child['start_index'] < $parent['start_index'] || $child['end_index'] > $parent['end_index']) {
            $errors[] = "division {$type} {$number} escapes parent {$parentType} {$parentNumber} in ordinal space";
        }
        if ($child['start_page'] < $parent['start_page'] || $child['end_page'] > $parent['end_page']) {
            $errors[] = "division {$type} {$number} escapes parent {$parentType} {$parentNumber} in pages";
        }
    }

    foreach ($divisionTypes as $typeKey => $type) {
        if ($type['parent_type'] === null || $type['per_parent'] === null) {
            continue;
        }
        $perParent = [];
        foreach ($type['rows'] as $row) {
            $perParent[$row['parent_number']] = ($perParent[$row['parent_number']] ?? 0) + 1;
        }
        foreach ($perParent as $parentNumber => $count) {
            if ($count !== (int) $type['per_parent']) {
                $errors[] = "division type {$typeKey}: parent {$parentNumber} has {$count} children, expected {$type['per_parent']}";
            }
        }
    }

    if ($errors !== []) {
        check('fixture integrity', false, implode(' | ', $errors));
        exit(1);
    }

    \App\Database::transaction(static function () use ($fixture, $ayahs, $ordered, $segments, $surahSpans, $divisionTypes, $pageStarts, $pageEnds): void {
        foreach ($fixture['surahs'] as $number => $meta) {
            if (!isset($surahSpans[$number])) {
                throw new \RuntimeException("declared surah {$number} has no ayahs in the page map");
            }
            \App\Database::run(
                'INSERT INTO quran_surahs (surah_number, name_arabic, name_transliteration, name_english, revelation_type, ayah_count, start_page, end_page)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $number,
                    $meta['name_arabic'],
                    $meta['name_transliteration'],
                    $meta['name_english'],
                    $meta['revelation_type'],
                    $surahSpans[$number]['ayah_count'],
                    $surahSpans[$number]['min'],
                    $surahSpans[$number]['max'],
                ]
            );
        }

        foreach ($ordered as $row) {
            \App\Database::run(
                'INSERT INTO quran_ayahs (surah_number, ayah_number, ayah_index) VALUES (?, ?, ?)',
                [$row['surah'], $row['ayah'], $row['index']]
            );
        }

        foreach ($fixture['page_segments'] as $pageKey => $keys) {
            $page = (int) $pageKey;
            [$startSurah, $startAyah] = array_map(intval(...), explode(':', $pageStarts[$page]));
            [$endSurah, $endAyah] = array_map(intval(...), explode(':', $pageEnds[$page]));
            \App\Database::run(
                'INSERT INTO quran_pages (page_number, start_surah, start_ayah, end_surah, end_ayah) VALUES (?, ?, ?, ?, ?)',
                [$page, $startSurah, $startAyah, $endSurah, $endAyah]
            );
        }

        foreach ($segments as $segment) {
            \App\Database::run(
                'INSERT INTO quran_page_ayahs (page_number, surah_number, ayah_number, segment_order, segment_index, continues_previous, continues_next)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $segment['page_number'],
                    $segment['surah_number'],
                    $segment['ayah_number'],
                    $segment['segment_order'],
                    $segment['segment_index'],
                    $segment['continues_previous'],
                    $segment['continues_next'],
                ]
            );
        }

        foreach ($fixture['division_types'] as [$typeKey, $nameArabic, $nameEnglish, $parentType, $perParent, $countExpected]) {
            \App\Database::run(
                'INSERT INTO quran_division_types (type_key, name_arabic, name_english, parent_type_key, per_parent, count_expected)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$typeKey, $nameArabic, $nameEnglish, $parentType, $perParent, $countExpected]
            );
        }

        foreach ($fixture['divisions'] as [$type, $number, $parentType, $parentNumber, $startKey, $endKey, $startPage, $endPage]) {
            [$startSurah, $startAyah] = array_map(intval(...), explode(':', $startKey));
            [$endSurah, $endAyah] = array_map(intval(...), explode(':', $endKey));
            \App\Database::run(
                'INSERT INTO quran_divisions (division_type, division_number, parent_type, parent_number, start_surah, start_ayah, end_surah, end_ayah, start_page, end_page)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$type, (int) $number, $parentType, $parentNumber, $startSurah, $startAyah, $endSurah, $endAyah, (int) $startPage, (int) $endPage]
            );
        }

        foreach ($fixture['meta'] as $key => $value) {
            \App\Database::run(
                'INSERT INTO quran_dataset_meta (meta_key, meta_value) VALUES (?, ?)',
                [(string) $key, (string) $value]
            );
        }
    });

    // Continuation flags (straddling ayah): set after rows are known to be consistent.
    $segmentCount = count($segments);
    for ($i = 1; $i < $segmentCount; $i++) {
        $current = $segments[$i];
        $previous = $segments[$i - 1];
        if ($current['page_number'] === $previous['page_number']) {
            continue;
        }
        if ($current['surah_number'] === $previous['surah_number'] && $current['ayah_number'] === $previous['ayah_number']) {
            $segments[$i - 1]['continues_next'] = 1;
            $segments[$i]['continues_previous'] = 1;
            \App\Database::run(
                'UPDATE quran_page_ayahs SET continues_next = 1 WHERE page_number = ? AND surah_number = ? AND ayah_number = ?',
                [$previous['page_number'], $previous['surah_number'], $previous['ayah_number']]
            );
            \App\Database::run(
                'UPDATE quran_page_ayahs SET continues_previous = 1 WHERE page_number = ? AND surah_number = ? AND ayah_number = ?',
                [$current['page_number'], $current['surah_number'], $current['ayah_number']]
            );
        }
    }

    check('fixture integrity + import', true);
}
