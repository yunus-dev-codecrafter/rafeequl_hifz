<?php

declare(strict_types=1);

/**
 * Deployment bundle builder (Prompt 27).
 *
 * Produces a host-portable deployment package for shared hosting
 * (InfinityFree first; any host with a sub-directory document root):
 *
 *   deploy/dist/            upload payload - laid out as:
 *     htdocs/               -> the host document root (InfinityFree htdocs/,
 *                              Apache public_html/, nginx root/)
 *     app/ config/ routes/  -> account root (ONE level above the document
 *     storage/                root, never web-reachable)
 *     .env.example          -> template; the operator writes the real .env
 *     MANIFEST.sha256       -> written next to dist/ (deploy/MANIFEST.sha256)
 *
 *   Hosts whose panel refuses any upload outside the document root can
 *   instead extract the payload DIRECTLY INTO htdocs/ so that index.php,
 *   app/, config/, routes/, storage/ and .env all sit side by side in the
 *   document root. That layout stays safe because index.php resolves its
 *   bootstrap by location and every server-side directory ships a deny-all
 *   .htaccess, mirrored by the RewriteRule in htdocs/.htaccess. See
 *   docs/deployment/infinityfree.md (Layout A / Layout B).
 *   deploy/sql/
 *     rafeequl-hifz.sql     -> single phpMyAdmin import: migrations
 *                              0001..0011 (raw file bytes) + the 11
 *                              schema_migrations registry rows (same
 *                              version/description/checksum semantics as
 *                              tools/database/apply-migrations.php) + all
 *                              quran_* canonical data.
 *     MANIFEST.sha256       -> checksum of the SQL file.
 *
 * Excluded on purpose (never uploaded, never web-reachable):
 *   .env, tests/, docs/, tools/, data/ (private Quran source files),
 *   database/ (migrations ship inside the SQL), storage runtime output
 *   (logs/cache/sessions contents), .git, *.log.
 *
 * Hard gates (Quran-data correctness is never traded for convenience):
 * the quran database must hold the canonical counts - 6236 ayahs and
 * 604 pages - or the build refuses.
 *
 * Usage: php tools/deploy/build-bundle.php [--force] [--quran-database=<name>]
 *   DB_* env supplies MySQL credentials; QURAN_CANONICAL_DB (or
 *   --quran-database) picks the canonical dataset database
 *   (default: rafeequl_hifz).
 *
 * Exit codes: 0 ok - 1 failure - 2 usage error
 */

const MIGRATION_DESCRIPTIONS = [
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

/** Directory names that must never appear inside the upload payload. */
const FORBIDDEN_DIRS = ['tests', 'docs', 'tools', 'data', 'database', 'deploy', '.git'];

/** File names that must never appear inside the upload payload. */
const FORBIDDEN_FILES = ['.env'];

/** Canonical dataset invariants - a non-matching dump is a hard failure. */
const CANONICAL_AYAHS = 6236;
const CANONICAL_PAGES = 604;

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : (string) $value;
}

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

/** Recursive copy of a source tree (includes dotfiles, skips "." and ".."). */
function copyTree(string $src, string $dst): int
{
    if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
        throw new RuntimeException("cannot create {$dst}");
    }
    $copied = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        $target = $dst . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($src) + 1));
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new RuntimeException("cannot create {$target}");
            }
            continue;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create {$dir}");
        }
        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException("cannot copy {$item->getPathname()}");
        }
        $copied++;
    }
    return $copied;
}

/** All files under a root, relative POSIX paths, sorted. @return array<int, string> */
function walkFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/** Renders one SQL literal from a native PDO value. */
function sqlLiteral(mixed $value, PDO $pdo): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        return sprintf('%.10g', $value);
    }
    $quoted = $pdo->quote((string) $value);
    if ($quoted === false) {
        throw new RuntimeException('PDO::quote failed');
    }
    return $quoted;
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$force = false;
$quranDatabase = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
        continue;
    }
    if (str_starts_with($arg, '--quran-database=')) {
        $quranDatabase = substr($arg, strlen('--quran-database='));
        continue;
    }
    fwrite(STDERR, "unknown argument: {$arg}\nUsage: php tools/deploy/build-bundle.php [--force] [--quran-database=<name>]\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$outDir = $root . '/deploy';
$dist = $outDir . '/dist';
$sqlDir = $outDir . '/sql';
$sqlFile = $sqlDir . '/rafeequl-hifz.sql';
$distManifest = $outDir . '/MANIFEST.sha256';
$sqlManifest = $sqlDir . '/MANIFEST.sha256';

if ($quranDatabase === '') {
    $quranDatabase = env('QURAN_CANONICAL_DB', 'rafeequl_hifz');
}
if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $quranDatabase) !== 1) {
    fwrite(STDERR, "invalid quran database name: '{$quranDatabase}'\n");
    exit(2);
}

foreach ([$dist, $sqlDir] as $path) {
    if (is_dir($path) && !empty(array_diff(scandir($path) ?: [], ['.', '..']))) {
        if (!$force) {
            fwrite(STDERR, "{$path} already exists - pass --force to rebuild\n");
            exit(2);
        }
    }
}

