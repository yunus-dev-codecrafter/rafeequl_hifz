# API — Progress Analytics

Status: implemented (Prompt 22). Base path `/api/v1`. The route requires a session (`AuthMiddleware`).

Progress analytics (التقدم والمتابعة) is the dashboard's read-only snapshot of the user's journey: memorization figures, revision consistency, ربط activity, flip-card counts, weekly productivity and a merged recent-activity feed. The purpose is **awareness and consistency, not competition** — deliberately absent are leaderboards, scores, streak counters, rankings, comparisons against other users and any pressure mechanism.

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/progress/summary` | session | Full analytics snapshot → `200` |

Envelope is always `{ok, data, errors}`; `401` unauthenticated. The endpoint never writes: opening the dashboard cannot change any stored number.

## Analytics object

`GET /progress/summary` → `data.analytics`:

```json
{
  "generated_at": "2026-10-05 18:00:00",
  "consistency_window_days": 14,
  "memorization": {
    "established": true,
    "memorized_start_page": 1,
    "current_boundary_page": 15,
    "next_page_to_memorize": 16,
    "page_count": 15,
    "total_pages": 604,
    "percent_memorized": 2.48,
    "pages_last_14_days": 5
  },
  "revision": {
    "sessions_last_14_days": 3,
    "sessions_total": 4,
    "active_days_last_14_days": 2,
    "consistency_percent": 14.29,
    "segments_completed": 1,
    "segments_total": 3
  },
  "rabt": { "tasks_completed_last_14_days": 1 },
  "flip_cards": {
    "active": 2, "in_review": 1, "mastered": 1, "archived": 1,
    "total": 5, "pending_review": 3
  },
  "productivity": { "window_days": 7, "planned": 4, "completed": 2, "percent": 50.0 },
  "recent_activity": [
    { "type": "memorization", "occurred_at": "2026-10-05 17:59:00", "page_number": 11 },
    { "type": "revision", "occurred_at": "2026-10-04 18:00:00", "status": "partial", "pages_completed": 3, "total_pages": 5 },
    { "type": "flip", "occurred_at": "2026-10-04 16:00:00", "result": "recalled" },
    { "type": "task", "occurred_at": "2026-10-05 17:00:00", "title": "مراجعة", "task_slug": "murajaah" }
  ]
}
```

When memorization is not established, every `memorization` figure except `established` and `pages_last_14_days` is `null` (the UI renders `—`).

## Windows and definitions

All windows use the **server's UTC date** (the same rule as tasks and revision); boundaries are computed in PHP with `gmdate()` and passed into SQL as parameters — no SQL session-timezone dependence.

| Metric | Definition |
| --- | --- |
| `pages_last_14_days` | Rows in `memorization_history` with `memorized_at` inside the last 14 UTC days (today + previous 13). |
| `sessions_last_14_days` | `revision_sessions.started_at` inside the same window. |
| `sessions_total` | All of the user's revision sessions (lifetime; history is preserved). |
| `active_days_last_14_days` | Distinct UTC days with ≥1 started revision session (`COUNT(DISTINCT DATE(started_at))`). |
| `consistency_percent` | `round(active_days / 14 * 100, 2)` — a neutral window fill for the dashboard bar, never a score. |
| `segments_completed` / `segments_total` | `revision_segments` with `status='completed'` over all of the user's segments. |
| `rabt.tasks_completed_last_14_days` | Completed tasks whose type is `rabt` with `scheduled_date` in the window (the ربط-window pages themselves are already shown on the activity card). |
| `flip_cards.*` | `COUNT(*)` of `flip_cards` grouped by status; `pending_review = active + in_review` (the review queue's two statuses). |
| `productivity.*` | `daily_tasks` with `scheduled_date` in the last 7 UTC days; `percent = round(completed / planned * 100, 2)`, `0.0` when nothing is planned. |

## Recent activity

- Newest rows from four streams (memorization, revision sessions, flip reviews, completed tasks), 5 per stream, merged and sorted **by time only** — no stream outranks another — then capped at the newest 5 overall.
- `occurred_at` is the source table's UTC datetime (`memorized_at`, `started_at`, `reviewed_at`, `completed_at`).
- `type` is one of `memorization`, `revision`, `flip`, `task`; each carries only its own detail fields (see the example above). Task events expose `task_slug` plus a display `title` (falls back to the type's Arabic name).
- The client formats timestamps and Arabic labels; it never computes counts or percentages from them.

## Server-side math

Every displayed number is computed in PHP (`ProgressAnalyticsService`) or SQL (`ProgressRepository`) — conventions §14. Percentages use `round(x / y * 100, 2)` and degrade to `0.0` on an empty denominator. The client only formats and renders.

## Ownership

| Layer | File |
| --- | --- |
| Route | `routes/api.php` — `GET /api/v1/progress/summary` |
| Controller | `app/Controllers/ProgressController.php` |
| Service | `app/Services/ProgressAnalyticsService.php` |
| Repository | `app/Repositories/ProgressRepository.php` (aggregate SQL) |
| Client wrapper | `public/js/features/progress/progress-api.js` |
| UI | `public/shell.html` (`view-dashboard` → `.dash-progress`), `public/js/features/dashboard/dashboard-page.js` |

## Verification

- `php tests/Integration/ProgressAnalyticsTest.php` — fresh zeros, memorization figures, window math, segment/flip/productivity counts, merge order, read-only guarantee, cross-user isolation (61 checks).
- `powershell -File test_progress.ps1` — 401 gate, envelope, establish/mark flow over HTTP, read-only re-read, isolation, shell slots and asset availability (65 checks).
