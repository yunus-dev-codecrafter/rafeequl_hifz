<?php

declare(strict_types=1);

/**
 * Front controller — the single HTTP entry point of the application.
 *
 * Flow: bootstrap → Request::fromGlobals → Router (+ middleware) → Response.
 * Errors are rendered by the centralized ExceptionHandler.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

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
