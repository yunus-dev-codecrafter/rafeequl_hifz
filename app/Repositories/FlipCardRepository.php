<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Reads/writes for flip_cards (0007, extended by 0010) and the seeded
 * flip_card_categories vocabulary: the user's flagged memorization
 * errors. Review history lives in FlipCardReviewRepository.
 */
final class FlipCardRepository extends Repository
{
    /**
     * Card joined with its category, scoped to the owner.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $cardId): ?array
    {
        return $this->fetch(
            'SELECT c.id, c.user_id, c.category_id, c.surah_number, c.ayah_number, c.page_number,
                    c.error_note, c.context_note, c.severity, c.status,
                    c.review_count, c.last_reviewed_at, c.next_review_at, c.mastered_at,
                    c.created_at, c.updated_at,
                    k.slug AS category_slug, k.name_en AS category_name_en, k.name_ar AS category_name_ar
               FROM flip_cards c
               JOIN flip_card_categories k ON k.id = c.category_id
              WHERE c.id = ? AND c.user_id = ?',
            [$cardId, $userId]
        );
    }

    /**
     * The user's cards, newest first, with optional status/category
     * filters (already validated upstream).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, ?string $status, ?int $categoryId, int $limit): array
    {
        $sql = 'SELECT c.id, c.user_id, c.category_id, c.surah_number, c.ayah_number, c.page_number,
                       c.error_note, c.context_note, c.severity, c.status,
                       c.review_count, c.last_reviewed_at, c.next_review_at, c.mastered_at,
                       c.created_at, c.updated_at,
                       k.slug AS category_slug, k.name_en AS category_name_en, k.name_ar AS category_name_ar
                  FROM flip_cards c
                  JOIN flip_card_categories k ON k.id = c.category_id
                 WHERE c.user_id = ?';
        $params = [$userId];

        if ($status !== null) {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }
        if ($categoryId !== null) {
            $sql .= ' AND c.category_id = ?';
            $params[] = $categoryId;
        }

        $sql .= ' ORDER BY c.id DESC LIMIT ?';
        $params[] = $limit;

        return $this->fetchAll($sql, $params);
    }

    /**
     * The active review queue: active/in_review cards ordered by
     * least-recently reviewed first (never-reviewed first), then oldest.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queueForUser(int $userId, int $limit): array
    {
        return $this->fetchAll(
            "SELECT c.id, c.user_id, c.category_id, c.surah_number, c.ayah_number, c.page_number,
                    c.error_note, c.context_note, c.severity, c.status,
                    c.review_count, c.last_reviewed_at, c.next_review_at, c.mastered_at,
                    c.created_at, c.updated_at,
                    k.slug AS category_slug, k.name_en AS category_name_en, k.name_ar AS category_name_ar
               FROM flip_cards c
               JOIN flip_card_categories k ON k.id = c.category_id
              WHERE c.user_id = ? AND c.status IN ('active', 'in_review')
              ORDER BY (c.last_reviewed_at IS NULL) DESC, c.last_reviewed_at ASC, c.id ASC
              LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Inserts a newly flagged card (status active, review_count 0).
     * Returns the new card id.
     */
    public function create(
        int $userId,
        int $categoryId,
        int $surahNumber,
        int $ayahNumber,
        int $pageNumber,
        string $errorNote,
        ?string $contextNote,
        string $severity,
    ): int {
        return $this->insert(
            'INSERT INTO flip_cards
                    (user_id, category_id, surah_number, ayah_number, page_number,
                     error_note, context_note, severity, status, review_count,
                     last_reviewed_at, next_review_at, mastered_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'active\', 0, NULL, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$userId, $categoryId, $surahNumber, $ayahNumber, $pageNumber, $errorNote, $contextNote, $severity]
        );
    }

    /**
     * Review tracking on the card row: bumps the counter, stamps
     * last_reviewed_at, and promotes a still-active card to in_review
     * in the same write. Returns the affected row count (0 if missing).
     */
    public function recordReview(int $userId, int $cardId): int
    {
        $statement = $this->run(
            "UPDATE flip_cards
                SET review_count = review_count + 1,
                    last_reviewed_at = UTC_TIMESTAMP(),
                    status = CASE WHEN status = 'active' THEN 'in_review' ELSE status END,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND user_id = ?",
            [$cardId, $userId]
        );
        return $statement->rowCount();
    }

    /**
     * Explicit status transition; entering mastered stamps mastered_at
     * (kept afterwards as history). Returns the affected row count.
     */
    public function updateStatus(int $userId, int $cardId, string $status): int
    {
        $statement = $this->run(
            "UPDATE flip_cards
                SET status = ?,
                    mastered_at = CASE WHEN ? = 'mastered' THEN UTC_TIMESTAMP() ELSE mastered_at END,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = ? AND user_id = ?",
            [$status, $status, $cardId, $userId]
        );
        return $statement->rowCount();
    }

    /** Explicit delete (reviews cascade with the row). Returns affected rows. */
    public function delete(int $userId, int $cardId): int
    {
        $statement = $this->run(
            'DELETE FROM flip_cards WHERE id = ? AND user_id = ?',
            [$cardId, $userId]
        );
        return $statement->rowCount();
    }

    /**
     * The user's still-queued card at a location, if any (Prompt 24):
     * duplicate detection — one active/in-review card per ayah.
     */
    public function findActiveAtLocation(int $userId, int $surahNumber, int $ayahNumber): ?array
    {
        return $this->fetch(
            "SELECT id, status FROM flip_cards
              WHERE user_id = ? AND surah_number = ? AND ayah_number = ?
                AND status IN ('active', 'in_review')
              ORDER BY id
              LIMIT 1",
            [$userId, $surahNumber, $ayahNumber]
        );
    }

    /** Whether a seeded error category exists (validation). */
    public function categoryExists(int $categoryId): bool
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM flip_card_categories WHERE id = ?',
            [$categoryId]
        ) === 1;
    }

    /** The seeded category vocabulary (read-only application vocabulary). */
    public function categories(): array
    {
        return $this->fetchAll(
            'SELECT id, slug, name_en, name_ar, description_en, description_ar, sort_order
               FROM flip_card_categories
              ORDER BY sort_order ASC, id ASC'
        );
    }
}
