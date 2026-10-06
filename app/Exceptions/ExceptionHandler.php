<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Request;
use App\Response;

/**
 * Centralized error handling (conventions §11).
 *
 * - HttpException/ValidationException → mapped status + envelope errors.
 * - Anything else → logged, then a generic 500 (details only when APP_DEBUG).
 * - In production no stack traces, SQL, paths, or environment values leak.
 */
final class ExceptionHandler
{
    public static function render(\Throwable $e, ?Request $request = null): Response
    {
        // Error responses follow the same cache policy as successes (Prompt 19).
        $cacheControl = $request !== null && str_starts_with($request->path(), '/api/') ? 'no-store' : 'no-cache';

        return self::build($e)->withHeader('Cache-Control', $cacheControl);
    }

    private static function build(\Throwable $e): Response
    {
        if ($e instanceof HttpException) {
            return Response::failure($e->getErrors(), $e->getStatusCode())->withSecurityHeaders();
        }

        self::report($e);

        if (APP_DEBUG) {
            return Response::failure([
                [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                ],
            ], 500)->withSecurityHeaders();
        }

        return Response::failure([['message' => 'Internal Server Error']], 500)->withSecurityHeaders();
    }

    public static function report(\Throwable $e): void
    {
        error_log(sprintf(
            '[%s] %s: %s in %s:%d',
            gmdate('Y-m-d\TH:i:s\Z'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    /** Last-resort entry point (bootstrap's set_exception_handler and front controller catch). */
    public static function terminate(\Throwable $e, ?Request $request = null): void
    {
        try {
            self::render($e, $request)->send();
        } catch (\Throwable $failure) {
            self::report($failure);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Internal Server Error';
        }
    }
}
