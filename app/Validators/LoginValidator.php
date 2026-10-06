<?php

declare(strict_types=1);

namespace App\Validators;

/** Login payload rules. */
final class LoginValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'email' => 'required|email|maxLength:190',
            'password' => 'required|string|maxLength:255',
        ];
    }

    protected function attributes(): array
    {
        return [
            'email' => 'Email',
            'password' => 'Password',
        ];
    }
}
