<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Where a memorization_history row came from — mirrors
 * memorization_history.source (0004_hifz). Only manual marking exists
 * today; 'import' is reserved for the dataset/import prompt.
 */
enum MemorizationSource: string
{
    case Manual = 'manual';
    case Import = 'import';
}
