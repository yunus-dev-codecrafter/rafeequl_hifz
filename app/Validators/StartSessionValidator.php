<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for opening (or resuming) a revision session. */
final class StartSessionValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'segment_id' => 'required|integer|min:1',
            'resumes_session_id' => 'integer|min:1',
        ];
    }

    protected function attributes(): array
    {
        return [
            'segment_id' => 'Segment',
            'resumes_session_id' => 'Resumed session',
        ];
    }
}
