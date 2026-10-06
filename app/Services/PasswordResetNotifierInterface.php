<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Delivers a password-reset token to the user.
 *
 * Implementations:
 * - LogPasswordResetNotifier (default): records that a reset was requested
 *   WITHOUT the token — safe for logs, useful for auditing.
 * - A future SMTP/mail implementation plugs in here when mail is configured
 *   (never log or expose the raw token anywhere else).
 */
interface PasswordResetNotifierInterface
{
    public function send(string $email, string $rawToken, \DateTimeImmutable $expiresAt): void;
}
