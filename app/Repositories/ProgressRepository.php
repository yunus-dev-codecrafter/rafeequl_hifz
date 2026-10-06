<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Read-only aggregates for progress analytics (Prompt 22).
 *
 * Every number shown on the dashboard's "التقدم والمتابعة" section is
 * computed here (SQL) or in ProgressAnalyticsService — the client never
 * does arithmetic (conventions §14). All queries are user-scoped and
 * date-bounded; the append-only history tables keep the full record even
 * when only a recent window is counted.
 *
 * "Today" is the server's UTC date (same rule as tasks/revision); window
 * boundaries are passed in as parameters computed with gmdate() by the
 * service, never by SQL session-time functions.
 */
final class ProgressRepository extends Repository
{
    // --- memorization (append-only history + current state) --------------

    /** Pages recorded as memorized within the inclusive window. */
    public function countMemorizedSince(int $userId, string $sinceDateTime, string $untilDateTime): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*)
               FROM memorization_history
              WHERE user_id = ? AND memorized_at >= ? AND memorized_at <= ?',
            [$userId, $sinceDateTime, $untilDateTime]
        );
    }

    // --- revision sessions & segments ------------------------------------

    public function countRevisionSessionsSince(int $userId, string $sinceDateTime, string $untilDateTime): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*)
               FROM revision_sessions
              WHERE user_id = ? AND started_at >= ? AND started_at <= ?',
            [$userId, $sinceDateTime, $untilDateTime]
        );
    }

    public function countRevisionSessions(int $userId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM revision_sessions WHERE user_id = ?',
            [$userId]
        );
    }

    /** Distinct UTC days with at least one started revision session. */
    public function countActiveRevisionDays(int $userId, string $sinceDateTime, string $untilDateTime): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(DISTINCT DATE(started_at))
               FROM revision_sessions
              WHERE user_id = ? AND started_at >= ? AND started_at <= ?',
            [$userId, $sinceDateTime, $untilDateTime]
        );
    }

    public function countRevisionSegments(int $userId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM revision_segments WHERE user_id = ?',
            [$userId]
        );
    }

    public function countCompletedRevisionSegments(int $userId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM revision_segments WHERE user_id = ? AND status = 'completed'",
            [$userId]
        );
    }

    // --- rabt (ربط) activity: completed ربط tasks -------------------------

    /** Completed tasks of type `rabt` within the inclusive date window. */
    public function countRabtTasksCompletedSince(int $userId, string $sinceDate, string $untilDate): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*)
               FROM daily_tasks t
               JOIN task_types k ON k.id = t.task_type_id
              WHERE t.user_id = ?
                AND k.slug = 'rabt'
                AND t.status = 'completed'
                AND t.scheduled_date >= ?
                AND t.scheduled_date <= ?",
            [$userId, $sinceDate, $untilDate]
        );
    }

    // --- flip cards -------------------------------------------------------

    /** @return array<string, int> status => count (statuses absent = 0) */
    public function countFlipCardsByStatus(int $userId): array
    {
        $rows = $this->fetchAll(
            'SELECT status, COUNT(*) AS total
               FROM flip_cards
              WHERE user_id = ?
              GROUP BY status',
            [$userId]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    // --- daily productivity ----------------------------------------------

    /** Tasks scheduled within the inclusive date window (future dates excluded). */
    public function countTasksSince(int $userId, string $fromDate, string $untilDate): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*)
               FROM daily_tasks
              WHERE user_id = ? AND scheduled_date >= ? AND scheduled_date <= ?',
            [$userId, $fromDate, $untilDate]
        );
    }

    public function countCompletedTasksSince(int $userId, string $fromDate, string $untilDate): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*)
               FROM daily_tasks
              WHERE user_id = ? AND scheduled_date >= ? AND scheduled_date <= ?
                AND status = 'completed'",
            [$userId, $fromDate, $untilDate]
        );
    }

    // --- recent activity (newest first, bounded) --------------------------

    /** @return array<int, array<string, mixed>> */
    public function recentMemorization(int $userId, int $limit): array
    {
        return $this->fetchAll(
            'SELECT page_number, memorized_at
               FROM memorization_history
              WHERE user_id = ?
              ORDER BY memorized_at DESC, id DESC
              LIMIT ?',
            [$userId, $limit]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function recentRevisionSessions(int $userId, int $limit): array
    {
        return $this->fetchAll(
            'SELECT status, pages_completed, total_pages, started_at
               FROM revision_sessions
              WHERE user_id = ?
              ORDER BY started_at DESC, id DESC
              LIMIT ?',
            [$userId, $limit]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function recentFlipReviews(int $userId, int $limit): array
    {
        return $this->fetchAll(
            'SELECT result, reviewed_at
               FROM flip_card_reviews
              WHERE user_id = ?
              ORDER BY reviewed_at DESC, id DESC
              LIMIT ?',
            [$userId, $limit]
        );
    }

    /** Completed tasks (title falls back to the type's Arabic name). */
    public function recentCompletedTasks(int $userId, int $limit): array
    {
        return $this->fetchAll(
            "SELECT COALESCE(t.title, k.name_ar) AS title, k.slug AS task_slug, t.completed_at
               FROM daily_tasks t
               JOIN task_types k ON k.id = t.task_type_id
              WHERE t.user_id = ? AND t.status = 'completed' AND t.completed_at IS NOT NULL
              ORDER BY t.completed_at DESC, t.id DESC
              LIMIT ?",
            [$userId, $limit]
        );
    }
}
