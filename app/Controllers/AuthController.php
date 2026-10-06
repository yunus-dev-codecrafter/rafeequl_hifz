<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Request;
use App\Response;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Validators\ChangePasswordValidator;
use App\Validators\ForgotPasswordValidator;
use App\Validators\LoginValidator;
use App\Validators\RegisterValidator;
use App\Validators\ResetPasswordValidator;
use App\Validators\UpdateProfileValidator;

/**
 * Authentication endpoints. Cookies are attached here (HTTP concern);
 * credential/session rules live in the services.
 */
final class AuthController extends Controller
{
    private AuthService $auth;
    private PasswordResetService $passwordReset;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->auth = new AuthService();
        $this->passwordReset = new PasswordResetService();
    }

    public function register(array $params = []): Response
    {
        $data = $this->validate(new RegisterValidator());

        $result = $this->auth->register($data, $this->request->ip(), $this->request->header('User-Agent'));

        return $this
            ->success(['user' => $result['user']], 201)
            ->withCookie($this->sessionCookieName(), $result['token'], $this->sessionCookieTtl());
    }

    public function login(array $params = []): Response
    {
        $data = $this->validate(new LoginValidator());

        $result = $this->auth->login(
            $data,
            $this->request->ip(),
            $this->request->header('User-Agent'),
            $this->sessionToken()
        );

        return $this
            ->success(['user' => $result['user']])
            ->withCookie($this->sessionCookieName(), $result['token'], $this->sessionCookieTtl());
    }

    public function logout(array $params = []): Response
    {
        $this->auth->logout($this->sessionToken());

        return $this->success(['message' => 'Logged out'])
            ->withoutCookie($this->sessionCookieName());
    }

    /** Returns ONLY the authenticated user's own profile. */
    public function me(array $params = []): Response
    {
        $user = $this->authUser();
        if ($user === null) {
            throw new \LogicException('AuthMiddleware must attach the user');
        }

        return $this->success(['user' => $user->profile()]);
    }

    /** Updates the signed-in user's display name and/or email (Prompt 18). */
    public function updateProfile(array $params = []): Response
    {
        $user = $this->requireUser();
        $data = $this->validate(new UpdateProfileValidator());

        return $this->success(['user' => $this->auth->updateProfile($user->id(), $data)]);
    }

    /**
     * Changes the password (Prompt 18). Every session is revoked —
     * this browser gets its cookie cleared and must sign in again.
     */
    public function changePassword(array $params = []): Response
    {
        $user = $this->requireUser();
        $data = $this->validate(new ChangePasswordValidator());

        $this->auth->changePassword($user->id(), $data);

        return $this->success(['message' => 'Password updated. Please sign in again.'])
            ->withoutCookie($this->sessionCookieName());
    }

    /** Lists only the authenticated user's own sessions (no token material). */
    public function sessions(array $params = []): Response
    {
        $user = $this->authUser();
        if ($user === null) {
            throw new \LogicException('AuthMiddleware must attach the user');
        }

        return $this->success([
            'sessions' => $this->auth->listSessions($user->id(), $this->sessionToken()),
        ]);
    }

    /**
     * Revokes a session by id — ownership is enforced in the query.
     * Another user's session id yields 404 (existence is not revealed).
     */
    public function revokeSession(array $params = []): Response
    {
        $user = $this->authUser();
        if ($user === null) {
            throw new \LogicException('AuthMiddleware must attach the user');
        }

        $sessionId = $params['session_id'] ?? '';
        if (!ctype_digit($sessionId) || (int) $sessionId < 1) {
            throw ValidationException::withErrors([
                ['field' => 'session_id', 'message' => 'Session id must be a positive integer'],
            ]);
        }

        if (!$this->auth->revokeSession($user->id(), (int) $sessionId)) {
            throw new \App\Exceptions\NotFoundException('Session not found');
        }

        $response = $this->success(['message' => 'Session revoked']);

        // Revoking your own current session logs you out.
        if ($this->sessionToken() !== null
            && $this->auth->authenticate($this->sessionToken()) === null
        ) {
            $response = $response->withoutCookie($this->sessionCookieName());
        }

        return $response;
    }

    /** Generic response — identical whether or not the email exists. */
    public function forgotPassword(array $params = []): Response
    {
        $data = $this->validate(new ForgotPasswordValidator());

        $this->passwordReset->request((string) $data['email'], $this->request->ip());

        return $this->success(['message' => 'If that email is registered, a reset link has been sent']);
    }

    public function resetPassword(array $params = []): Response
    {
        $data = $this->validate(new ResetPasswordValidator());

        $this->passwordReset->reset((string) $data['token'], (string) $data['password']);

        // Every session was revoked — make sure this browser drops its cookie too.
        return $this->success(['message' => 'Password updated. Please sign in again.'])
            ->withoutCookie($this->sessionCookieName());
    }
}
