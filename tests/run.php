<?php

declare(strict_types=1);

/**
 * Test runner: executes every *Test.php script (Unit first, then
 * Integration) and exits non-zero if any fails.
 * Usage: php tests/run.php
 * Integration scripts skip themselves when the test database is unreachable.
 */

$files = [];
foreach (['Unit', 'Integration'] as $group) {
    $matches = glob(__DIR__ . '/' . $group . '/*Test.php');
    foreach (($matches === false ? [] : $matches) as $file) {
        $files[] = $file;
    }
}

if ($files === []) {
    fwrite(STDERR, "no test files found\n");
    exit(1);
}

$failures = 0;
foreach ($files as $file) {
    $relative = substr($file, strlen(dirname(__DIR__)) + 1);
    echo '== ' . str_replace('\\', '/', $relative) . "\n";
    passthru(PHP_BINARY . ' ' . escapeshellarg($file), $code);
    if ($code !== 0) {
        $failures++;
    }
    echo "\n";
}

if ($failures > 0) {
    echo "RESULT: {$failures} test file(s) failed\n";
    exit(1);
}

echo "RESULT: all test files passed\n";
exit(0);
