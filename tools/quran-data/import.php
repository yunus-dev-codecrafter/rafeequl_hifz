<?php

declare(strict_types=1);

/**
 * Pipeline stage 5 - IMPORT (prompt 24A §7/§8, data-architecture §7.5).
 *
 * Gates on the latest verification report (must be PASS and its
 * processed checksums must match the files on disk). Runs in two modes:
 *
 *   php tools/quran-data/import.php              # DRY-RUN (default)
 *   php tools/quran-data/import.php --dry-run    # same
 *   php tools/quran-data/import.php --execute    # perform the import
 *
 * The import is a single transaction with FOREIGN_KEY_CHECKS=0 (FK cycle
 * pages <-> ayahs via segments), deletes only quran_* tables, re-inserts
 * the whole canonical set (idempotent replace - never patches), asserts
 * row counts before commit, and proves user tables kept identical row
 * counts. quran_dataset_meta records provenance (version, checksums,
 * report link, imported_at).
 *
 * Database selection: DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME /
 * DB_PASSWORD environment variables (or --database=<name>).
 */

require __DIR__ . '/lib.php';

function quranBatchInsert(string $table, array $columns, array $rows): int
{
    if ($rows === []) {
        return 0;
    }
    $inserted = 0;
    foreach (array_chunk($rows, 300) as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as $row) {
            $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            foreach ($columns as $column) {
                $params[] = $row[$column] ?? null;
            }
        }
        \App\Database::run(
            'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES ' . implode(',', $placeholders),
            $params
        );
        $inserted += count($chunk);
    }
    return $inserted;
}

/** @return array<string, int> non-quran table => row count */
function quranUserTableCounts(): array
{
    $tables = \App\Database::fetchAll(
        "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
    );
    $counts = [];
    foreach ($tables as $row) {
        $name = (string) $row['t'];
        if (str_starts_with($name, 'quran_')) {
            continue;
        }
        $counts[$name] = (int) \App\Database::scalar('SELECT COUNT(*) FROM `' . $name . '`');
    }
    return $counts;
}

