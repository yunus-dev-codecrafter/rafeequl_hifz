<?php

declare(strict_types=1);

/**
 * Pipeline stage 6 - AUDIT (prompt 24A §8, data-architecture §7.6).
 *
 * Reads the *imported* canonical dataset straight from quran_* tables and
 * re-runs:
 *   - every §5/§6 structural invariant (same checks as verify, but on DB rows)
 *   - §8.7 provenance (meta keys, PASS report link, checksum chain
 *     DB meta <-> verification report <-> processed files on disk)
 *
 * Run it after import.php --execute, and after any manual DB change.
 * Exit code 0 only when all checks pass. Writes nothing.
 *
 * Usage: php tools/quran-data/audit.php [--database=<name>]
 */

require __DIR__ . '/lib.php';

quranRunCli(function (): void {
    $database = null;
    foreach (quranCliArgs() as $arg) {
        if (str_starts_with($arg, '--database=')) {
            $database = substr($arg, strlen('--database='));
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "usage: audit.php [--database=<name>]\n";
            exit(0);
        } else {
            quranFail("unknown argument {$arg} (usage: audit.php [--database=<name>])");
        }
    }
    if ($database !== null) {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            quranFail('invalid --database name');
        }
        putenv('DB_DATABASE=' . $database);
        $_ENV['DB_DATABASE'] = $database;
    }

    $config = require CONFIG_PATH . '/database.php';
    if ($config['database'] === '') {
        quranFail('DB_DATABASE is not set (export DB_* or pass --database=<name>)');
    }

    $dataset = quranLoadDatasetFromDatabase();
    $counts = quranCountsSummary($dataset);
    $version = quranCanonicalDatasetVersion();
    echo 'target:  ' . $config['database'] . ' @' . $config['host'] . ':' . $config['port'] . "\n";
    echo 'dataset: ' . ($version ?? '(none - not a canonical import)') . "\n";
    echo 'rows:    ' . quranFormatCounts($counts) . "\n\n";

    if (!quranIsCanonicalDataset($version)) {
        quranFail('dataset_version is not canonical (import a verified dataset first)');
    }

    $checks = array_merge(
        quranStructuralChecks($dataset),
        quranProvenanceChecks($dataset['meta']),
    );
    $failed = array_values(array_filter($checks, static fn (array $c): bool => !$c['passed']));

    echo quranFormatChecks($checks) . "\n\n";
    echo sprintf(
        "summary: %d checks, %d passed, %d failed -> verdict %s\n",
        count($checks),
        count($checks) - count($failed),
        count($failed),
        $failed === [] ? 'PASS' : 'FAIL'
    );

    if ($failed !== []) {
        foreach ($failed as $check) {
            echo 'FAILED: ' . $check['name'] . ' - ' . $check['detail'] . "\n";
        }
        quranFail('audit FAILED (' . count($failed) . ' check(s))');
    }
});
