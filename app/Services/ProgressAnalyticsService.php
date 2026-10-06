<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProgressRepository;

/**
 * Progress analytics (Prompt 22): one read-only snapshot that answers
 * "how is my journey going?" — awareness and consistency, never
 * competition. Deliberately excluded: leaderboards, scores, streak
 * counters, comparisons against other users, and any pressure mechanism.
 *
 * Rules enforced here:
 * - viewing writes nothing (every method is a read);
 * - all arithmetic happens server-side (conventions §14);
 * - history is never rewritten — windows count the append-only tables,
 *   the full record stays in the database;
 * - "today" is the server's UTC date (same rule as tasks/revision), so
 *   windows are built with gmdate() and passed into SQL as parameters;
 * - recent activity merges the newest rows from each stream and keeps
 *   the newest few overall (order is by time only — never by importance).
 */
final class ProgressAnalyticsService
{
    /** Consistency window: today + the previous 13 days (14 total). */
    private const CONSISTENCY_WINDOW_DAYS = 14;

    /** Productivity window: today + the previous 6 days (7 total). */
    private const PRODUCTIVITY_WINDOW_DAYS = 7;

    /** Newest events returned across all activity streams combined. */
    private const RECENT_LIMIT = 5;

    private const PERCENT_PRECISION = 2;

    private ProgressRepository $repo;
    private MemorizationProgressService $progress;

    public function __construct(
        ?ProgressRepository $repo = null,
        ?MemorizationProgressService $progress = null,
    ) {
        $this->repo = $repo ?? new ProgressRepository();
        $this->progress = $progress ?? new MemorizationProgressService();
    }

    /**
     * Full analytics snapshot for one user (read-only).
     *
     * @return array<string, mixed>
     */
    public function summary(int $userId): array
    {
        $windowDays = self::CONSISTENCY_WINDOW_DAYS;
        $productivityDays = self::PRODUCTIVITY_WINDOW_DAYS;

        // Inclusive, date-anchored UTC window: computed from "today" (not
        // from a timestamp) and bounded at BOTH ends, so future-dated rows
        // (e.g. ahead-of-time task schedules) never inflate a window.
        // Boundaries are passed as SQL parameters — no SQL session-timezone
        // or NOW() dependence.
        $today = gmdate('Y-m-d');
        $todayStart = strtotime($today . ' 00:00:00 UTC');
        $windowFrom = gmdate('Y-m-d', strtotime('-' . ($windowDays - 1) . ' days', $todayStart));
        $productivityFrom = gmdate('Y-m-d', strtotime('-' . ($productivityDays - 1) . ' days', $todayStart));
        $windowSince = $windowFrom . ' 00:00:00';
        $windowUntil = $today . ' 23:59:59';

        return [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'consistency_window_days' => $windowDays,
            'memorization' => $this->memorization($userId, $windowSince, $windowUntil),
            'revision' => $this->revision($userId, $windowSince, $windowUntil, $windowDays),
            'rabt' => $this->rabt($userId, $windowFrom, $today),
            'flip_cards' => $this->flipCards($userId),
            'productivity' => $this->productivity($userId, $productivityFrom, $today, $productivityDays),
            'recent_activity' => $this->recentActivity($userId),
        ];
    }

    /** @return array<string, mixed> */
    private function memorization(int $userId, string $windowSince, string $windowUntil): array
    {
        $state = $this->progress->state($userId);
        $progress = $state['progress'];

        return [
            'established' => $state['established'],
            'memorized_start_page' => $state['memorized_start_page'],
            'current_boundary_page' => $state['current_boundary_page'],
            'next_page_to_memorize' => $state['next_page_to_memorize'],
            'page_count' => $progress['page_count'] ?? null,
            'total_pages' => $progress['total_pages'] ?? null,
            'percent_memorized' => $progress['percent_memorized'] ?? null,
            'pages_last_14_days' => $this->repo->countMemorizedSince($userId, $windowSince, $windowUntil),
        ];
    }

