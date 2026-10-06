<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\HttpException;
use App\Helpers\Config;
use App\Request;
use App\Response;
use App\Services\RateLimitService;

/**
 * Fixed-window rate limiting for unauthenticated high-risk endpoints
 * (audit finding F-03): POST register, forgot-password, reset-password.
 *
 * Two buckets are counted per request:
 *   - route + client IP   (limits distributed address abuse)
 *   - route + e-mail body (limits hammering one account/box; only counted
 *     when the body actually carries a syntactically valid e-mail)
 *
 * Disabled automatically when APP_ENV=testing (config/auth.php) so the
 * regression suite - which runs every smoke against 127.0.0.1 - can never
 * trip its own limiter; HTTP behavior is asserted by test_security.ps1,
 * which boots its server with APP_ENV=production.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!(bool) Config::get('auth', 'rate_limit_enabled', true)) {
            return $next($request);
        }

        $ipMax = (int) Config::get('auth', 'rate_limit_ip_max', 10);
        $emailMax = (int) Config::get('auth', 'rate_limit_email_max', 5);
        $window = (int) Config::get('auth', 'rate_limit_window_seconds', 60);
        $service = new RateLimitService();
        $route = $request->method() . ' ' . $request->path();

        if (!$service->attempt('ip|' . $route . '|' . ($request->ip() ?? 'unknown'), $ipMax, $window)) {
            throw $this->tooMany();
        }

        $email = $request->input('email');
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            if (!$service->attempt('email|' . $route . '|' . strtolower($email), $emailMax, $window)) {
                throw $this->tooMany();
            }
        }

        return $next($request);
    }

    private function tooMany(): HttpException
    {
        return new HttpException(429, 'Too Many Requests', [
            ['message' => 'Too many requests. Please wait a moment and try again.'],
        ]);
    }
}
