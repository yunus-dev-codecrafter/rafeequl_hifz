<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for a plan status transition (active/paused/completed). */
final class PlanStatusValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'status' => 'required|string|in:active,paused,completed',
        ];
    }

    protected function attributes(): array
    {
        return [
            'status' => 'Status',
        ];
    }
}
