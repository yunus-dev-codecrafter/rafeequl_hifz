<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for one flip card review (Prompt 13). */
final class FlipCardReviewValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'result' => 'required|string|in:recalled,partial,forgotten',
            'duration_seconds' => 'integer|min:0|max:86400',
            'notes' => 'string|maxLength:255',
        ];
    }

    protected function attributes(): array
    {
        return [
            'result' => 'Result',
            'duration_seconds' => 'Duration',
            'notes' => 'Notes',
        ];
    }
}
