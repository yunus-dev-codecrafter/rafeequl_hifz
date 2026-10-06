<?php

declare(strict_types=1);

namespace App\Validators;

/** "Forgot password" payload rules. */
final class ForgotPasswordValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'email' => 'required|email|maxLength:190',
        ];
    }

    protected function attributes(): array
    {
        return [
            'email' => 'Email',
        ];
    }
}
