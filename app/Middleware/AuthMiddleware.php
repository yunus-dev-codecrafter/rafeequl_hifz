<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\HttpException;
use App\Request;
use App\Response;
use App\Services\AuthService;

/**
 * Requires a valid, unexpired session belonging to an active user.
 * Attaches the authenticated user (App\Models\User) as request attribute
 * `user` and the raw session token as `session_token`.
 *
 * Never trusts any client-supplied user id — identity comes only from
 * the session cookie (conventions §12: never trust client input).
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->cookie((string) \App\Helpers\Config::get('app', 'session.name', 'rafeequl_hifz_session'));

        $user = (new AuthService())->authenticate($token);
        if ($user === null) {
            throw new HttpException(401, 'Unauthenticated', [
                ['message' => 'Authentication required'],
            ]);
        }

        return $next(
            $request
                ->withAttribute('user', $user)
                ->withAttribute('session_token', $token)
        );
    }
}
