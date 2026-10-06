<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for generating the next revision cycle. */
final class GenerateCycleValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'regenerate' => 'boolean',
        ];
    }

    protected function attributes(): array
    {
        return [
            'regenerate' => 'Regenerate',
        ];
    }
}
