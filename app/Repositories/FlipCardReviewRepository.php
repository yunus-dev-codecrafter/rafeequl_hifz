<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Reads/writes for flip_card_reviews (0007): append-only review history
 * for flip cards. Rows survive every status change and are only removed
 * when the card itself is explicitly deleted (FK cascade).
 */
final class FlipCardReviewRepository extends Repository
{
    /** Appends one review event. Returns the new review id. */
    public function create(
        int $cardId,
        int $userId,
        string $result,
        ?int $durationSeconds,
        ?string $notes,
    ): int {
        return $this->insert(
            'INSERT INTO flip_card_reviews
                    (card_id, user_id, reviewed_at, result, duration_seconds, notes, created_at)
             VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, ?, UTC_TIMESTAMP())',
            [$cardId, $userId, $result, $durationSeconds, $notes]
        );
    }

    /**
     * All reviews on a card, newest first, scoped to the owner.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCard(int $userId, int $cardId): array
    {
        return $this->fetchAll(
            'SELECT id, result, duration_seconds, notes, reviewed_at
               FROM flip_card_reviews
              WHERE card_id = ? AND user_id = ?
              ORDER BY id DESC',
            [$cardId, $userId]
        );
    }
}
