<?php

declare(strict_types=1);

namespace App\Validators;

/** Registration payload rules. */
final class RegisterValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'email' => 'required|email|maxLength:190',
            'password' => 'required|string|minLength:8|maxLength:72',
            'display_name' => 'string|maxLength:100',
        ];
    }

    protected function attributes(): array
    {
        return [
            'email' => 'Email',
            'password' => 'Password',
            'display_name' => 'Display name',
        ];
    }
}
