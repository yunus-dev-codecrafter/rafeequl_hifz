<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Flip card lifecycle — mirrors flip_cards.status (0007, extended by
 * 0010). Documented state machine: active → in_review → mastered
 * (| archived); mastered/archived leave the review queue, every other
 * cross-state move is allowed, same-state is an idempotent no-op.
 */
enum FlipCardStatus: string
{
    case Active = 'active';
    case InReview = 'in_review';
    case Mastered = 'mastered';
    case Archived = 'archived';

    /** Whether the card sits in the active review queue. */
    public function inQueue(): bool
    {
        return $this === self::Active || $this === self::InReview;
    }
}
