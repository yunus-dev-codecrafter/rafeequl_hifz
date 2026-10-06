<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Query rules for task history (Prompt 14): an inclusive date range;
 * from <= to is enforced in TaskService.
 */
final class TaskHistoryValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'from' => 'required|date',
            'to' => 'required|date',
            'limit' => 'integer|min:1|max:100',
        ];
    }

    protected function attributes(): array
    {
        return [
            'from' => 'From',
            'to' => 'To',
            'limit' => 'Limit',
        ];
    }
}
