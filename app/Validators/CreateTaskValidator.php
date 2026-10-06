<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for creating a daily task (Prompt 14). Type existence
 * and the general-category title rule are checked in TaskService —
 * input rules stay declarative here.
 */
final class CreateTaskValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'task_type_id' => 'required|integer|min:1',
            'title' => 'string|maxLength:150',
            'duration_minutes' => 'integer|min:1|max:1440',
            'scheduled_date' => 'date',
            'notes' => 'string|maxLength:255',
        ];
    }

    protected function attributes(): array
    {
        return [
            'task_type_id' => 'Task type',
            'title' => 'Title',
            'duration_minutes' => 'Duration',
            'scheduled_date' => 'Scheduled date',
            'notes' => 'Notes',
        ];
    }
}