    /** @return array<string, mixed> */
    private function revision(int $userId, string $windowSince, string $windowUntil, int $windowDays): array
    {
        $sessionsLastWindow = $this->repo->countRevisionSessionsSince($userId, $windowSince, $windowUntil);
        $sessionsTotal = $this->repo->countRevisionSessions($userId);
        $activeDays = $this->repo->countActiveRevisionDays($userId, $windowSince, $windowUntil);
        $segmentsTotal = $this->repo->countRevisionSegments($userId);
        $segmentsCompleted = $this->repo->countCompletedRevisionSegments($userId);

        return [
            'sessions_last_14_days' => $sessionsLastWindow,
            'sessions_total' => $sessionsTotal,
            'active_days_last_14_days' => $activeDays,
            // Neutral window fill for the consistency bar (never a score).
            'consistency_percent' => $windowDays > 0
                ? round($activeDays / $windowDays * 100, self::PERCENT_PRECISION)
                : 0.0,
            'segments_completed' => $segmentsCompleted,
            'segments_total' => $segmentsTotal,
        ];
    }

    /** ربط activity = completed tasks of type `rabt` in the window.
     *  @return array<string, mixed> */
    private function rabt(int $userId, string $windowFrom, string $today): array
    {
        return [
            'tasks_completed_last_14_days' => $this->repo->countRabtTasksCompletedSince($userId, $windowFrom, $today),
        ];
    }

    /** @return array<string, mixed> */
    private function flipCards(int $userId): array
    {
        $byStatus = $this->repo->countFlipCardsByStatus($userId);

        $active = $byStatus['active'] ?? 0;
        $inReview = $byStatus['in_review'] ?? 0;
        $mastered = $byStatus['mastered'] ?? 0;
        $archived = $byStatus['archived'] ?? 0;
        $total = $active + $inReview + $mastered + $archived;

        return [
            'active' => $active,
            'in_review' => $inReview,
            'mastered' => $mastered,
            'archived' => $archived,
            'total' => $total,
            // Cards still owed a review (the queue's two statuses).
            'pending_review' => $active + $inReview,
        ];
    }

    /** @return array<string, mixed> */
    private function productivity(int $userId, string $productivityFrom, string $today, int $windowDays): array
    {
        $planned = $this->repo->countTasksSince($userId, $productivityFrom, $today);
        $completed = $this->repo->countCompletedTasksSince($userId, $productivityFrom, $today);

        return [
            'window_days' => $windowDays,
            'planned' => $planned,
            'completed' => $completed,
            'percent' => $planned > 0
                ? round($completed / $planned * 100, self::PERCENT_PRECISION)
                : 0.0,
        ];
    }

    /**
     * Newest events from every stream, merged by time (newest first).
     * Order reflects chronology only — no stream is ranked over another.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(int $userId): array
    {
        $limit = self::RECENT_LIMIT;
        $events = [];

        foreach ($this->repo->recentMemorization($userId, $limit) as $row) {
            $events[] = [
                'type' => 'memorization',
                'occurred_at' => (string) $row['memorized_at'],
                'page_number' => (int) $row['page_number'],
            ];
        }

        foreach ($this->repo->recentRevisionSessions($userId, $limit) as $row) {
            $events[] = [
                'type' => 'revision',
                'occurred_at' => (string) $row['started_at'],
                'status' => (string) $row['status'],
                'pages_completed' => (int) $row['pages_completed'],
                'total_pages' => (int) $row['total_pages'],
            ];
        }

        foreach ($this->repo->recentFlipReviews($userId, $limit) as $row) {
            $events[] = [
                'type' => 'flip',
                'occurred_at' => (string) $row['reviewed_at'],
                'result' => (string) $row['result'],
            ];
        }

        foreach ($this->repo->recentCompletedTasks($userId, $limit) as $row) {
            $events[] = [
                'type' => 'task',
                'occurred_at' => (string) $row['completed_at'],
                'title' => (string) $row['title'],
                'task_slug' => (string) $row['task_slug'],
            ];
        }

        usort($events, static fn (array $a, array $b): int =>
            strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));

        return array_slice($events, 0, $limit);
    }
}
