<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RateLimitRepository;

/**
 * Fixed-window rate limiting for unauthenticated high-risk endpoints
 * (audit finding F-03: register / forgot-password / reset-password were
 * unthrottled while login already had per-account lockout).
 *
 * Business rule: a bucket allows up to $max attempts per $windowSeconds;
 * the window restarts (counter resets to 1) as soon as it has expired.
 * Counting is per-(route, ip) and per-(route, e-mail); keys are hashed
 * before storage (privacy - conventions §3).
 */
final class RateLimitService
{
    private RateLimitRepository $rateLimits;

    public function __construct()
    {
        $this->rateLimits = new RateLimitRepository();
    }

    /**
     * Records one attempt against $key and reports whether it is still allowed.
     * $max <= 0 or $windowSeconds <= 0 disables the check (config escape hatch).
     */
    public function attempt(string $key, int $max, int $windowSeconds): bool
    {
        if ($max <= 0 || $windowSeconds <= 0) {
            return true;
        }

        $now = time();
        $this->rateLimits->prune(gmdate('Y-m-d H:i:s', $now - 86400));
        $attempts = $this->rateLimits->record(
            hash('sha256', $key),
            gmdate('Y-m-d H:i:s', $now - $windowSeconds)
        );

        return $attempts <= $max;
    }
}
