<?php

declare(strict_types=1);

/**
 * Integration tests: GET /quran/pages/{page_number}/ayahs — the canonical
 * ayah picker behind the "Flag Mistake" form. Locks the exact JSON shape the
 * frontend reads (page_number / surah_number / ayah_number / ayah_index),
 * the reading order it renders, and the two 422 paths (bad route id vs. page
 * outside the dataset). No users are created, so the shared fixture is left
 * as the next suite expects it.
 * Run: php tests/Integration/QuranPageAyahsEndpointTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Controllers\QuranController;
use App\Exceptions\ValidationException;
use App\Request;
use App\Services\QuranStructureService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$controller = new QuranController(Request::fromGlobals());
$structure = new QuranStructureService();

// === happy path: 200 with the documented shape ==========================
$response = $controller->pageAyahs(['page_number' => '12']);
checkEquals('endpoint: 200', 200, $response->status());
checkEquals(
    'endpoint: json content type',
    'application/json; charset=utf-8',
    (string) $response->header('Content-Type')
);

$payload = json_decode($response->body(), true);
check('endpoint: body decodes to an object', is_array($payload));

$data = $payload['data'] ?? [];
checkEquals('endpoint: echoes the page', 12, $data['page_number'] ?? null);
check('endpoint: carries an ayahs list', is_array($data['ayahs'] ?? null) && $data['ayahs'] !== []);

// Exactly the fields the flag form reads — no renames, no nesting.
// Only ayahs that START on the page (continues_previous = 0) are returned.
// The controller filters continuation segments so the flag picker only shows
// ayahs whose canonical page matches — FlipCardService would otherwise reject
// them with 422 "This ayah does not appear on that page".
$expected = array_values(array_map(
    static fn (array $row): array => [
        'surah_number' => (int) $row['surah_number'],
        'ayah_number' => (int) $row['ayah_number'],
        'ayah_index' => (int) $row['ayah_index'],
    ],
    array_filter(
        $structure->pageAyahs(12),
        static fn (array $row): bool => (int) $row['continues_previous'] === 0
    )
));
checkEquals('endpoint: ayahs match the structure service (no continuations)', $expected, $data['ayahs']);

$first = $data['ayahs'][0] ?? [];
check('endpoint: surah_number is an int', is_int($first['surah_number'] ?? null));
check('endpoint: ayah_number is an int', is_int($first['ayah_number'] ?? null));
check('endpoint: ayah_index is an int', is_int($first['ayah_index'] ?? null));

$indexes = array_column($data['ayahs'], 'ayah_index');
$sorted = $indexes;
sort($sorted);
checkEquals('endpoint: reading order ascending', $sorted, $indexes);

// === page 13: ayah 3:8 continues from page 12 and must be excluded ======
// If the filter is missing, 3:8 appears in the picker and the user's flag
// call fails with 422 because its canonical page is 12, not 13.
$response13 = $controller->pageAyahs(['page_number' => '13']);
$payload13 = json_decode($response13->body(), true);
$data13 = $payload13['data'] ?? [];
$ayahs13 = $data13['ayahs'] ?? [];

check('page 13: returns ayahs', $ayahs13 !== []);

// 3:8 must not appear in page 13's picker (its canonical start page is 12).
$has3_8 = false;
foreach ($ayahs13 as $ayah) {
    if ((int) $ayah['surah_number'] === 3 && (int) $ayah['ayah_number'] === 8) {
        $has3_8 = true;
        break;
    }
}
check('page 13: ayah 3:8 (continues_previous) excluded from picker', !$has3_8);

// 4:1 and 4:2 start on page 13 and must be present.
$surahAyahPairs13 = array_map(
    static fn (array $a): string => $a['surah_number'] . ':' . $a['ayah_number'],
    $ayahs13
);
check('page 13: 4:1 starts on this page and is included', in_array('4:1', $surahAyahPairs13, true));
check('page 13: 4:2 starts on this page and is included', in_array('4:2', $surahAyahPairs13, true));

// === route id validation (422, field page_number) =======================
checkThrows(
    'non-numeric page → 422',
    fn () => $controller->pageAyahs(['page_number' => 'abc']),
    ValidationException::class,
    'page_number'
);
checkThrows(
    'zero page → 422',
    fn () => $controller->pageAyahs(['page_number' => '0']),
    ValidationException::class,
    'page_number'
);
checkThrows(
    'missing page → 422',
    fn () => $controller->pageAyahs([]),
    ValidationException::class,
    'page_number'
);

// === dataset bounds (422, field page) ===================================
checkThrows(
    'page past the dataset → 422',
    fn () => $controller->pageAyahs(['page_number' => '999']),
    ValidationException::class,
    'page'
);

exit(summary('Quran page ayahs endpoint'));
