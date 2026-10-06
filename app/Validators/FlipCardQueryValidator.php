<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Query rules for listing/queueing flip cards (Prompt 13): optional
 * limit and filters, absent values stay null (defaults apply later).
 */
final class FlipCardQueryValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'limit' => 'integer|min:1|max:100',
            'status' => 'string|in:active,in_review,mastered,archived',
            'category_id' => 'integer|min:1',
        ];
    }

    protected function attributes(): array
    {
        return [
            'limit' => 'Limit',
            'status' => 'Status',
            'category_id' => 'Category',
        ];
    }
}
