<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for a flip card status transition (Prompt 13). */
final class FlipCardStatusValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'status' => 'required|string|in:active,in_review,mastered,archived',
        ];
    }

    protected function attributes(): array
    {
        return [
            'status' => 'Status',
        ];
    }
}
