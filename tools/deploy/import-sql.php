<?php

declare(strict_types=1);

/**
 * Deployment SQL importer (Prompt 27) - the LOCAL stand-in for phpMyAdmin.
 *
 * Executes deploy/sql/rafeequl-hifz.sql against a database and prints a
 * machine-readable summary of what landed. Deployment uses phpMyAdmin on
 * the host (no SSH on InfinityFree); this tool exists so the same SQL can
 * be proven locally before upload, and so test_deploy.ps1 can assert the
 * fresh-import result.
 *
 * Safety rules:
 *   - the target must be EMPTY unless --drop is passed (a non-empty import
 *     of CREATE TABLEs fails halfway on a real host too - fail early here);
 *   - --drop removes and recreates the target database first;
 *   - statements are executed one at a time with the same splitter as
 *     tools/database/apply-migrations.php; any failure aborts with the
 *     offending statement index.
 *
 * Usage: php tools/deploy/import-sql.php --database=<name> [--sql=<path>] [--drop]
 *   DB_* env supplies MySQL credentials.
 *   Default --sql: deploy/sql/rafeequl-hifz.sql (relative to the repo root).
 *
 * Exit codes: 0 ok - 1 failure - 2 usage error
 */

/** Splits SQL text into statements (full-line comments removed, strings safe). Mirrors tools/database/apply-migrations.php. */
function splitSql(string $sql): array
{
    $kept = [];
    foreach (explode("\n", $sql) as $line) {
        $line = rtrim($line, "\r");
        if (str_starts_with(ltrim($line), '--')) {
            continue;
        }
        $kept[] = $line;
    }

    $body = implode("\n", $kept);
    $statements = [];
    $current = '';
    $quote = null;
    $length = strlen($body);

    for ($i = 0; $i < $length; $i++) {
        $char = $body[$i];
        if ($quote !== null) {
            $current .= $char;
            if ($char === '\\' && $quote === "'" && $i + 1 < $length) {
                $current .= $body[++$i];
                continue;
            }
            if ($char === $quote) {
                if ($i + 1 < $length && $body[$i + 1] === $quote) {
                    $current .= $body[++$i];
                    continue;
                }
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '`') {
            $quote = $char;
            $current .= $char;
            continue;
        }
        if ($char === ';') {
            $statements[] = $current;
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if (trim($current) !== '') {
        $statements[] = $current;
    }

    return array_values(array_filter($statements, static fn (string $s): bool => trim($s) !== ''));
}

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : (string) $value;
}

function scalar(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$database = '';
$sqlFile = '';
$drop = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $database = substr($arg, strlen('--database='));
        continue;
    }
    if (str_starts_with($arg, '--sql=')) {
        $sqlFile = substr($arg, strlen('--sql='));
        continue;
    }
    if ($arg === '--drop') {
        $drop = true;
        continue;
    }
    fwrite(STDERR, "unknown argument: {$arg}\nUsage: php tools/deploy/import-sql.php --database=<name> [--sql=<path>] [--drop]\n");
    exit(2);
}

if ($database === '' || preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
    fwrite(STDERR, "target database missing or invalid: '{$database}' (pass --database=)\n");
    exit(2);
}
if ($sqlFile === '') {
    $sqlFile = dirname(__DIR__, 2) . '/deploy/sql/rafeequl-hifz.sql';
}
$sql = @file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "cannot read {$sqlFile} (run php tools/deploy/build-bundle.php first)\n");
    exit(2);
}

$host = env('DB_HOST', 'localhost');
$port = env('DB_PORT', '3306');
$user = env('DB_USERNAME', '');
$password = env('DB_PASSWORD', '');
if ($user === '') {
    fwrite(STDERR, "DB_USERNAME is not set\n");
    exit(2);
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
];

try {
    $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, $options);
} catch (PDOException $e) {
    fwrite(STDERR, 'connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($drop) {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
$server->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
unset($server);

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        $options
    );
} catch (PDOException $e) {
    fwrite(STDERR, "connection to '{$database}' failed: " . $e->getMessage() . "\n");
    exit(1);
}

$existing = scalar($pdo, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'');
if ($existing > 0 && !$drop) {
    fwrite(STDERR, "database '{$database}' is not empty ({$existing} tables). "
        . "Import into an EMPTY database, or pass --drop to recreate it.\n");
    exit(1);
}

$statements = splitSql($sql);
if ($statements === []) {
    fwrite(STDERR, "no statements found in {$sqlFile}\n");
    exit(1);
}

foreach ($statements as $index => $statementSql) {
    try {
        $pdo->exec($statementSql);
    } catch (PDOException $e) {
        fwrite(STDERR, 'statement ' . ($index + 1) . ' of ' . count($statements) . ' failed: ' . $e->getMessage() . "\n");
        fwrite(STDERR, 'first line: ' . strtok(ltrim($statementSql), "\n") . "\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Summary - facts only; assertions live in test_deploy.ps1.
// ---------------------------------------------------------------------------

$tables = scalar(
    $pdo,
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
);
$summary = [
    'database' => $database,
    'statements' => count($statements),
    'tables' => $tables,
    'registry' => scalar($pdo, 'SELECT COUNT(*) FROM schema_migrations'),
    'quran_ayahs' => scalar($pdo, 'SELECT COUNT(*) FROM quran_ayahs'),
    'quran_pages' => scalar($pdo, 'SELECT COUNT(*) FROM quran_pages'),
    'quran_surahs' => scalar($pdo, 'SELECT COUNT(*) FROM quran_surahs'),
    'quran_divisions' => scalar($pdo, 'SELECT COUNT(*) FROM quran_divisions'),
    'task_types' => scalar($pdo, 'SELECT COUNT(*) FROM task_types'),
    'flip_card_categories' => scalar($pdo, 'SELECT COUNT(*) FROM flip_card_categories'),
    'users' => scalar($pdo, 'SELECT COUNT(*) FROM users'),
];

echo json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
