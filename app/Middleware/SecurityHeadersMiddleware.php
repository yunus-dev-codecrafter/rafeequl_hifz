<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Request;
use App\Response;

/**
 * Applies baseline security headers to every successful response.
 * Exception responses get the same headers via ExceptionHandler.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        if (!$response instanceof Response) {
            throw new \RuntimeException('Downstream middleware must return App\Response');
        }

        // API JSON must never be cached (personal data, freshness); the shell
        // and static assets revalidate on each use (Prompt 19, PWA).
        $cacheControl = str_starts_with($request->path(), '/api/') ? 'no-store' : 'no-cache';

        return $response->withSecurityHeaders()->withHeader('Cache-Control', $cacheControl);
    }
}
