<?php

declare(strict_types=1);

namespace App\Validators;

/** Payload rules for establishing the memorized range + boundary. */
final class EstablishStateValidator extends Validator
{
    protected function rules(): array
    {
        return [
            'memorized_start_page' => 'required|integer|min:1',
            'current_boundary_page' => 'required|integer|min:1',
            'note' => 'string|maxLength:255',
        ];
    }

    protected function attributes(): array
    {
        return [
            'memorized_start_page' => 'Memorized start page',
            'current_boundary_page' => 'Current boundary page',
            'note' => 'Note',
        ];
    }
}
