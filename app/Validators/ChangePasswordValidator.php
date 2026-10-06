<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for changing the password (Prompt 18). Bounds mirror
 * RegisterValidator so the policy stays identical everywhere.
 */
final class ChangePasswordValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'current_password' => 'required|string|minLength:8|maxLength:72',
            'new_password' => 'required|string|minLength:8|maxLength:72',
        ];
    }

    protected function attributes(): array
    {
        return [
            'current_password' => 'Current password',
            'new_password' => 'New password',
        ];
    }

    protected function messages(): array
    {
        return [
            'current_password.required' => 'Current password is required',
            'new_password.required' => 'New password is required',
        ];
    }
}
