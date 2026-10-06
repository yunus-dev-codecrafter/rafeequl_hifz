<?php

declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Responsibilities:
 *  - define base path constants
 *  - load the environment (.env)
 *  - register a minimal PSR-4 style autoloader for the App\ namespace
 *  - apply base error/logging behaviour and delegate to the centralized handler
 *
 * This file must never contain business features (auth, Quran logic,
 * revision, dashboard, ...). Those belong to later prompts.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('ROUTES_PATH', BASE_PATH . '/routes');

require APP_PATH . '/Helpers/Env.php';

\App\Helpers\Env::load(BASE_PATH . '/.env');

$appEnv = \App\Helpers\Env::getString('APP_ENV', 'production');
$appDebug = \App\Helpers\Env::getBool('APP_DEBUG', false);

define('APP_ENV', $appEnv);
define('APP_DEBUG', $appDebug);

date_default_timezone_set(\App\Helpers\Env::getString('APP_TIMEZONE', 'UTC'));

/*
 * Minimal PSR-4 autoloader:  App\Helpers\Env  ->  app/Helpers/Env.php
 * (kept framework-free and dependency-free on purpose)
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

/*
 * Error handling: log to storage/logs, never expose stack traces in
 * production (APP_DEBUG gates display). Exceptions are rendered by
 * App\Exceptions\ExceptionHandler into the API envelope.
 */
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

error_reporting(E_ALL);

if (APP_DEBUG) {
    set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
}

set_exception_handler(static function (\Throwable $e): void {
    if (class_exists(\App\Exceptions\ExceptionHandler::class)) {
        \App\Exceptions\ExceptionHandler::terminate($e, null);
        return;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Internal Server Error';
});
