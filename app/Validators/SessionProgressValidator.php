<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for reporting live progress in a running session. */
final class SessionProgressValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'last_page_reached' => 'required|integer|min:1',
        ];
    }

    protected function attributes(): array
    {
        return [
            'last_page_reached' => 'Last page reached',
        ];
    }
}
