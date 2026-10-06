<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for finalizing a revision session. */
final class FinishSessionValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'status' => 'required|string|in:completed,partial,interrupted',
            'last_page_reached' => 'integer|min:1',
            'interruption_reason' => 'string|maxLength:190',
            'notes' => 'string|maxLength:255',
        ];
    }

    protected function attributes(): array
    {
        return [
            'status' => 'Status',
            'last_page_reached' => 'Last page reached',
            'interruption_reason' => 'Interruption reason',
            'notes' => 'Notes',
        ];
    }
}
