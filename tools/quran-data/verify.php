<?php

declare(strict_types=1);

/**
 * Pipeline stage 4 - VERIFY (prompt 24A §6, data-architecture §7.4/§8).
 *
 * Runs against the *source* + *processed* files before any import:
 *   1. source checksums vs .sha256 sidecars (mismatch aborts)
 *   2. round-trip: re-derive from source, byte-compare processed/*.json
 *   3. all §5 structural invariants (also prompt §6 boundary conditions)
 *   4. cross-source diff against the independent alquran.cloud data set
 *   5. deterministic human spot-check list (§8.5) for maintainer sign-off
 *
 * Writes verification/report-<UTC>.json with per-check pass/fail (written
 * on failure too, as evidence). Exit code 0 only when every check passes.
 *
 * Usage: php tools/quran-data/verify.php
 */

require __DIR__ . '/lib.php';

quranRunCli(function (): void {
    $unknown = quranCliArgs();
    if ($unknown !== []) {
        quranFail('unexpected argument(s): ' . implode(' ', $unknown) . ' (usage: verify.php)');
    }
    quranEnsureDirs();

    $paths = [
        'quran-data.xml' => quranSourceDir() . '/quran-data.xml',
        'alquran-quran-uthmani.json' => quranSourceDir() . '/alquran-quran-uthmani.json',
        'alquran-meta.json' => quranSourceDir() . '/alquran-meta.json',
    ];

    // 1) source integrity ----------------------------------------------------
    $sourceEntries = [];
    foreach (QURAN_SOURCES as $key => $source) {
        $path = $paths[$source['file']];
        $sha = quranVerifySidecar($path);
        $sourceEntries[] = [
            'key' => $key,
            'role' => $source['role'],
            'url' => $source['url'],
            'file' => $source['file'],
            'version' => '',
            'sha256' => $sha,
            'bytes' => (int) filesize($path),
        ];
        echo "ok      checksum {$source['file']} " . substr($sha, 0, 16) . "...\n";
    }

    // 2) re-derive from source (throws on any violation) ---------------------
    $dataset = quranDeriveDataset(
        $paths['quran-data.xml'],
        $paths['alquran-quran-uthmani.json'],
        $paths['alquran-meta.json'],
        array_column($sourceEntries, 'sha256', 'file')
    );
    echo "derived in-memory from source: no derivation violations\n";

    $tanzil = quranParseTanzil($paths['quran-data.xml']);
    $sourceEntries[0]['version'] = $tanzil['version'];

    // 3) round-trip determinism (§8.4) ---------------------------------------
    $roundTripMismatches = quranRoundTripDiff($dataset);
    $roundTripCheck = quranCheck(
        '§8.4 round-trip determinism: processed files byte-identical to re-derivation',
        $roundTripMismatches === [],
        $roundTripMismatches === [] ? 'all 7 processed files byte-identical' : implode(' | ', $roundTripMismatches)
    );

    // 4) invariants ----------------------------------------------------------
    $structural = quranStructuralChecks($dataset);
    $cross = quranCrossSourceChecks(
        $dataset,
        quranParseAlQuran($paths['alquran-quran-uthmani.json']),
        quranParseAlQuranMeta($paths['alquran-meta.json'])
    );

    $checks = array_merge([$roundTripCheck], $structural, $cross);
    $failed = array_values(array_filter($checks, static fn (array $c): bool => !$c['passed']));
    $verdict = $failed === [] ? 'PASS' : 'FAIL';

    // 5) processed checksums + counts + spot check ---------------------------
    $processedEntries = [];
    foreach (quranProcessedFiles() as $section => $path) {
        $processedEntries[] = [
            'file' => basename($path),
            'sha256' => is_file($path) ? quranSha256File($path) : null,
        ];
    }
    $spot = quranSpotCheckLocations($dataset, (string) $dataset['meta']['dataset_source_sha256']);

    $report = [
        'tool' => 'tools/quran-data/verify.php',
        'tool_version' => QURAN_TOOL_VERSION,
        'generated_at' => gmdate('c'),
        'verdict' => $verdict,
        'sources' => $sourceEntries,
        'processed' => $processedEntries,
        'counts' => quranCountsSummary($dataset),
        'checks' => $checks,
        'checks_summary' => [
            'total' => count($checks),
            'passed' => count($checks) - count($failed),
            'failed' => count($failed),
        ],
        'spot_check' => $spot,
    ];
    $reportPath = quranWriteReport($report);

    echo "\n" . quranFormatChecks($checks) . "\n\n";
    echo sprintf(
        "summary: %d checks, %d passed, %d failed -> verdict %s\n",
        count($checks),
        count($checks) - count($failed),
        count($failed),
        $verdict
    );
    echo 'report:  ' . ltrim(str_replace('\\', '/', str_replace(BASE_PATH, '', $reportPath)), '/') . "\n";
    echo 'spot-check locations: ' . count($spot['locations']) . ' (PENDING maintainer sign-off, data-architecture §8.5)' . "\n";

    if ($verdict !== 'PASS') {
        foreach ($failed as $check) {
            echo 'FAILED: ' . $check['name'] . ' - ' . $check['detail'] . "\n";
        }
        quranFail('verification FAILED (' . count($failed) . ' check(s)); import is blocked');
    }
    echo "next: php tools/quran-data/import.php --dry-run\n";
});
