<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Request;
use App\Response;

/**
 * Middleware contract (PSR-15-style, lightweight).
 *
 * $next receives the request and returns the downstream Response;
 * a middleware may modify that response (headers) or short-circuit
 * by returning its own Response without calling $next.
 */
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
