<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Why a memorization boundary moved — mirrors
 * memorization_boundary_history.reason (0004_hifz).
 */
enum BoundaryChangeReason: string
{
    case Manual = 'manual';
    case Recalc = 'recalc';
    case Restart = 'restart';
}
