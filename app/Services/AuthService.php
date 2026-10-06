<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Exceptions\HttpException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Helpers\Config;
use App\Models\User;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;

/**
 * Registration, login, logout, session lifecycle.
 *
 * Security posture (conventions §12):
 * - passwords hashed with password_hash() (bcrypt, PASSWORD_DEFAULT);
 * - session tokens are 32 random bytes; only SHA-256 hashes are stored;
 * - a brand-new token is issued on every login (session fixation);
 * - failed logins are counted and temporarily lock the account;
 * - a dummy hash + fixed delay equalizes timing for unknown emails;
 * - login/profile responses never expose hashes or other users' data.
 */
final class AuthService
{
    /** Valid bcrypt hash used only to equalize timing for unknown emails. */
    private const DUMMY_HASH = '$2y$10$4uHUUkXldgbK4L626vL3OO9WB7M9QWKEouJFv8.oo/YEQqvJrGH92';

    private const TOKEN_BYTES = 32;

    private UserRepository $users;
    private SessionRepository $sessions;

    public function __construct(?UserRepository $users = null, ?SessionRepository $sessions = null)
    {
        $this->users = $users ?? new UserRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /**
     * Creates account + default settings, then signs in (issues a session).
     *
     * @param array<string, mixed> $data sanitized input
     * @return array{user: array<string, mixed>, token: string}
     */
    public function register(array $data, ?string $ip = null, ?string $userAgent = null): array
    {
        $email = strtolower(trim((string) $data['email']));
        $displayName = trim((string) ($data['display_name'] ?? ''));

        if ($this->users->findByEmail($email) !== null) {
            throw ValidationException::withErrors([
                ['field' => 'email', 'message' => 'Email is already registered'],
            ]);
        }

        $passwordHash = password_hash((string) $data['password'], PASSWORD_DEFAULT);

        try {
            /** @var array{user: array<string, mixed>, token: string} $result */
            $result = Database::transaction(function () use ($email, $displayName, $passwordHash, $ip, $userAgent) {
                $userId = $this->users->create($email, $passwordHash, $displayName);
                $token = $this->issueSession($userId, $ip, $userAgent);
                $row = $this->users->find($userId);

                if ($row === null) {
                    throw new \RuntimeException('User row disappeared after creation');
                }

                return ['user' => User::fromRow($row)->profile(), 'token' => $token];
            });
        } catch (\PDOException $exception) {
            // Race: the pre-check passed but a concurrent request inserted the
            // same email first — the UNIQUE constraint decided, answer like the
            // pre-check would (Prompt 24: never leak a 500 for a known case).
            if ((string) $exception->getCode() === '23000') {
                throw ValidationException::withErrors([
                    ['field' => 'email', 'message' => 'Email is already registered'],
                ]);
            }
            throw $exception;
        }

        return $result;
    }

    /**
     * Verifies credentials and issues a fresh session.
     * The caller's previous session (if any) is discarded first — fixation defense.
     *
     * @param array<string, mixed> $data sanitized input
     * @return array{user: array<string, mixed>, token: string}
     */
    public function login(array $data, ?string $ip = null, ?string $userAgent = null, ?string $replacedToken = null): array
    {
        if ($replacedToken !== null && preg_match('/^[a-f0-9]{64}$/', $replacedToken) === 1) {
            $this->sessions->deleteByTokenHash(hash('sha256', $replacedToken));
        }

        $email = strtolower(trim((string) $data['email']));
        $password = (string) $data['password'];

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);
            $this->delay();
            throw self::invalidCredentials();
        }

        $auth = $this->users->findAuth((int) $user['id']);
        if ($auth === null) {
            password_verify($password, self::DUMMY_HASH);
            $this->delay();
            throw self::invalidCredentials();
        }

        // locked_until is written with gmdate() (UTC) and compared against
        // time() — so it must be parsed as UTC as well (Prompt 24: parsing
        // in the server's local timezone skews the lock window).
        $lockedUntil = $auth['locked_until'] !== null ? (string) $auth['locked_until'] : null;
        if ($lockedUntil !== null) {
            if (strtotime($lockedUntil . ' UTC') > time()) {
                $this->delay();
                throw new HttpException(429, 'Too Many Requests', [
                    ['message' => 'Too many failed attempts. Please try again later.'],
                ]);
            }
            // The lock expired: start a fresh attempt window — otherwise a
            // single mistake right after waiting would lock again instantly.
            $this->users->clearLockout((int) $user['id']);
            $auth['failed_login_count'] = 0;
        }

        if (!password_verify($password, (string) $auth['password_hash'])) {
            $failed = (int) $auth['failed_login_count'] + 1;
            $lock = null;
            if ($failed >= (int) Config::get('auth', 'max_failed_attempts', 5)) {
                $lock = gmdate(
                    'Y-m-d H:i:s',
                    time() + ((int) Config::get('auth', 'lockout_minutes', 15)) * 60
                );
            }
            $this->users->recordFailedLogin((int) $user['id'], $failed, $lock);
            $this->delay();
            throw self::invalidCredentials();
        }

        if ($user['status'] !== 'active') {
            $this->delay();
            throw self::invalidCredentials();
        }

