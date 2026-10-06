<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for editing the signed-in profile (Prompt 18): both
 * fields are optional — omitted fields keep the current value. An
 * explicitly empty email is rejected (Prompt 24): it must never be
 * interpreted as "not provided" nor stored.
 */
final class UpdateProfileValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'display_name' => 'string|maxLength:100',
            'email' => 'email|maxLength:190',
        ];
    }

    protected function rejectEmpty(): array
    {
        return ['email'];
    }

    protected function messages(): array
    {
        return [
            // Present-but-empty email: full sentence (custom messages replace
            // the default "label + rule text" composition), matching the
            // service-level rejection for a cleared email.
            'email.required' => 'Email must be a valid email address',
        ];
    }

    protected function attributes(): array
    {
        return [
            'display_name' => 'Display name',
            'email' => 'Email',
        ];
    }
}
