<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Session outcome — mirrors revision_sessions.status (0005_revision).
 *
 * There is no "started" enum value: a session is inserted in progress
 * (status Partial with ended_at NULL) and finalized exactly once — rows
 * with ended_at set are history and never change again.
 */
enum RevisionSessionStatus: string
{
    case Completed = 'completed';
    case Partial = 'partial';
    case Interrupted = 'interrupted';

    /** Resumable outcomes: the segment still has work left. */
    public function canResume(): bool
    {
        return $this === self::Interrupted || $this === self::Partial;
    }
}
