<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Daily task lifecycle — mirrors daily_tasks.status (0008).
 * Documented state machine: pending ⇄ active, pending/active → skipped
 * (skippable back), active/pending → completed; skipped must be reopened
 * before it can be completed (Prompt 24 — a skipped task never accrues a
 * completion record directly); completed is terminal (its single
 * task_completions row is append-only history).
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Skipped = 'skipped';

    /**
     * Whether a task in this state may move to $target. Same state is an
     * idempotent no-op (false here, handled by callers); completed is final;
     * skipped → completed is not a direct transition.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === self::Completed || $target === $this) {
            return false;
        }

        return !($this === self::Skipped && $target === self::Completed);
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }
}
