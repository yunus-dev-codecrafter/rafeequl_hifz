<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for editing a daily task (Prompt 14): every field is
 * optional — omitted/empty values keep the current value, and the
 * scheduled date is never editable.
 */
final class UpdateTaskValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'task_type_id' => 'integer|min:1',
            'title' => 'string|maxLength:150',
            'duration_minutes' => 'integer|min:1|max:1440',
            'notes' => 'string|maxLength:255',
        ];
    }

    protected function attributes(): array
    {
        return [
            'task_type_id' => 'Task type',
            'title' => 'Title',
            'duration_minutes' => 'Duration',
            'notes' => 'Notes',
        ];
    }
}
