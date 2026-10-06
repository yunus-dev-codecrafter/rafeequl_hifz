<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Query rules for the day view (Prompt 14): an optional scheduled date;
 * absent means "today" (decided server-side in TaskService).
 */
final class TaskDayQueryValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'date' => 'date',
        ];
    }

    protected function attributes(): array
    {
        return [
            'date' => 'Date',
        ];
    }
}
