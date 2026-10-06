<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for explicitly marking a page range as memorized. */
final class MarkMemorizedValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'start_page' => 'required|integer|min:1',
            'end_page' => 'required|integer|min:1',
            'note' => 'string|maxLength:255',
        ];
    }

    protected function attributes(): array
    {
        return [
            'start_page' => 'Start page',
            'end_page' => 'End page',
            'note' => 'Note',
        ];
    }
}