// ---------------------------------------------------------------------------
// Part A - upload payload (deploy/dist)
// ---------------------------------------------------------------------------

$sources = ['public' => 'htdocs', 'app' => 'app', 'config' => 'config', 'routes' => 'routes'];
foreach (array_merge(array_keys($sources), ['.env.example']) as $srcName) {
    $src = $root . '/' . $srcName;
    if (!file_exists($src)) {
        fwrite(STDERR, "missing source: {$src}\n");
        exit(1);
    }
}

$rm = static function (string $dir) use (&$rm): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $rm($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
};
if (is_dir($dist)) {
    $rm($dist);
}
if (!mkdir($dist, 0755, true) && !is_dir($dist)) {
    fwrite(STDERR, "cannot create {$dist}\n");
    exit(1);
}

$copied = 0;
foreach ($sources as $srcName => $dstName) {
    $copied += copyTree($root . '/' . $srcName, $dist . '/' . $dstName);
}

// storage/: the three runtime directories with .gitkeep only - never ship
// logs, cache or session contents.
foreach (['logs', 'cache', 'sessions'] as $sub) {
    $dir = $dist . '/storage/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "cannot create {$dir}\n");
        exit(1);
    }
    file_put_contents($dir . '/.gitkeep', '');
    $copied++;
}

// storage/.htaccess is not reached by the whitelist copy above (storage is
// built rather than mirrored), but it is required: it is the deny-all guard
// that keeps runtime data unreachable when a host keeps the whole
// application inside the document root (htdocs-only layout).
if (!copy($root . '/storage/.htaccess', $dist . '/storage/.htaccess')) {
    fwrite(STDERR, "cannot copy storage/.htaccess\n");
    exit(1);
}
$copied++;

if (!copy($root . '/.env.example', $dist . '/.env.example')) {
    fwrite(STDERR, "cannot copy .env.example\n");
    exit(1);
}
$copied++;

// Exclusion audit - second line of defense on top of the whitelist copy.
$distFiles = walkFiles($dist);
$violations = [];
foreach ($distFiles as $rel) {
    foreach (explode('/', $rel) as $segment) {
        if (in_array($segment, FORBIDDEN_DIRS, true)) {
            $violations[] = $rel . ' (dir ' . $segment . ')';
        }
    }
    $base = basename($rel);
    if (in_array($base, FORBIDDEN_FILES, true) || str_ends_with($base, '.log') || str_ends_with($base, '.sql')) {
        $violations[] = $rel;
    }
}
foreach (['htdocs/index.php', 'htdocs/.htaccess', 'htdocs/sw.js', 'app/bootstrap.php', 'app/.htaccess', 'config/database.php', 'config/.htaccess', 'routes/api.php', 'routes/.htaccess', 'storage/.htaccess', '.env.example'] as $required) {
    if (!is_file($dist . '/' . $required)) {
        $violations[] = 'missing required ' . $required;
    }
}
if ($violations !== []) {
    fwrite(STDERR, "payload audit FAILED:\n  " . implode("\n  ", $violations) . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Part B - SQL bundle (deploy/sql/rafeequl-hifz.sql)
// ---------------------------------------------------------------------------

if (!is_dir($sqlDir) && !mkdir($sqlDir, 0755, true) && !is_dir($sqlDir)) {
    fwrite(STDERR, "cannot create {$sqlDir}\n");
    exit(1);
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
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$quranDatabase};charset=utf8mb4",
        $user,
        $password,
        $options
    );
} catch (PDOException $e) {
    fwrite(STDERR, "connection to '{$quranDatabase}' failed: " . $e->getMessage() . "\n");
    exit(1);
}

$ayahs = (int) $pdo->query('SELECT COUNT(*) FROM quran_ayahs')->fetchColumn();
$pages = (int) $pdo->query('SELECT COUNT(*) FROM quran_pages')->fetchColumn();
if ($ayahs !== CANONICAL_AYAHS || $pages !== CANONICAL_PAGES) {
    fwrite(STDERR, "canonical gate FAILED: quran_ayahs={$ayahs} (expected " . CANONICAL_AYAHS
        . "), quran_pages={$pages} (expected " . CANONICAL_PAGES . ") in '{$quranDatabase}'.\n"
        . "Import the verified dataset first (tools/quran-data/).\n");
    exit(1);
}

$migrations = glob($root . '/database/migrations/*.sql') ?: [];
sort($migrations, SORT_STRING);
if ($migrations === []) {
    fwrite(STDERR, "no migration files found\n");
    exit(1);
}

/** @var array<int, array{name: string, sha: string, body: string}> $migrationRows */
$migrationRows = [];
foreach ($migrations as $file) {
    $body = file_get_contents($file);
    if ($body === false) {
        fwrite(STDERR, "cannot read {$file}\n");
        exit(1);
    }
    $migrationRows[] = ['name' => basename($file), 'sha' => hash('sha256', $body), 'body' => $body];
}

$tables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name LIKE 'quran\\_%'
     ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);
if ($tables === []) {
    fwrite(STDERR, "no quran_* tables in '{$quranDatabase}'\n");
    exit(1);
}

