<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Plan lifecycle — mirrors revision_plans.status (0005_revision).
 * Documented state machine: active ⇄ paused → completed.
 */
enum RevisionPlanStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';

    public function acceptsWork(): bool
    {
        return $this === self::Active;
    }
}
