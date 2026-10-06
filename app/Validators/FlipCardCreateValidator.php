<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Payload rules for flagging a memorization error (Prompt 13).
 * Quran location and category existence are checked against the
 * canonical dataset in FlipCardService — input rules stay declarative.
 */
final class FlipCardCreateValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'surah_number' => 'required|integer|min:1',
            'ayah_number' => 'required|integer|min:1',
            'page_number' => 'required|integer|min:1',
            'category_id' => 'required|integer|min:1',
            'error_note' => 'required|string|minLength:1|maxLength:500',
            'context_note' => 'string|maxLength:500',
            'severity' => 'string|in:low,medium,high',
        ];
    }

    protected function attributes(): array
    {
        return [
            'surah_number' => 'Surah',
            'ayah_number' => 'Ayah',
            'page_number' => 'Page',
            'category_id' => 'Category',
            'error_note' => 'Error note',
            'context_note' => 'Context note',
            'severity' => 'Severity',
        ];
    }
}
