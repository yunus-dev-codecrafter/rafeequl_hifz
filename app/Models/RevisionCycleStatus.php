<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Cycle lifecycle — mirrors revision_cycles.status (0005_revision).
 * Documented state machine: pending → active → completed;
 * superseded = replaced by a regenerated cycle (rows are never destroyed).
 */
enum RevisionCycleStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Superseded = 'superseded';
}
