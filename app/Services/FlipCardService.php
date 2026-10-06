<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\FlipCardReviewResult;
use App\Models\FlipCardSeverity;
use App\Models\FlipCardStatus;
use App\Repositories\FlipCardRepository;
use App\Repositories\FlipCardReviewRepository;

/**
 * Flip cards (بطاقات الأخطاء, Prompt 13): flag a memorization error at
 * a canonical Quran location, track reviews, and move cards through
 * active → in_review → mastered (| archived).
 *
 * Established rules:
 * - locations are validated against the canonical dataset: the ayah
 *   must exist and the card page must actually carry that ayah;
 * - the review queue holds active/in_review cards ordered least
 *   recently reviewed first (never-reviewed first); mastered/archived
 *   leave the queue but keep every review;
 * - the first review of an active card promotes it to in_review; later
 *   reviews only bump counters (status changes stay explicit);
 * - review history is append-only and survives until the card itself
 *   is explicitly deleted (reviews cascade with the row).
 */
final class FlipCardService
{
    /** Cards returned by list/queue when the caller sends no limit. */
    public const DEFAULT_LIMIT = 20;

    private FlipCardRepository $cards;
    private FlipCardReviewRepository $reviews;
    private QuranStructureService $structure;

    public function __construct(
        ?FlipCardRepository $cards = null,
        ?FlipCardReviewRepository $reviews = null,
        ?QuranStructureService $structure = null,
    ) {
        $this->cards = $cards ?? new FlipCardRepository();
        $this->reviews = $reviews ?? new FlipCardReviewRepository();
        $this->structure = $structure ?? new QuranStructureService();
    }

    /** The seeded error-category vocabulary (application, not Quran, data). */
    public function categories(): array
    {
        return ['categories' => $this->cards->categories()];
    }

    /**
     * Flags a new error at a canonical location (201).
     *
     * @param array<string, mixed> $input validated create payload
     * @return array{card: array<string, mixed>}
     */
    public function create(int $userId, array $input): array
    {
        $surah = (int) $input['surah_number'];
        $ayah = (int) $input['ayah_number'];
        $page = $this->validateLocation($surah, $ayah, (int) $input['page_number']);

        // Prompt 24: one queued card per location. Mastered/archived cards
        // are outside the queue, so the same ayah may be re-flagged later.
        if ($this->cards->findActiveAtLocation($userId, $surah, $ayah) !== null) {
            throw ValidationException::withErrors([
                ['field' => 'ayah_number', 'message' => 'This ayah is already flagged (an active card exists)'],
            ]);
        }

        $categoryId = (int) $input['category_id'];
        if (!$this->cards->categoryExists($categoryId)) {
            throw ValidationException::withErrors([
                ['field' => 'category_id', 'message' => 'Category does not exist'],
            ]);
        }

        $severity = ($input['severity'] ?? null) !== null
            ? FlipCardSeverity::from((string) $input['severity'])->value
            : FlipCardSeverity::Medium->value;

        $cardId = $this->cards->create(
            $userId,
            $categoryId,
            $surah,
            $ayah,
            $page,
            (string) $input['error_note'],
            ($input['context_note'] ?? null) !== null ? (string) $input['context_note'] : null,
            $severity,
        );

        return ['card' => $this->requireCard($userId, $cardId)];
    }

    /**
     * The user's cards, newest first (optional filters).
     *
     * @return array{cards: array<int, array<string, mixed>>}
     */
    public function list(int $userId, ?string $status, ?int $categoryId, int $limit): array
    {
        $rows = $this->cards->listForUser($userId, $status, $categoryId, $limit);

        return ['cards' => array_map(fn (array $row): array => $this->mapCard($row), $rows)];
    }

    /**
     * The active review queue: active/in_review, least recently
     * reviewed first (never-reviewed first).
     *
     * @return array{cards: array<int, array<string, mixed>>}
     */
    public function queue(int $userId, int $limit): array
    {
        $rows = $this->cards->queueForUser($userId, $limit);

        return ['cards' => array_map(fn (array $row): array => $this->mapCard($row), $rows)];
    }

    /**
     * One card with its full review history.
     *
     * @return array{card: array<string, mixed>, reviews: array<int, array<string, mixed>>}
     */
    public function detail(int $userId, int $cardId): array
    {
        return [
            'card' => $this->requireCard($userId, $cardId),
            'reviews' => $this->mapReviews($this->reviews->findByCard($userId, $cardId)),
        ];
    }

