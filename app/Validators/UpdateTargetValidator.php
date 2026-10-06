<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for changing a plan's daily revision target. */
final class UpdateTargetValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'target_unit' => 'required|string|in:page,hizb,rub,juz',
            'daily_amount' => 'required|numeric|min:0.1|max:9999',
        ];
    }

    protected function attributes(): array
    {
        return [
            'target_unit' => 'Target unit',
            'daily_amount' => 'Daily amount',
        ];
    }
}
