<?php

declare(strict_types=1);

/**
 * Integration tests: Prompt 24 schema constraints — the DDL guarantees the
 * app code leans on: enum shapes (incl. migration 0010 preserving existing
 * flip_cards.status rows), UNIQUE email (register race answer), one
 * completion row per task, and cascade deletes for review history.
 * Run: php tests/Integration/SchemaConstraintsTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();

// --- enum column shapes --------------------------------------------------------

checkEquals(
    'flip_cards.status has all four states (0007 + 0010)',
    "enum('active','in_review','mastered','archived')",
    strtolower((string) Database::fetch("SHOW COLUMNS FROM flip_cards LIKE 'status'")['Type'])
);
checkEquals(
    'daily_tasks.status has all four states',
    "enum('pending','active','completed','skipped')",
    strtolower((string) Database::fetch("SHOW COLUMNS FROM daily_tasks LIKE 'status'")['Type'])
);
checkEquals(
    'flip_cards.status default stays active',
    'active',
    (string) Database::fetch("SHOW COLUMNS FROM flip_cards LIKE 'status'")['Default']
);

// --- migration 0010: existing rows survive the enum extension ------------------

$migration = (string) file_get_contents(
    dirname(__DIR__, 2) . '/database/migrations/0010_flip_cards_status_in_review.sql'
);
check(
    '0010 file extends the enum exactly as documented',
    str_contains(
        $migration,
        "MODIFY status ENUM('active', 'in_review', 'mastered', 'archived') NOT NULL DEFAULT 'active'"
    )
);

Database::run('DROP TABLE IF EXISTS scratch_enum');
Database::run("CREATE TABLE scratch_enum (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status ENUM('active','mastered','archived') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB");
try {
    Database::run("INSERT INTO scratch_enum (status) VALUES ('active'), ('mastered'), ('archived')");
    $before = Database::fetchAll('SELECT id, status FROM scratch_enum ORDER BY id');
    Database::run(
        "ALTER TABLE scratch_enum
         MODIFY status ENUM('active', 'in_review', 'mastered', 'archived') NOT NULL DEFAULT 'active'"
    );
    $after = Database::fetchAll('SELECT id, status FROM scratch_enum ORDER BY id');
    checkEquals('0010: existing rows keep their values', $before, $after);

    Database::run('INSERT INTO scratch_enum () VALUES ()');
    checkEquals(
        '0010: default still active',
        'active',
        (string) Database::scalar('SELECT status FROM scratch_enum WHERE id = 4')
    );
    Database::run("INSERT INTO scratch_enum (status) VALUES ('in_review')");
    checkEquals(
        '0010: in_review insertable after the ALTER',
        1,
        (int) Database::scalar("SELECT COUNT(*) FROM scratch_enum WHERE status = 'in_review'")
    );

    $rejected = false;
    try {
        Database::run("INSERT INTO scratch_enum (status) VALUES ('bogus')");
    } catch (PDOException $e) {
        $rejected = true;
    }
    check('0010: invalid enum value still rejected (strict mode)', $rejected);
} finally {
    Database::run('DROP TABLE IF EXISTS scratch_enum');
}

// --- UNIQUE users.email (register race answers 422) ----------------------------

$pdo = Database::connection();
Database::run('DELETE FROM users WHERE email = ?', ['schema-constraint@example.com']);
$now = gmdate('Y-m-d H:i:s');
Database::run(
    'INSERT INTO users (email, display_name, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
    ['schema-constraint@example.com', 'Schema', 'active', $now, $now]
);
$duplicateRejected = false;
try {
    Database::run(
        'INSERT INTO users (email, display_name, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['schema-constraint@example.com', 'Schema 2', 'active', $now, $now]
    );
} catch (PDOException $e) {
    $duplicateRejected = $e->getCode() === '23000'
        || str_contains($e->getMessage(), 'Duplicate entry');
}
check('users.email duplicate insert rejected by UNIQUE (23000)', $duplicateRejected);

// --- one completion row per task ------------------------------------------------

$now = gmdate('Y-m-d H:i:s');
$taskTypeId = (int) Database::scalar('SELECT id FROM task_types ORDER BY id LIMIT 1');
$userId = (int) Database::scalar(
    'SELECT id FROM users WHERE email = ?',
    ['schema-constraint@example.com']
);
Database::run(
    'INSERT INTO daily_tasks (user_id, task_type_id, scheduled_date, duration_minutes, status, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$userId, $taskTypeId, gmdate('Y-m-d'), 30, 'completed', $now, $now]
);
$taskId = (int) Database::connection()->lastInsertId();
$duplicateRejected = false;
try {
    Database::run(
        'INSERT INTO task_completions (task_id, user_id, completed_at, created_at) VALUES (?, ?, ?, ?)',
        [$taskId, $userId, $now, $now]
    );
    Database::run(
        'INSERT INTO task_completions (task_id, user_id, completed_at, created_at) VALUES (?, ?, ?, ?)',
        [$taskId, $userId, $now, $now]
    );
} catch (PDOException $e) {
    // The statement that failed must be the *second* row (UNIQUE), not the first.
    $duplicateRejected = $e->getCode() === '23000'
        && (int) Database::scalar('SELECT COUNT(*) FROM task_completions WHERE task_id = ?', [$taskId]) === 1;
}
check('task_completions allows one row per task (UNIQUE)', $duplicateRejected);

// --- cascade deletes ------------------------------------------------------------

$reviewsCascade = str_contains(
    (string) Database::fetch('SHOW CREATE TABLE flip_card_reviews')['Create Table'],
    'ON DELETE CASCADE'
);
check('flip_card_reviews rows cascade with their card', $reviewsCascade);

// Cleanup (user delete cascades daily_tasks + task_completions).
Database::run('DELETE FROM users WHERE email = ?', ['schema-constraint@example.com']);

exit(summary('Schema constraints (Prompt 24)'));