    /**
     * Tracks one review: appends history, bumps review_count, stamps
     * last_reviewed_at and promotes a still-active card to in_review.
     *
     * @param array<string, mixed> $input validated review payload
     * @return array{card: array<string, mixed>, reviews: array<int, array<string, mixed>>}
     */
    public function review(int $userId, int $cardId, array $input): array
    {
        $this->requireCard($userId, $cardId);

        $result = FlipCardReviewResult::from((string) $input['result'])->value;
        $duration = ($input['duration_seconds'] ?? null) !== null ? (int) $input['duration_seconds'] : null;
        $notes = ($input['notes'] ?? null) !== null ? (string) $input['notes'] : null;

        Database::transaction(function () use ($cardId, $userId, $result, $duration, $notes): void {
            $this->reviews->create($cardId, $userId, $result, $duration, $notes);
            $this->cards->recordReview($userId, $cardId);
        });

        return [
            'card' => $this->requireCard($userId, $cardId),
            'reviews' => $this->mapReviews($this->reviews->findByCard($userId, $cardId)),
        ];
    }

    /**
     * Explicit status transition. Same state is an idempotent no-op;
     * every other cross-state move is allowed (master, archive, reopen).
     *
     * @return array{card: array<string, mixed>}
     */
    public function changeStatus(int $userId, int $cardId, FlipCardStatus $target): array
    {
        $card = $this->requireCard($userId, $cardId);
        $current = FlipCardStatus::from($card['status']);

        if ($target !== $current) {
            $this->cards->updateStatus($userId, $cardId, $target->value);
        }

        return ['card' => $this->requireCard($userId, $cardId)];
    }

    /**
     * Explicit delete: the card row and its review history leave the
     * system together (FK cascade).
     *
     * @return array{card_id: int, deleted: bool}
     */
    public function delete(int $userId, int $cardId): array
    {
        $this->requireCard($userId, $cardId);
        $this->cards->delete($userId, $cardId);

        return ['card_id' => $cardId, 'deleted' => true];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Canonical location check: the ayah must exist (422) and the card
     * page must be a dataset page that carries it (422, canonical page
     * named). Returns the accepted page number.
     */
    private function validateLocation(int $surah, int $ayah, int $page): int
    {
        try {
            $canonical = $this->structure->locationToPage($surah, $ayah);
        } catch (NotFoundException) {
            throw ValidationException::withErrors([
                ['field' => 'ayah_number', 'message' => 'Quran location does not exist'],
            ]);
        }

        $this->structure->assertValidPageNumber($page, 'page_number');

        $appears = false;
        foreach ($this->structure->pageAyahs($page) as $segment) {
            if ((int) $segment['surah_number'] === $surah && (int) $segment['ayah_number'] === $ayah) {
                $appears = true;
                break;
            }
        }

        if (!$appears) {
            throw ValidationException::withErrors([
                [
                    'field' => 'page_number',
                    'message' => 'This ayah does not appear on that page (canonical page ' . $canonical . ')',
                ],
            ]);
        }

        return $page;
    }

    /** @return array<string, mixed> mapped card */
    private function requireCard(int $userId, int $cardId): array
    {
        $row = $this->cards->find($userId, $cardId);
        if ($row === null) {
            throw new NotFoundException('Flip card not found');
        }
        return $this->mapCard($row);
    }

    /** Row → API shape (ids/counts cast, timestamps untouched). */
    private function mapCard(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'category_id' => (int) $row['category_id'],
            'category_slug' => (string) $row['category_slug'],
            'category_name_en' => (string) $row['category_name_en'],
            'category_name_ar' => (string) $row['category_name_ar'],
            'surah_number' => (int) $row['surah_number'],
            'ayah_number' => (int) $row['ayah_number'],
            'page_number' => (int) $row['page_number'],
            'error_note' => (string) $row['error_note'],
            'context_note' => $row['context_note'] === null ? null : (string) $row['context_note'],
            'severity' => (string) $row['severity'],
            'status' => (string) $row['status'],
            'review_count' => (int) $row['review_count'],
            'last_reviewed_at' => $row['last_reviewed_at'] === null ? null : (string) $row['last_reviewed_at'],
            'next_review_at' => $row['next_review_at'] === null ? null : (string) $row['next_review_at'],
            'mastered_at' => $row['mastered_at'] === null ? null : (string) $row['mastered_at'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapReviews(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'result' => (string) $row['result'],
            'duration_seconds' => $row['duration_seconds'] === null ? null : (int) $row['duration_seconds'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'reviewed_at' => (string) $row['reviewed_at'],
        ], $rows);
    }
}