        if (password_needs_rehash((string) $auth['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $this->users->recordSuccessfulLogin((int) $user['id']);
        $token = $this->issueSession((int) $user['id'], $ip, $userAgent);

        $row = $this->users->find((int) $user['id']);
        if ($row === null) {
            throw self::invalidCredentials();
        }

        return ['user' => User::fromRow($row)->profile(), 'token' => $token];
    }

    /** Resolves a raw session token to an active user, or null. */
    public function authenticate(?string $token): ?User
    {
        if ($token === null || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $row = $this->sessions->findActiveUserByTokenHash($tokenHash);
        if ($row === null) {
            return null;
        }

        $this->sessions->touch($tokenHash);

        return User::fromRow($row);
    }

    public function logout(?string $token): void
    {
        if ($token !== null && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            $this->sessions->deleteByTokenHash(hash('sha256', $token));
        }
    }

    /**
     * Own sessions for the account menu. Never includes token material.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $userId, ?string $currentToken = null): array
    {
        $currentId = null;
        if ($currentToken !== null && preg_match('/^[a-f0-9]{64}$/', $currentToken) === 1) {
            $currentId = $this->sessions->findIdByTokenHash(hash('sha256', $currentToken));
        }

        $rows = $this->sessions->listForUser($userId);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'ip_address' => $row['ip_address'],
                'user_agent' => $row['user_agent'],
                'last_seen_at' => $row['last_seen_at'],
                'expires_at' => $row['expires_at'],
                'created_at' => $row['created_at'],
                'is_current' => $currentId !== null && $currentId === (int) $row['id'],
            ];
        }

        return $out;
    }

    /** Revokes one of the user's sessions. Returns false when it isn't theirs (or doesn't exist). */
    public function revokeSession(int $userId, int $sessionId): bool
    {
        return $this->sessions->deleteForUser($userId, $sessionId) === 1;
    }

    /**
     * Updates the signed-in profile (Prompt 18): display name and/or email.
     * An email change re-checks uniqueness; both fields are optional —
     * omitted/null values keep the current row value.
     *
     * @param array<string, mixed> $data sanitized input
     * @return array<string, mixed> refreshed profile
     */
    public function updateProfile(int $userId, array $data): array
    {
        $current = $this->users->find($userId);
        if ($current === null) {
            throw new NotFoundException('User not found');
        }

        $email = $data['email'] ?? null;
        if ($email !== null && trim((string) $email) === '') {
            // Explicitly-empty email bypassed the email rule (empty input) - reject it.
            throw ValidationException::withErrors([
                ['field' => 'email', 'message' => 'Email must be a valid email address'],
            ]);
        }

        $newEmail = $email === null ? (string) $current['email'] : strtolower(trim((string) $email));
        $newName = ($data['display_name'] ?? null) === null
            ? (string) $current['display_name']
            : trim((string) $data['display_name']);

        if ($newEmail !== strtolower((string) $current['email'])) {
            $existing = $this->users->findByEmail($newEmail);
            if ($existing !== null) {
                throw ValidationException::withErrors([
                    ['field' => 'email', 'message' => 'Email is already registered'],
                ]);
            }
        }

        try {
            $this->users->updateProfile($userId, $newEmail, $newName);
        } catch (\PDOException $exception) {
            // Same race as register: another user took the email between the
            // pre-check and the UPDATE — answer 422, not 500.
            if ((string) $exception->getCode() === '23000') {
                throw ValidationException::withErrors([
                    ['field' => 'email', 'message' => 'Email is already registered'],
                ]);
            }
            throw $exception;
        }

        $row = $this->users->find($userId);
        if ($row === null) {
            throw new NotFoundException('User not found');
        }

        return User::fromRow($row)->profile();
    }

    /**
     * Changes the password (Prompt 18): verifies the current one, applies
     * the shared 8–72 policy, then revokes EVERY session — the account
     * signs in again on all devices (same posture as resetPassword).
     *
     * @param array<string, mixed> $data sanitized input
     */
    public function changePassword(int $userId, array $data): void
    {
        $auth = $this->users->findAuth($userId);
        if ($auth === null) {
            throw new NotFoundException('User not found');
        }

        $current = (string) $data['current_password'];
        $new = (string) $data['new_password'];

        if (!password_verify($current, (string) $auth['password_hash'])) {
            $this->delay();
            throw ValidationException::withErrors([
                ['field' => 'current_password', 'message' => 'Current password is incorrect'],
            ]);
        }

        if (hash_equals($current, $new)) {
            throw ValidationException::withErrors([
                ['field' => 'new_password', 'message' => 'New password must differ from the current one'],
            ]);
        }

        $this->users->updatePassword($userId, password_hash($new, PASSWORD_DEFAULT));
        $this->sessions->deleteAllForUser($userId);
    }

    private function issueSession(int $userId, ?string $ip, ?string $userAgent): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $lifetimeMinutes = (int) Config::get('app', 'session.lifetime', 120);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $lifetimeMinutes * 60);

        $this->sessions->create($userId, hash('sha256', $token), $expiresAt, $ip, $userAgent);

        return $token;
    }

    private function delay(): void
    {
        $microseconds = (int) Config::get('auth', 'failure_delay_microseconds', 200000);
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    private static function invalidCredentials(): HttpException
    {
        // Identical message whether the email exists or the password is wrong.
        return new HttpException(401, 'Invalid credentials', [
            ['message' => 'Invalid email or password'],
        ]);
    }
}
