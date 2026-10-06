<?php

declare(strict_types=1);

/**
 * Front controller — the single HTTP entry point of the application.
 *
 * Flow: bootstrap → Request::fromGlobals → Router (+ middleware) → Response.
 * Errors are rendered by the centralized ExceptionHandler.
 *
 * Two deployment layouts are supported:
 *
 *  1. Split (InfinityFree FTP root, documented in docs/deployment):
 *       /app /config /routes /storage   <- account root, never web-reachable
 *       /htdocs/index.php               <- document root
 *  2. htdocs-only — for hosts whose panel refuses any upload outside the
 *     document root:
 *       /htdocs/{index.php,app,config,routes,storage,.env}
 *     Everything server-side still stays unreachable because .htaccess
 *     denies HTTP access to app/, config/, routes/ and storage/.
 *
 * bootstrap.php derives BASE_PATH from its own location, so locating the
 * correct bootstrap file is the only layout-dependent step.
 */

$bootstrap = __DIR__ . '/app/bootstrap.php';

if (!is_file($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/app/bootstrap.php';
}

if (!is_file($bootstrap)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Application bootstrap not found.';
    exit(1);
}

require $bootstrap;

use App\Exceptions\ExceptionHandler;
use App\Middleware\SecurityHeadersMiddleware;
use App\Request;
use App\Router;

$request = null;

try {
    $request = Request::fromGlobals();

    $router = new Router();
    $router->setGlobalMiddleware([SecurityHeadersMiddleware::class]);
    $router->loadFile(ROUTES_PATH . '/api.php');
    $router->loadFile(ROUTES_PATH . '/web.php');

    $router->dispatch($request)->send();
} catch (\Throwable $e) {
    ExceptionHandler::terminate($e, $request);
}
