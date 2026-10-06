<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for updating user settings (Prompt 18): every field is
 * optional — omitted fields keep their current value. The revision
 * defaults reuse the plan-target bounds (CreatePlanValidator) so both
 * entry points accept exactly the same amounts.
 */
final class UpdateSettingsValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'theme' => 'string|in:auto,light,dark',
            'sound_enabled' => 'boolean',
            'screen_awake_enabled' => 'boolean',
            'locale' => 'string|in:ar,en',
            'daily_revision_unit' => 'string|in:page,hizb,rub,juz',
            'daily_revision_amount' => 'numeric|min:0.1|max:9999',
        ];
    }

    protected function attributes(): array
    {
        return [
            'theme' => 'Theme',
            'sound_enabled' => 'Sound',
            'screen_awake_enabled' => 'Screen awake',
            'locale' => 'Language',
            'daily_revision_unit' => 'Revision unit',
            'daily_revision_amount' => 'Revision amount',
        ];
    }
}