$generatedAt = gmdate('Y-m-d\TH:i:s\Z');
$sql = [];
$sql[] = '-- ============================================================';
$sql[] = '-- Rafeequl Hifz - deployment database bundle (Prompt 27)';
$sql[] = "-- Generated: {$generatedAt} by tools/deploy/build-bundle.php";
$sql[] = '-- Contents:   database/migrations/0001..0011 (verbatim) + the';
$sql[] = '--             schema_migrations registry rows (same semantics as';
$sql[] = '--             tools/database/apply-migrations.php) + quran_* data';
$sql[] = '--             from `' . $quranDatabase . '` (' . number_format($ayahs) . ' ayahs, ' . number_format($pages) . ' pages).';
$sql[] = '-- How to import: phpMyAdmin > the EMPTY account database > Import.';
$sql[] = '-- Do NOT import into a populated database: the CREATE TABLEs are';
$sql[] = '-- left untouched on purpose so drift stays visible as an error.';
$sql[] = '-- Rebuild: php tools/deploy/build-bundle.php --force';
$sql[] = '-- ============================================================';
$sql[] = '';
$sql[] = 'SET NAMES utf8mb4;';
$sql[] = 'SET FOREIGN_KEY_CHECKS=0;';
$sql[] = 'SET SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\';';
$sql[] = '';

foreach ($migrationRows as $row) {
    $sql[] = '-- ==== migration ' . $row['name'] . ' (sha256 ' . $row['sha'] . ') ====';
    $sql[] = rtrim($row['body'], "\r\n");
    $sql[] = '';
}

$sql[] = '-- ==== schema_migrations registry (11 rows; mirrors apply-migrations.php) ====';
$registryRows = [];
foreach ($migrationRows as $row) {
    $description = MIGRATION_DESCRIPTIONS[$row['name']]
        ?? str_replace(['_', '.sql'], [' ', ''], $row['name']);
    $registryRows[] = '  (' . $pdo->quote($row['name']) . ', ' . $pdo->quote($description)
        . ', ' . $pdo->quote($row['sha']) . ', UTC_TIMESTAMP())';
}
$sql[] = 'INSERT INTO schema_migrations (version, description, checksum, applied_at) VALUES' . "\n"
    . implode(",\n", $registryRows) . ';';
$sql[] = '';

foreach ($tables as $table) {
    $columns = $pdo->query(
        'SELECT column_name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ' . $pdo->quote($table) . '
         ORDER BY ordinal_position'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($columns === []) {
        fwrite(STDERR, "no columns for {$table}\n");
        exit(1);
    }

    $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
    $quotedColumns = array_map(static fn (string $c): string => '`' . $c . '`', $columns);
    $columnList = implode(', ', $quotedColumns);

    $sql[] = '-- ==== ' . $table . ' (' . number_format(count($rows)) . ' rows) ====';
    if ($rows === []) {
        $sql[] = "-- (empty)";
        $sql[] = '';
        continue;
    }

    $batch = [];
    foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $column) {
            $values[] = sqlLiteral($row[$column], $pdo);
        }
        $batch[] = '(' . implode(', ', $values) . ')';
        if (count($batch) === 200) {
            $sql[] = 'INSERT INTO `' . $table . '` (' . $columnList . ') VALUES' . "\n" . implode(",\n", $batch) . ';';
            $batch = [];
        }
    }
    if ($batch !== []) {
        $sql[] = 'INSERT INTO `' . $table . '` (' . $columnList . ') VALUES' . "\n" . implode(",\n", $batch) . ';';
    }
    $sql[] = '';
}

$sql[] = 'SET FOREIGN_KEY_CHECKS=1;';
$sqlBody = implode("\n", $sql) . "\n";

if (!file_put_contents($sqlFile, $sqlBody)) {
    fwrite(STDERR, "cannot write {$sqlFile}\n");
    exit(1);
}
file_put_contents($sqlManifest, hash('sha256', $sqlBody) . '  rafeequl-hifz.sql' . "\n");

// ---------------------------------------------------------------------------
// Part C - dist manifest (deploy/MANIFEST.sha256, outside the upload payload)
// ---------------------------------------------------------------------------

$lines = [];
foreach ($distFiles as $rel) {
    $lines[] = hash_file('sha256', $dist . '/' . $rel) . '  ' . $rel;
}
file_put_contents($distManifest, implode("\n", $lines) . "\n");

$bytes = 0;
foreach ($distFiles as $rel) {
    $bytes += (int) filesize($dist . '/' . $rel);
}

echo "ok build-bundle\n";
echo '  payload:  ' . count($distFiles) . ' files, ' . number_format($bytes) . " bytes -> deploy/dist (htdocs/ + app root)\n";
echo '  manifest: ' . count($lines) . " entries -> deploy/MANIFEST.sha256\n";
echo '  sql:      ' . number_format(strlen($sqlBody)) . " bytes, " . count($tables)
    . " quran tables, " . count($migrationRows) . " migrations -> deploy/sql/rafeequl-hifz.sql\n";
echo '  sql sha256: ' . hash('sha256', $sqlBody) . "\n";
exit(0);
