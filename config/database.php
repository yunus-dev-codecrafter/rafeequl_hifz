<?php

declare(strict_types=1);

use App\Helpers\Env;

/**
 * Database configuration foundation.
 *
 * Credentials are read exclusively from the environment.
 * No production database credentials are hard-coded anywhere in the
 * repository; the `.env` file is git-ignored.
 */

return [
    'driver' => Env::getString('DB_DRIVER', 'mysql'),
    'host' => Env::getString('DB_HOST', 'localhost'),
    'port' => Env::getInt('DB_PORT', 3306),
    'database' => Env::getString('DB_DATABASE', ''),
    'username' => Env::getString('DB_USERNAME', ''),
    'password' => Env::getString('DB_PASSWORD', ''),
    'charset' => Env::getString('DB_CHARSET', 'utf8mb4'),
    'collation' => 'utf8mb4_unicode_ci',
    'options' => [
        // PDO options are applied when the connection layer is introduced
        // in a later prompt; listed here as the configuration foundation.
    ],
];
