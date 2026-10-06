<?php

declare(strict_types=1);

use App\Helpers\Env;

/**
 * Authentication configuration (values come from the environment).
 * No secrets here — only policy numbers.
 */

return [
    // Brute-force protection: lock the account after N failed logins...
    'max_failed_attempts' => Env::getInt('AUTH_MAX_FAILED_ATTEMPTS', 5),
    // ...for this many minutes.
    'lockout_minutes' => Env::getInt('AUTH_LOCKOUT_MINUTES', 15),
    // Password-reset token lifetime.
    'password_reset_ttl_minutes' => Env::getInt('PASSWORD_RESET_TTL_MINUTES', 60),
    // Artificial delay on a failed login (timing-equalization, microseconds).
    'failure_delay_microseconds' => 200000,
    // Fixed-window rate limiting for unauthenticated high-risk endpoints
    // (register / forgot-password / reset-password). Disabled automatically
    // under APP_ENV=testing so the regression suite (one shared IP) never
    // trips its own limiter - HTTP behavior is covered by test_security.ps1.
    'rate_limit_enabled' => Env::getString('APP_ENV', 'production') !== 'testing',
    'rate_limit_ip_max' => Env::getInt('RATE_LIMIT_IP_MAX', 10),
    'rate_limit_email_max' => Env::getInt('RATE_LIMIT_EMAIL_MAX', 5),
    'rate_limit_window_seconds' => Env::getInt('RATE_LIMIT_WINDOW_SECONDS', 60),
];
