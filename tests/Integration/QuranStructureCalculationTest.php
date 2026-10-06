<?php

declare(strict_types=1);

/**
 * Integration tests: Quran structure calculations against the canonical
 * tables, loaded from the clearly-synthetic test fixture.
 * Run: php tests/Integration/QuranStructureCalculationTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Exceptions\AppException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\DivisionType;
use App\Services\QuranStructureService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();

try {
    $empty = new QuranStructureService();
    checkThrows(
        'fail-closed: empty dataset rejected',
        fn () => $empty->pageBounds(),
        AppException::class
    );

    loadSyntheticFixture();

    $structure = new QuranStructureService();

    // --- dataset provenance -------------------------------------------------
    $info = $structure->datasetInfo();
    checkEquals('dataset: version tag', 'synthetic-fixture-1', $info['dataset_version']);
    checkEquals('dataset: page count', 16, $info['page_count']);
    checkEquals('dataset: surah count', 4, $info['surah_count']);
    checkEquals('dataset: ayah count', 32, $info['ayah_count']);
    checkEquals('dataset: juz count', 2, $info['division_counts']['juz']);
    checkEquals('dataset: hizb count', 4, $info['division_counts']['hizb']);
    checkEquals('dataset: rub count', 8, $info['division_counts']['rub']);

    // --- page bounds --------------------------------------------------------
    $bounds = $structure->pageBounds();
    checkEquals('bounds: min', 1, $bounds['min_page']);
    checkEquals('bounds: max', 16, $bounds['max_page']);
    checkEquals('bounds: count', 16, $bounds['page_count']);

    // --- exact locations ----------------------------------------------------
    checkEquals('1:1 starts page 1', 1, $structure->locationToPage(1, 1));
    checkEquals('3:5 starts page 11', 11, $structure->locationToPage(3, 5));
    checkEquals('3:8 starts page 12 (spans to 13)', 12, $structure->locationToPage(3, 8));
    checkEquals('4:1 starts page 13', 13, $structure->locationToPage(4, 1));
    checkEquals('4:8 starts page 16', 16, $structure->locationToPage(4, 8));
    checkThrows('unknown ayah → 404', fn () => $structure->locationToPage(9, 1), NotFoundException::class);
    checkThrows('unknown surah → 404', fn () => $structure->locationToPage(5, 1), NotFoundException::class);

    $info41 = $structure->locationInfo(4, 1);
    checkEquals('4:1 ordinal', 25, $info41['ayah_index']);
    checkEquals('4:1 page', 13, $info41['page_number']);
    checkEquals('4:1 surah name', 'Test Delta', $info41['name_english']);

    $ordinal = $structure->ordinalToLocation(24);
    checkEquals('ordinal 24 → surah', 3, $ordinal['surah_number']);
    checkEquals('ordinal 24 → ayah', 8, $ordinal['ayah_number']);
    checkEquals('ordinal 24 start page', 12, $ordinal['page_number']);
    checkThrows('unknown ordinal → 404', fn () => $structure->ordinalToLocation(999), NotFoundException::class);

    // --- pages, segments, ranges -------------------------------------------
    $page12 = $structure->pageDetails(12);
    checkEquals('page 12 starts 3:7', [3, 7], [(int) $page12['start_surah'], (int) $page12['start_ayah']]);
    checkEquals('page 12 ends 3:8', [3, 8], [(int) $page12['end_surah'], (int) $page12['end_ayah']]);

    $segments12 = $structure->pageAyahs(12);
    checkEquals('page 12 segment count', 2, count($segments12));
    checkEquals('page 12 last continues next', 1, (int) $segments12[1]['continues_next']);

    $segments13 = $structure->pageAyahs(13);
    checkEquals('page 13 segment count', 3, count($segments13));
    checkEquals('page 13 first is continuation of 3:8', [3, 8], [(int) $segments13[0]['surah_number'], (int) $segments13[0]['ayah_number']]);
    checkEquals('page 13 first continues previous', 1, (int) $segments13[0]['continues_previous']);
    checkEquals('page 13 third is 4:2', [4, 2], [(int) $segments13[2]['surah_number'], (int) $segments13[2]['ayah_number']]);
    checkEquals('page 13 segment orders', [1, 2, 3], array_column($segments13, 'segment_order'));

    checkThrows('page 0 → 422', fn () => $structure->pageDetails(0), ValidationException::class, 'page');
    checkThrows('page 17 → 422', fn () => $structure->pageDetails(17), ValidationException::class, 'page');
    checkThrows('reversed range → 422', fn () => $structure->pageRange(5, 3), ValidationException::class, 'range');
    checkThrows('range past max → 422', fn () => $structure->pageRange(1, 99), ValidationException::class, 'to_page');

    $range = $structure->pageRange(3, 5);
    checkEquals('range 3-5 page count', 3, $range['page_count']);
    checkEquals('range 3-5 surahs', [1, 2], array_column($range['surahs'], 'surah_number'));
    checkEquals('surah 1 span clamped into range', [3, 4], [$range['surahs'][0]['first_page'], $range['surahs'][0]['last_page']]);
    checkEquals('surah 2 span clamped into range', [5, 5], [$range['surahs'][1]['first_page'], $range['surahs'][1]['last_page']]);

    $surah3 = $structure->surahDetails(3);
    checkEquals('surah 3 ayah count', 8, (int) $surah3['ayah_count']);
    checkEquals('surah 3 start page', 9, (int) $surah3['start_page']);
    checkEquals('surah 3 end page (includes continuation)', 13, (int) $surah3['end_page']);
    checkThrows('unknown surah details → 404', fn () => $structure->surahDetails(7), NotFoundException::class);

    // --- divisions ----------------------------------------------------------
    $juz1 = $structure->divisionDetails(DivisionType::Juz, 1);
    checkEquals('juz 1 pages', [1, 8], [(int) $juz1['start_page'], (int) $juz1['end_page']]);
    checkEquals('juz 1 ends 2:8', [2, 8], [(int) $juz1['end_surah'], (int) $juz1['end_ayah']]);

    $juz2 = $structure->divisionDetails(DivisionType::Juz, 2);
    checkEquals('juz 2 starts on shared page 8', 8, (int) $juz2['start_page']);
    checkEquals('juz 2 ends page 16', 16, (int) $juz2['end_page']);

    checkEquals(
        'rub overlap [3,5]',
        [2, 3],
        array_column($structure->divisionsInRange(DivisionType::Rub, 3, 5), 'division_number')
    );
    checkEquals(
        'page 8 sits in both juz',
        [1, 2],
        array_column($structure->divisionsInRange(DivisionType::Juz, 8, 8), 'division_number')
    );
    checkEquals(
        'page 11 sits in both rubs',
        [5, 6],
        array_column($structure->divisionsInRange(DivisionType::Rub, 11, 11), 'division_number')
    );
    checkEquals(
        'hizb overlap [1,4] only hizb 1',
        [1],
        array_column($structure->divisionsInRange(DivisionType::Hizb, 1, 4), 'division_number')
    );
    checkEquals(
        'rub overlap [1,16] = all rubs',
        [1, 2, 3, 4, 5, 6, 7, 8],
        array_column($structure->divisionsInRange(DivisionType::Rub, 1, 16), 'division_number')
    );

    checkEquals('page 7 → juz 1', 1, $structure->divisionAtPage(DivisionType::Juz, 7)['division_number']);
    checkEquals('page 8 → juz 2 (latest start wins)', 2, $structure->divisionAtPage(DivisionType::Juz, 8)['division_number']);
    checkEquals('page 9 → juz 2', 2, $structure->divisionAtPage(DivisionType::Juz, 9)['division_number']);
    checkEquals('page 10 → rub 5', 5, $structure->divisionAtPage(DivisionType::Rub, 10)['division_number']);
    checkEquals('page 11 → rub 6 (latest start wins)', 6, $structure->divisionAtPage(DivisionType::Rub, 11)['division_number']);
    checkEquals('page 13 → hizb 4', 4, $structure->divisionAtPage(DivisionType::Hizb, 13)['division_number']);
    checkThrows('unknown division → 404', fn () => $structure->divisionDetails(DivisionType::Juz, 99), NotFoundException::class);
    checkThrows('page outside dataset in divisionAtPage → 422', fn () => $structure->divisionAtPage(DivisionType::Juz, 40), ValidationException::class, 'page');

    // --- DivisionLocator seam (real implementation) -------------------------
    checkEquals('locator: juz at page 9', 2, $structure->divisionNumberAtPage(DivisionType::Juz, 9));
    checkEquals('locator: hizb 2 end page', 8, $structure->divisionEndPage(DivisionType::Hizb, 2));
    checkEquals('locator: missing division end', null, $structure->divisionEndPage(DivisionType::Hizb, 99));
} finally {
    clearCanonicalTables();
}

exit(summary('QuranStructureCalculation'));
