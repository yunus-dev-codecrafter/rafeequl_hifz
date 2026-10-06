<?php

declare(strict_types=1);

namespace App\Models;

/** Task type grouping — mirrors task_types.category (0008): Quran vs personal. */
enum TaskCategory: string
{
    case Quran = 'quran';
    case General = 'general';

    public function isGeneral(): bool
    {
        return $this === self::General;
    }
}
