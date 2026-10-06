<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Default reset notifier: appends a non-sensitive audit line to
 * storage/logs/password-reset.log. The raw token is deliberately NOT
 * written anywhere (conventions §12: never log secrets).
 *
 * Delivery of the actual token requires an SMTP implementation of
 * PasswordResetNotifierInterface — wired in when mail is configured.
 */
final class LogPasswordResetNotifier implements PasswordResetNotifierInterface
{
    public function send(string $email, string $rawToken, \DateTimeImmutable $expiresAt): void
    {
        unset($rawToken); // token must never reach a log sink

        $line = sprintf(
            "[%s] password reset requested for %s (expires %s)\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $email,
            $expiresAt->format('c')
        );

        @file_put_contents(STORAGE_PATH . '/logs/password-reset.log', $line, FILE_APPEND);
    }
}