quranRunCli(function (): void {
    $execute = false;
    $database = null;
    foreach (quranCliArgs() as $arg) {
        if ($arg === '--execute') {
            $execute = true;
        } elseif ($arg === '--dry-run') {
            $execute = false;
        } elseif (str_starts_with($arg, '--database=')) {
            $database = substr($arg, strlen('--database='));
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "usage: import.php [--dry-run | --execute] [--database=<name>]\n";
            exit(0);
        } else {
            quranFail("unknown argument {$arg} (usage: import.php [--dry-run|--execute] [--database=<name>])");
        }
    }
    if ($database !== null) {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            quranFail('invalid --database name');
        }
        putenv('DB_DATABASE=' . $database);
        $_ENV['DB_DATABASE'] = $database;
    }

    // --- gate: latest verification report must be PASS + checksums match ----
    $gate = quranRequirePassingReport();
    $reportPath = $gate['path'];
    $report = $gate['report'];
    $counts = $report['counts'] ?? [];
    echo 'report:  ' . basename($reportPath) . ' (PASS, ' . ($report['checks_summary']['passed'] ?? '?') . '/' . ($report['checks_summary']['total'] ?? '?') . " checks)\n";

    // --- target database ----------------------------------------------------
    $config = require CONFIG_PATH . '/database.php';
    if ($config['database'] === '') {
        quranFail('DB_DATABASE is not set (export DB_* or pass --database=<name>)');
    }
    $datasetTables = array_keys(QURAN_IMPORT_ORDER);
    $existing = \App\Database::fetchAll(
        "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()"
    );
    $existingNames = array_map(static fn (array $r): string => (string) $r['t'], $existing);
    $missing = array_diff($datasetTables, $existingNames);
    if ($missing !== []) {
        quranFail('canonical tables missing (run tools/database/apply-migrations.php): ' . implode(', ', $missing));
    }
    echo 'target:  ' . $config['database'] . ' @' . $config['host'] . ':' . $config['port'] . "\n";

    $userBefore = quranUserTableCounts();

    // --- provenance meta (file meta + runtime keys) --------------------------
    $fileMeta = quranReadJson(quranProcessedFiles()['meta']);
    if (!is_array($fileMeta)) {
        quranFail('processed meta.json is malformed');
    }
    $reportSha = quranSha256File($reportPath);
    $processedChecksums = [];
    foreach (quranProcessedFiles() as $path) {
        $processedChecksums[basename($path)] = quranSha256File($path);
    }
    $runtimeMeta = [
        'processed_checksums' => quranEncodeJson($processedChecksums),
        'verification_report' => basename($reportPath),
        'verification_report_sha256' => $reportSha,
        'verification_verdict' => (string) ($report['verdict'] ?? ''),
        'verification_generated_at' => (string) ($report['generated_at'] ?? ''),
        'imported_by' => 'tools/quran-data/import.php v' . QURAN_TOOL_VERSION,
        'imported_at' => gmdate('c'),
        'spot_check_status' => (string) ($fileMeta['spot_check_status'] ?? 'pending'),
    ];
    $metaRows = [];
    foreach ($fileMeta as $key => $value) {
        $metaRows[] = ['meta_key' => (string) $key, 'meta_value' => (string) $value];
    }
    foreach ($runtimeMeta as $key => $value) {
        $metaRows = array_values(array_filter($metaRows, static fn (array $r): bool => $r['meta_key'] !== $key));
        $metaRows[] = ['meta_key' => $key, 'meta_value' => $value];
    }

    // --- plan (from the verified report; no file re-decode needed) ----------
    echo "plan:\n";
    foreach (QURAN_IMPORT_ORDER as $table => $section) {
        $planned = match ($table) {
            'quran_dataset_meta' => count($metaRows),
            'quran_divisions' => (int) (($counts['divisions']['juz'] ?? 0) + ($counts['divisions']['hizb'] ?? 0) + ($counts['divisions']['rub'] ?? 0)),
            'quran_division_types' => (int) ($counts['division_types'] ?? -1),
            default => (int) ($counts[$section === 'page_ayahs' ? 'segments' : $section] ?? -1),
        };
        echo sprintf("  %-24s %6d rows (replace)\n", $table, $planned);
    }
    echo '  user tables              ' . count($userBefore) . " tables (row counts asserted unchanged)\n";

    if (!$execute) {
        echo "DRY RUN: no changes made. Re-run with --execute to import.\n";
        return;
    }

    // --- load processed rows -------------------------------------------------
    $rows = [];
    foreach (quranProcessedFiles() as $section => $path) {
        $rows[$section] = quranReadJson($path);
    }

    $expectedCounts = [
        'quran_surahs' => count($rows['surahs']),
        'quran_ayahs' => count($rows['ayahs']),
        'quran_pages' => count($rows['pages']),
        'quran_page_ayahs' => count($rows['page_ayahs']),
        'quran_division_types' => count($rows['division_types']),
        'quran_divisions' => count($rows['divisions']),
        'quran_dataset_meta' => count($metaRows),
    ];

    $started = microtime(true);
    $pdo = \App\Database::connection();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        \App\Database::transaction(static function () use ($rows, $metaRows, $expectedCounts): void {
            foreach (array_reverse(QURAN_IMPORT_ORDER) as $table => $section) {
                \App\Database::run('DELETE FROM `' . $table . '`');
            }
            foreach (QURAN_IMPORT_ORDER as $table => $section) {
                $insertRows = $table === 'quran_dataset_meta' ? $metaRows : $rows[$section];
                quranBatchInsert($table, QURAN_IMPORT_COLUMNS[$table], $insertRows);
            }
            foreach ($expectedCounts as $table => $expected) {
                $actual = (int) \App\Database::scalar('SELECT COUNT(*) FROM `' . $table . '`');
                if ($actual !== $expected) {
                    throw new RuntimeException("post-insert count mismatch on {$table}: expected {$expected}, found {$actual}");
                }
            }
        });
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    $elapsed = round((microtime(true) - $started) * 1000);

    $userAfter = quranUserTableCounts();
    if ($userAfter !== $userBefore) {
        $changed = [];
        foreach ($userBefore as $table => $before) {
            $after = $userAfter[$table] ?? null;
            if ($after !== $before) {
                $changed[] = "{$table}: {$before} -> " . var_export($after, true);
            }
        }
        quranFail('user table row counts changed during import (must never happen): ' . implode(' | ', $changed));
    }

    echo "imported in {$elapsed} ms (single transaction, idempotent replace):\n";
    foreach ($expectedCounts as $table => $count) {
        echo sprintf("  %-24s %6d rows\n", $table, $count);
    }
    echo '  user tables              ' . count($userAfter) . " tables unchanged\n";
    echo 'meta:    dataset_version=' . ($rows['meta']['dataset_version'] ?? '?') . ' imported_at=' . $runtimeMeta['imported_at'] . "\n";
    echo "next: php tools/quran-data/audit.php\n";
});
