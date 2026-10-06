<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Canonical Quran division types (أنواع التقسيم) the engine understands.
 * Values match quran_division_types.type_key; a new verified division is
 * added here AND in the registry (never engine literals).
 */
enum DivisionType: string
{
    case Juz = 'juz';
    case Hizb = 'hizb';
    case Rub = 'rub';
}
