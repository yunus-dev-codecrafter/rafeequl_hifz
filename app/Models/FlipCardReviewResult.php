<?php

declare(strict_types=1);

namespace App\Models;

/** Outcome of one flip card review — mirrors flip_card_reviews.result (0007). */
enum FlipCardReviewResult: string
{
    case Recalled = 'recalled';
    case Partial = 'partial';
    case Forgotten = 'forgotten';
}
