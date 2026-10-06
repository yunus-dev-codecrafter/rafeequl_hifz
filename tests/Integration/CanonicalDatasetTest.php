<?php

declare(strict_types=1);

/**
 * Canonical dataset tests (prompt 24A §6/§8): the imported Madinah dataset
 * is loaded into this test database via cross-database copy and must pass
 * the full structural + provenance suites - the same checks verify.php and
 * audit.php run against the source pipeline, now proving the DB copy.
 *
 * SKIPPED (exit 0) when the canonical database is unreachable or does not
 * hold a canonical dataset; the regular synthetic suite stays green without
 * a canonical import. Canonical DB: env QURAN_CANONICAL_DB (default
 * "rafeequl_hifz").
 *
 * Run: php tests/Integration/CanonicalDatasetTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/tools/quran-data/lib.php';

use App\Database;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

$canonicalDb = getenv('QURAN_CANONICAL_DB');
$canonicalDb = ($canonicalDb === false || $canonicalDb === '') ? 'rafeequl_hifz' : $canonicalDb;
$version = canonicalDatasetVersion($canonicalDb);
if ($version === null) {
    echo "SKIPPED: canonical dataset not reachable in '{$canonicalDb}' (run tools/quran-data/import.php --execute)\n";
    exit(0);
}
if (!str_starts_with($version, 'tanzil-quran-metadata-')) {
    echo "SKIPPED: '{$canonicalDb}' holds non-canonical dataset {$version}\n";
    exit(0);
}

requireTestingDatabase();
$fixture = require dirname(__DIR__) . '/fixtures/synthetic_structure.php';

loadCanonicalFixture($canonicalDb);

try {
    // --- version + recorded totals ------------------------------------------
    checkEquals('dataset_version copied', $version, (string) Database::scalar(
        "SELECT meta_value FROM quran_dataset_meta WHERE meta_key = 'dataset_version'"
    ));
    check('verification_verdict recorded PASS', (string) Database::scalar(
        "SELECT meta_value FROM quran_dataset_meta WHERE meta_key = 'verification_verdict'"
    ) === 'PASS');
    check('spot_check_status recorded', (string) Database::scalar(
        "SELECT meta_value FROM quran_dataset_meta WHERE meta_key = 'spot_check_status'"
    ) === 'pending');

    // --- invariant totals against actual rows (prompt §6) -------------------
    checkEquals('surahs', 114, (int) Database::scalar('SELECT COUNT(*) FROM quran_surahs'));
    checkEquals('ayahs', 6236, (int) Database::scalar('SELECT COUNT(*) FROM quran_ayahs'));
    checkEquals('pages', 604, (int) Database::scalar('SELECT COUNT(*) FROM quran_pages'));
    checkEquals('page_ayahs segments', 6236, (int) Database::scalar('SELECT COUNT(*) FROM quran_page_ayahs'));
    $divisionCounts = [];
    foreach (Database::fetchAll('SELECT division_type, COUNT(*) AS n FROM quran_divisions GROUP BY division_type') as $row) {
        $divisionCounts[(string) $row['division_type']] = (int) $row['n'];
    }
    ksort($divisionCounts);
    checkEquals('divisions per type', ['hizb' => 60, 'juz' => 30, 'rub' => 240], $divisionCounts);

    // --- division type vocabulary + hierarchy match the spec fixture --------
    // (labels/parents are spec vocabulary; counts differ because the fixture
    // is a 16-page miniature: 2/4/8 vs the canonical 30/60/240)
    $expectedLabels = [];
    foreach ($fixture['division_types'] as [$typeKey, $nameArabic, $nameEnglish, $parentType]) {
        $expectedLabels[$typeKey] = [$nameArabic, $nameEnglish, $parentType];
    }
    $actualTypes = [];
    foreach (Database::fetchAll('SELECT * FROM quran_division_types') as $row) {
        $actualTypes[(string) $row['type_key']] = [
            (string) $row['name_arabic'],
            (string) $row['name_english'],
            $row['parent_type_key'] === null ? null : (string) $row['parent_type_key'],
            $row['per_parent'] === null ? null : (int) $row['per_parent'],
            $row['count_expected'] === null ? null : (int) $row['count_expected'],
        ];
    }
    $actualLabels = [];
    foreach ($actualTypes as $typeKey => $tuple) {
        $actualLabels[$typeKey] = array_slice($tuple, 0, 3);
    }
    ksort($expectedLabels);
    ksort($actualLabels);
    checkEquals('division labels + parent chain match spec', $expectedLabels, $actualLabels);
    // canonical arithmetic (prompt §6 invariants): 30 juz, 2 hizb/juz,
    // 60 hizb, 4 rub/hizb, 240 rub
    checkEquals('juz type row', [null, null, 30], array_slice($actualTypes['juz'], 2));
    checkEquals('hizb type row', ['juz', 2, 60], array_slice($actualTypes['hizb'], 2));
    checkEquals('rub type row', ['hizb', 4, 240], array_slice($actualTypes['rub'], 2));

    // --- full §5/§6 structural suite on the DB copy --------------------------
    $dataset = quranLoadDatasetFromDatabase();
    foreach (quranStructuralChecks($dataset) as $structural) {
        check($structural['name'], $structural['passed'], $structural['detail']);
    }

    // --- §8.7 provenance chain (DB meta <-> report <-> processed files) ------
    foreach (quranProvenanceChecks($dataset['meta']) as $provenance) {
        check($provenance['name'], $provenance['passed'], $provenance['detail']);
    }
} finally {
    clearCanonicalTables();
    loadSyntheticFixture();
}

exit(summary('CanonicalDataset'));
