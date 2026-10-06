<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Input failed validation → HTTP 422 with per-field errors (conventions §13).
 * Raised by App\Validators\Validator; never caught inside services.
 */
class ValidationException extends HttpException
{
    /** @param array<int, array<string, mixed>|string> $errors */
    private function __construct(array $errors)
    {
        parent::__construct(422, 'Validation failed', $errors);
    }

    /** @param array<int, array<string, mixed>|string> $errors */
    public static function withErrors(array $errors): self
    {
        return new self($errors);
    }
}
