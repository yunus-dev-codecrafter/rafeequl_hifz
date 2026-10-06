<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Segment lifecycle — mirrors revision_segments.status (0005_revision).
 * Documented state machine: pending → active → completed | skipped.
 */
enum RevisionSegmentStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Skipped = 'skipped';

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Skipped;
    }
}
