<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for changing a segment status (explicit skip). */
final class SegmentStatusValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'status' => 'required|string|in:skipped',
        ];
    }

    protected function attributes(): array
    {
        return [
            'status' => 'Status',
        ];
    }
}
