<?php

declare(strict_types=1);

/**
 * Pipeline stage 3 - NORMALIZE (data-architecture §7.3).
 *
 * Derives the processed application-ready dataset (processed/*.json)
 * programmatically from the verified source files. Output is
 * deterministic: identical sources always produce byte-identical files
 * (verify.php re-derives and byte-compares as a round-trip check).
 *
 * Usage: php tools/quran-data/normalize.php
 */

require __DIR__ . '/lib.php';

quranRunCli(function (): void {
    $unknown = quranCliArgs();
    if ($unknown !== []) {
        quranFail('unexpected argument(s): ' . implode(' ', $unknown) . ' (usage: normalize.php)');
    }
    quranEnsureDirs();

    $paths = [
        'quran-data.xml' => quranSourceDir() . '/quran-data.xml',
        'alquran-quran-uthmani.json' => quranSourceDir() . '/alquran-quran-uthmani.json',
        'alquran-meta.json' => quranSourceDir() . '/alquran-meta.json',
    ];
    $checksums = [];
    foreach ($paths as $name => $path) {
        $checksums[$name] = quranVerifySidecar($path);
        echo "ok      {$name} checksum verified\n";
    }

    $dataset = quranDeriveDataset(
        $paths['quran-data.xml'],
        $paths['alquran-quran-uthmani.json'],
        $paths['alquran-meta.json'],
        $checksums
    );
    echo "derived in-memory: no violations\n";

    $written = quranWriteProcessed($dataset);

    $rows = [
        'surahs' => count($dataset['surahs']),
        'ayahs' => count($dataset['ayahs']),
        'pages' => count($dataset['pages']),
        'page_ayahs' => count($dataset['page_ayahs']),
        'divisions' => count($dataset['divisions']),
        'division_types' => count($dataset['division_types']),
        'meta' => count($dataset['meta']),
    ];
    foreach ($rows as $section => $count) {
        echo sprintf("written %-15s %6d rows  sha256:%s...\n", $section . '.json', $count, substr($written[$section . '.json'], 0, 16));
    }
    echo 'dataset_version=' . $dataset['meta']['dataset_version'] . "\n";
    echo "next: php tools/quran-data/verify.php\n";
});
