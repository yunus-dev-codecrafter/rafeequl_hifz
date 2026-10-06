<?php

declare(strict_types=1);

/**
 * Integration tests: fixed-window rate limiting (Prompt 25, audit F-03).
 * Covers the window semantics (allow up to max, block after, reset on
 * expiry), key isolation, hash-only storage (no raw IP/e-mail in the
 * table), the config escape hatches, opportunistic pruning, and the
 * APP_ENV=testing disable switch that protects the regression suite.
 * HTTP-level behavior (429 envelope, middleware wiring) is asserted by
 * the test_security.ps1 smoke, which boots with APP_ENV=production.
 * Run: php tests/Integration/RateLimitTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Helpers\Config;
use App\Services\RateLimitService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();

// Idempotent re-runs: drop leftovers from a previously interrupted run.
Database::run('DELETE FROM rate_limits');

try {
    $svc = new RateLimitService();
    $key = 'ip|POST /api/v1/auth/register|127.0.0.1';
    $hash = hash('sha256', $key);

    // === window semantics: max attempts allowed, next one blocked =========
    checkEquals('attempt 1 allowed (max 3)', true, $svc->attempt($key, 3, 60));
    checkEquals('attempt 2 allowed (max 3)', true, $svc->attempt($key, 3, 60));
    checkEquals('attempt 3 allowed (max 3)', true, $svc->attempt($key, 3, 60));
    checkEquals('attempt 4 blocked (max 3)', false, $svc->attempt($key, 3, 60));
    checkEquals('attempt 5 blocked (max 3)', false, $svc->attempt($key, 3, 60));
    checkEquals('counter stored as 5', 5, (int) Database::scalar(
        'SELECT attempts FROM rate_limits WHERE bucket_key = ?', [$hash]
    ));

    // === key isolation: a different bucket is unaffected ===================
    checkEquals('other key unaffected', true, $svc->attempt('other|key', 3, 60));

    // === privacy: only the SHA-256 hash reaches storage ====================
    checkEquals('raw key never stored', 0, (int) Database::scalar(
        'SELECT COUNT(*) FROM rate_limits WHERE bucket_key = ?', [$key]
    ));
    checkEquals('hashed key stored', 1, (int) Database::scalar(
        'SELECT COUNT(*) FROM rate_limits WHERE bucket_key = ?', [$hash]
    ));

    // === window expiry: expired window restarts at attempt 1 ===============
    Database::run(
        'UPDATE rate_limits SET window_started_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE bucket_key = ?',
        [$hash]
    );
    checkEquals('expired window allows again', true, $svc->attempt($key, 3, 60));
    checkEquals('counter restarted at 1', 1, (int) Database::scalar(
        'SELECT attempts FROM rate_limits WHERE bucket_key = ?', [$hash]
    ));

    // === config escape hatches: max/window <= 0 disables the check =========
    checkEquals('max 0 disables check', true, $svc->attempt($key, 0, 60));
    checkEquals('window 0 disables check', true, $svc->attempt($key, 3, 0));

    // === pruning: counters idle for over a day are deleted =================
    Database::run(
        'UPDATE rate_limits SET window_started_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE bucket_key = ?',
        [$hash]
    );
    checkEquals('prune: fresh attempt lands', true, $svc->attempt('fresh|key', 3, 60));
    checkEquals('prune: stale counter removed', 0, (int) Database::scalar(
        'SELECT COUNT(*) FROM rate_limits WHERE bucket_key = ?', [$hash]
    ));

    // === APP_ENV guard: config mirrors the environment =====================
    $expectedEnabled = !defined('APP_ENV') || APP_ENV !== 'testing';
    checkEquals(
        'limiter flag follows APP_ENV (disabled under testing)',
        $expectedEnabled,
        (bool) Config::get('auth', 'rate_limit_enabled', true)
    );
} finally {
    Database::run('DELETE FROM rate_limits');
}

summary('Rate limiting (Prompt 25)');
