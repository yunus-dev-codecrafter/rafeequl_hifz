<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Helpers\Config;
use App\Models\User;
use App\Request;
use App\Response;
use App\Validators\Validator;

/**
 * Base controller: owns HTTP concerns only (conventions §9).
 *
 * Business logic lives in Services; queries live in Repositories;
 * input validation lives in App\Validators\Validator subclasses.
 */
abstract class Controller
{
    public function __construct(protected readonly Request $request)
    {
    }

    protected function request(): Request
    {
        return $this->request;
    }

    protected function input(?string $key = null, mixed $default = null): mixed
    {
        return $this->request->input($key, $default);
    }

    protected function query(?string $key = null, mixed $default = null): mixed
    {
        return $this->request->query($key, $default);
    }

    protected function param(string $name, ?string $default = null): ?string
    {
        return $this->request->param($name, $default);
    }

    /** Route id params must be positive integers (422 otherwise). */
    protected function routeId(array $params, string $name): int
    {
        $raw = $params[$name] ?? '';
        if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1) {
            throw ValidationException::withErrors([
                [
                    'field' => $name,
                    'message' => ucwords(str_replace('_', ' ', $name)) . ' must be a positive integer',
                ],
            ]);
        }
        return (int) $raw;
    }

    /** Authenticated user attached by AuthMiddleware (null on public routes). */
    protected function authUser(): ?User
    {
        $user = $this->request->attribute('user');
        return $user instanceof User ? $user : null;
    }

    /** Authenticated user on protected routes (LogicException when absent). */
    protected function requireUser(): User
    {
        $user = $this->authUser();
        if ($user === null) {
            throw new \LogicException('AuthMiddleware must attach the user');
        }
        return $user;
    }

    protected function sessionToken(): ?string
    {
        $token = $this->request->attribute('session_token');
        return is_string($token) ? $token : null;
    }

    protected function sessionCookieName(): string
    {
        return (string) Config::get('app', 'session.name', 'rafeequl_hifz_session');
    }

    protected function sessionCookieTtl(): int
    {
        return ((int) Config::get('app', 'session.lifetime', 120)) * 60;
    }

    protected function success(mixed $data = null, int $status = 200): Response
    {
        return Response::ok($data, $status);
    }

    /** @param array<int, array<string, mixed>|string>|string $errors */
    protected function failure(int $status, array|string $errors): Response
    {
        return Response::failure(is_string($errors) ? [$errors] : $errors, $status);
    }

    /**
     * Runs a validator against the request body (or a given input array).
     * Throws ValidationException (422) on failure.
     *
     * @return array<string, mixed> sanitized values
     */
    protected function validate(Validator $validator, ?array $input = null): array
    {
        return $validator->validate($input ?? $this->request->input());
    }
}
