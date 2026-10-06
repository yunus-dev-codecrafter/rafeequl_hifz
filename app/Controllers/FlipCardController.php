<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\FlipCardStatus;
use App\Request;
use App\Response;
use App\Services\FlipCardService;
use App\Validators\FlipCardCreateValidator;
use App\Validators\FlipCardQueryValidator;
use App\Validators\FlipCardReviewValidator;
use App\Validators\FlipCardStatusValidator;

/**
 * Flip card endpoints (بطاقات الأخطاء, Prompt 13). All routes sit behind
 * AuthMiddleware; every method operates on the authenticated user only.
 * Business rules live in FlipCardService (conventions §9).
 */
final class FlipCardController extends Controller
{
    private FlipCardService $cards;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->cards = new FlipCardService();
    }

    /** The seeded error-category vocabulary. */
    public function categories(array $params = []): Response
    {
        return $this->success($this->cards->categories());
    }

    /** Lists the user's cards (optional status/category filters), newest first. */
    public function index(array $params = []): Response
    {
        $data = $this->validate(new FlipCardQueryValidator(), [
            'limit' => $this->query('limit'),
            'status' => $this->query('status'),
            'category_id' => $this->query('category_id'),
        ]);

        return $this->success($this->cards->list(
            $this->requireUser()->id(),
            $data['status'] !== null ? (string) $data['status'] : null,
            $data['category_id'] !== null ? (int) $data['category_id'] : null,
            $data['limit'] !== null ? (int) $data['limit'] : FlipCardService::DEFAULT_LIMIT,
        ));
    }

    /** The active review queue (active/in_review, least recently reviewed first). */
    public function queue(array $params = []): Response
    {
        $data = $this->validate(new FlipCardQueryValidator(), [
            'limit' => $this->query('limit'),
        ]);

        return $this->success($this->cards->queue(
            $this->requireUser()->id(),
            $data['limit'] !== null ? (int) $data['limit'] : FlipCardService::DEFAULT_LIMIT,
        ));
    }

    /** Flags a new error at a canonical Quran location (201). */
    public function create(array $params = []): Response
    {
        $data = $this->validate(new FlipCardCreateValidator());

        return $this->success($this->cards->create($this->requireUser()->id(), $data), 201);
    }

    /** One card with its full review history. */
    public function detail(array $params = []): Response
    {
        return $this->success($this->cards->detail(
            $this->requireUser()->id(),
            $this->routeId($params, 'card_id'),
        ));
    }

    /** Tracks one review on a card (201 — history row created). */
    public function review(array $params = []): Response
    {
        $data = $this->validate(new FlipCardReviewValidator());

        return $this->success($this->cards->review(
            $this->requireUser()->id(),
            $this->routeId($params, 'card_id'),
            $data,
        ), 201);
    }

    /** Applies a card status transition (idempotent on the same state). */
    public function changeStatus(array $params = []): Response
    {
        $data = $this->validate(new FlipCardStatusValidator());

        return $this->success($this->cards->changeStatus(
            $this->requireUser()->id(),
            $this->routeId($params, 'card_id'),
            FlipCardStatus::from((string) $data['status']),
        ));
    }

    /** Explicitly deletes a card — its review history goes with it. */
    public function delete(array $params = []): Response
    {
        return $this->success($this->cards->delete(
            $this->requireUser()->id(),
            $this->routeId($params, 'card_id'),
        ));
    }
}
