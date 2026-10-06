<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Exceptions\ValidationException;
use App\Helpers\Config;
use App\Repositories\PasswordResetRepository;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;

/**
 * Password-reset architecture: request → single-use, time-limited token
 * (only its SHA-256 hash is stored) → reset → ALL sessions revoked.
 *
 * Requesting a reset always reports the same generic outcome, whether or
 * not the email exists (no account enumeration).
 */
final class PasswordResetService
{
    private UserRepository $users;
    private PasswordResetRepository $resets;
    private SessionRepository $sessions;
    private PasswordResetNotifierInterface $notifier;

    public function __construct(
        ?UserRepository $users = null,
        ?PasswordResetRepository $resets = null,
        ?SessionRepository $sessions = null,
        ?PasswordResetNotifierInterface $notifier = null,
    ) {
        $this->users = $users ?? new UserRepository();
        $this->resets = $resets ?? new PasswordResetRepository();
        $this->sessions = $sessions ?? new SessionRepository();
        $this->notifier = $notifier ?? new LogPasswordResetNotifier();
    }

    /** Always succeeds from the caller's perspective (anti-enumeration). */
    public function request(string $email, ?string $ip = null): void
    {
        $email = strtolower(trim($email));
        $user = $this->users->findByEmail($email);

        if ($user === null || $user['status'] !== 'active') {
            // Silent no-op: the response must be identical to the success path
            // — same body AND the same elapsed time (Prompt 24: without a
            // matching delay the DB round-trips on the real path leak whether
            // the email exists).
            $this->delay();
            return;
        }

        $ttl = (int) Config::get('auth', 'password_reset_ttl_minutes', 60);
        $expiresAt = new \DateTimeImmutable('+' . $ttl . ' minutes', new \DateTimeZone('UTC'));
        $token = bin2hex(random_bytes(32));

        $this->resets->deleteAllForUser((int) $user['id']);
        $this->resets->create(
            (int) $user['id'],
            hash('sha256', $token),
            $expiresAt->format('Y-m-d H:i:s'),
            $ip
        );

        $this->notifier->send((string) $user['email'], $token, $expiresAt);
        // Identical padding as the no-op path — both paths take the same base
        // time; the remaining difference is a couple of local DB round-trips.
        $this->delay();
    }

    /** Consumes a valid token, sets the new password, kills every session. */
    public function reset(string $token, string $newPassword): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw self::invalidToken();
        }

        $tokenHash = hash('sha256', $token);
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        Database::transaction(function () use ($tokenHash, $passwordHash) {
            $row = $this->resets->findValidByHash($tokenHash);
            if ($row === null) {
                throw self::invalidToken();
            }

            // Atomic single-use claim — fails if a concurrent request won.
            if (!$this->resets->claim((int) $row['id'])) {
                throw self::invalidToken();
            }

            $this->users->updatePassword((int) $row['user_id'], $passwordHash);
            // Force re-authentication everywhere after a credential change.
            $this->sessions->deleteAllForUser((int) $row['user_id']);
        });
    }

    private static function invalidToken(): ValidationException
    {
        return ValidationException::withErrors([
            ['field' => 'token', 'message' => 'Reset token is invalid or expired'],
        ]);
    }

    /** Same configurable padding AuthService uses for failed logins. */
    private function delay(): void
    {
        $microseconds = (int) Config::get('auth', 'failure_delay_microseconds', 200000);
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
