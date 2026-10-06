<?php

declare(strict_types=1);

namespace App\Validators;

/** Password-reset payload rules (token is a 64-char hex string). */
final class ResetPasswordValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'token' => 'required|string|minLength:64|maxLength:64',
            'password' => 'required|string|minLength:8|maxLength:72',
        ];
    }

    protected function attributes(): array
    {
        return [
            'token' => 'Reset token',
            'password' => 'New password',
        ];
    }

    protected function messages(): array
    {
        return [
            'token.minLength' => 'Reset token is invalid or expired',
            'token.maxLength' => 'Reset token is invalid or expired',
        ];
    }
}
