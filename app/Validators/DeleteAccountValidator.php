<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for account deletion (Prompt 18): the current password
 * must be re-supplied before anything is removed.
 */
final class DeleteAccountValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'password' => 'required|string|minLength:8|maxLength:72',
        ];
    }

    protected function attributes(): array
    {
        return [
            'password' => 'Password',
        ];
    }

    protected function messages(): array
    {
        return [
            'password.required' => 'Password is required to delete the account',
        ];
    }
}
