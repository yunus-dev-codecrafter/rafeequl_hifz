<?php

declare(strict_types=1);

/**
 * Root front controller — provides instant compatibility when the repository
 * or folder is uploaded directly to shared hosting document roots (e.g., InfinityFree htdocs/).
 */

if (is_file(__DIR__ . '/public/index.php')) {
    require __DIR__ . '/public/index.php';
    return;
}

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
