<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for creating a revision plan. Both fields are optional —
 * omitted values fall back to the user's saved revision defaults.
 */
final class CreatePlanValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'target_unit' => 'string|in:page,hizb,rub,juz',
            'daily_amount' => 'numeric|min:0.1|max:9999',
            'name' => 'string|maxLength:120',
        ];
    }

    protected function attributes(): array
    {
        return [
            'target_unit' => 'Target unit',
            'daily_amount' => 'Daily amount',
            'name' => 'Plan name',
        ];
    }
}
