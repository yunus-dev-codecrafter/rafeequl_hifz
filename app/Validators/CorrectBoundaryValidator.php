<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for correcting the memorization boundary.
 * `confirm` must be explicitly true — the service rejects a missing or
 * false value with a 422 on this field.
 */
final class CorrectBoundaryValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'current_boundary_page' => 'required|integer|min:1',
            'confirm' => 'boolean',
            'note' => 'string|maxLength:255',
        ];
    }

    protected function attributes(): array
    {
        return [
            'current_boundary_page' => 'Current boundary page',
            'confirm' => 'Confirmation',
            'note' => 'Note',
        ];
    }
}
