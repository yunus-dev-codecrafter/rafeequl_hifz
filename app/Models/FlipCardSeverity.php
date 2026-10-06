<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Severity of a flagged error — mirrors flip_cards.severity (0007).
 * The client may set it when flagging; omission falls back to the
 * column default (medium).
 */
enum FlipCardSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
