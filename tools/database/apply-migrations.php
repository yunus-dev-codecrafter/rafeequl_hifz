<?php

declare(strict_types=1);

/**
 * Migration applier for a fresh database (Prompt 24A support tool).
 *
 * Applies database/migrations/*.sql in numeric order, once each, recording
 * version + SHA-256 + applied_at in schema_migrations (the run-once registry
 * defined by 0001). An already-recorded version whose file hash differs is a
 * hard failure: schema drift must never be hidden (docs/database/schema.md).
 *
 * Usage: php tools/database/apply-migrations.php [--database=<name>]
 *   DB_* environment variables supply host/port/username/password;
 *   --database overrides DB_DATABASE. The database is created when missing.
 *
 * Exit codes: 0 applied-or-verified · 1 failure · 2 usage error
 */

const DESCRIPTIONS = [
    '0001_schema_migrations.sql' => '0001 schema migrations',
    '0002_quran_canonical.sql' => '0002 quran canonical',
    '0003_users_auth.sql' => '0003 users auth',
    '0004_hifz.sql' => '0004 hifz',
    '0005_revision.sql' => '0005 revision',
    '0006_rabt.sql' => '0006 rabt',
    '0007_flip_cards.sql' => '0007 flip cards',
    '0008_productivity.sql' => '0008 productivity',
    '0009_password_resets.sql' => '0009 password resets',
    '0010_flip_cards_status_in_review.sql' => '0010 flip card in_review state',
    '0011_rate_limits.sql' => '0011 rate limits',
];

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : (string) $value;
}

/** Splits SQL text into statements (full-line comments removed, strings safe). */
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

$database = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $database = substr($arg, strlen('--database='));
        continue;
    }
    fwrite(STDERR, "unknown argument: {$arg}\nUsage: php tools/database/apply-migrations.php [--database=<name>]\n");
    exit(2);
}

if ($database === '') {
    $database = env('DB_DATABASE');
}
if ($database === '' || preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
    fwrite(STDERR, "target database missing or invalid: '{$database}' (pass --database= or set DB_DATABASE)\n");
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
    $server->exec(
        "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    unset($server);

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        $options
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    fwrite(STDERR, "no migration files found\n");
    exit(1);
}

$applied = 0;
$verified = 0;

$tableExists = (bool) $pdo->query(
    "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
)->fetchColumn();

foreach ($files as $file) {
    $version = basename($file);
    $checksum = hash_file('sha256', $file);
    if ($checksum === false) {
        fwrite(STDERR, "cannot hash {$version}\n");
        exit(1);
    }

    $row = null;
    if ($tableExists) {
        $statement = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE version = ?');
        $statement->execute([$version]);
        $row = $statement->fetch();
    }

    if (is_array($row)) {
        if (!hash_equals((string) $row['checksum'], $checksum)) {
            fwrite(STDERR, "DRIFT: {$version} was applied with a different checksum (recorded "
                . substr((string) $row['checksum'], 0, 12) . '…, current ' . substr($checksum, 0, 12)
                . "…). Refusing to continue; migrate explicitly instead of editing applied files.\n");
            exit(1);
        }
        echo "ok      {$version} (already applied)\n";
        $verified++;
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "cannot read {$version}\n");
        exit(1);
    }

    try {
        foreach (splitSql($sql) as $statementSql) {
            $pdo->exec($statementSql);
        }
        $insert = $pdo->prepare(
            'INSERT INTO schema_migrations (version, description, checksum, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        $insert->execute([
            $version,
            DESCRIPTIONS[$version] ?? str_replace(['_', '.sql'], [' ', ''], $version),
            $checksum,
        ]);
        $tableExists = true;
    } catch (PDOException $e) {
        fwrite(STDERR, "FAILED {$version}: " . $e->getMessage() . "\n");
        exit(1);
    }

    echo "applied {$version}\n";
    $applied++;
}

echo "database '{$database}': {$applied} applied, {$verified} already present\n";
exit(0);
