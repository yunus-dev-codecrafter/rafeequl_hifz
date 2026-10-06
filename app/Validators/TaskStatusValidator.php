<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for a daily task status transition (Prompt 14).
 * The completion fields are only read when status becomes "completed".
 */
final class TaskStatusValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'status' => 'required|string|in:pending,active,completed,skipped',
            'actual_duration_seconds' => 'integer|min:0|max:86400',
            'note' => 'string|maxLength:255',
        ];
    }

    protected function attributes(): array
    {
        return [
            'status' => 'Status',
            'actual_duration_seconds' => 'Actual duration',
            'note' => 'Note',
        ];
    }
}
