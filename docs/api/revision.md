# API — Revision (المراجعة)

Status: implemented (Prompt 10). Base path `/api/v1`. Every route requires a session (`AuthMiddleware`).

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/revision/plans` | session | List the user's plans, newest first → `200` |
| POST | `/revision/plans` | session | Create a plan over the current memorized range (first cycle segmented) → `201` |
| GET | `/revision/plans/{plan_id}` | session | Full plan detail (segments, cycles, missed days) → `200` |
| PUT | `/revision/plans/{plan_id}/target` | session | Change the daily target; re-segments the pending tail → `200` |
| PUT | `/revision/plans/{plan_id}/status` | session | Transition `active` / `paused` / `completed` → `200` |
| POST | `/revision/plans/{plan_id}/cycles` | session | Generate the next cycle from the current range (`regenerate` optional) → `201` |
| GET | `/revision/cycles/{cycle_id}` | session | One cycle with its segments (history browsing) → `200` |
| PUT | `/revision/segments/{segment_id}` | session | Explicitly skip a segment (`status: "skipped"`) → `200` |
| GET | `/revision/sessions?limit=1..100` | session | Session history, newest first → `200` |
| POST | `/revision/sessions` | session | Open an attempt, or resume a prior one (`resumes_session_id`) → `201` |
| POST | `/revision/sessions/{session_id}/progress` | session | Report the furthest page reached → `200` |
| PUT | `/revision/sessions/{session_id}` | session | Finalize once: `completed` / `partial` / `interrupted` → `200` |

Envelope is always `{ok, data, errors}`; `400` malformed JSON, `401` unauthenticated, `404` not found (or not yours — ownership is never revealed), `422` validation. There are no `409` responses: conflicts are reported as `422` with the offending field.

## Payloads

### `POST /revision/plans` — create

```json
{ "target_unit": "page", "daily_amount": 4, "name": "optional, max 120 chars" }
```

Both fields are optional — omitted values fall back to `user_settings.daily_revision_unit` / `daily_revision_amount` (defaults `page` / `1.0`). Supported units: `page`, `hizb`, `rub`, `juz`.

### `PUT /revision/plans/{plan_id}/target` — change target

```json
{ "target_unit": "page", "daily_amount": 6 }
```

### `PUT /revision/plans/{plan_id}/status` — transition

```json
{ "status": "active" }
```

### `POST /revision/plans/{plan_id}/cycles` — next cycle

```json
{ "regenerate": false }
```

`regenerate: true` supersedes an unfinished current cycle instead of failing.

### `PUT /revision/segments/{segment_id}` — explicit skip

```json
{ "status": "skipped" }
```

### `POST /revision/sessions` — open or resume

```json
{ "segment_id": 12, "resumes_session_id": 30 }
```

`resumes_session_id` is optional; the resumed session must be a finished `partial`/`interrupted` attempt of the **same** segment — its progress is inherited.

### `POST /revision/sessions/{session_id}/progress`

```json
{ "last_page_reached": 3 }
```

### `PUT /revision/sessions/{session_id}` — finalize

```json
{ "status": "completed", "last_page_reached": 3, "interruption_reason": "optional, max 190", "notes": "optional, max 255" }
```

## Responses

### Plan detail (create, read, target change, status change, cycle generation, skip)

```json
{
  "plan": { "id": 1, "name": "Main cycle", "target_unit": "page", "daily_amount": 4.0,
            "range_start_page": 1, "range_end_page": 12, "boundary_page_snapshot": 12,
            "status": "active", "current_cycle_number": 1,
            "started_at": "...", "completed_at": null, "created_at": "...", "updated_at": "..." },
  "cycles": [ { "id": 1, "plan_id": 1, "cycle_number": 1, "range_start_page": 1,
                "range_end_page": 12, "boundary_page_snapshot": 12, "segment_count": 3,
                "status": "completed", "started_at": "...", "completed_at": "..." } ],
  "current_cycle": { "...": "same shape as one cycles[] row" },
  "segments": [ { "id": 1, "segment_number": 1, "start_page": 1, "end_page": 4, "page_count": 4,
                  "scheduled_date": "2026-10-05", "status": "pending", "completed_at": null,
                  "is_missed": false, "is_today": true } ],
  "missed_count": 0,
  "in_progress_session": null
}
```

Mutations add: `segments_regenerated` (target change) and `superseded_cycle_id` (cycle generation; `null` when the previous cycle was already completed).

### Cycle detail

```json
{ "cycle": { "...": "..." }, "segments": [ { "...": "...", "is_missed": false, "is_today": true } ], "missed_count": 0 }
```

### Session (start, progress, finish, and `in_progress_session`)

```json
{ "id": 30, "segment_id": 12, "resumes_session_id": null, "status": "partial",
  "total_pages": 4, "pages_completed": 0, "last_page_reached": null,
  "current_page": 1, "pages_remaining": 4, "percent_complete": 0,
  "started_at": "...", "ended_at": null, "duration_seconds": null,
  "interruption_reason": null, "notes": null }
```

The three display fields are computed server-side (conventions §14) so the UI never does arithmetic: `current_page` is the next page to open (last reached + 1, clamped to the segment end; the segment start before any progress), `pages_remaining` is `page_count - pages_completed` (never negative), `percent_complete` is the rounded completion percentage of the segment.

Finish responses also carry `segment` and `cycle_completed` (whether the cycle settled with this completion). Session lists are `{ "sessions": [ { "...": "...", "segment": { "segment_number": 1, "start_page": 1, "end_page": 4, "scheduled_date": "..." } } ] }`.

## Rules (server-authoritative)

* **Segments come from the memorized range, never from hard-coded pages.** A plan snapshots `[memorized_start_page..current_boundary_page]` at creation; segmentation runs through `RevisionTargetService::segmentRange` — consecutive, non-overlapping, never past the boundary, final segment may be smaller than the target.
* **One unfinished plan per user.** A second `POST /revision/plans` → `422` field `plan`. The range requires established memorization state (`404` otherwise).
* **Plan machine:** `active ⇄ paused → completed`. `active → completed` is refused (`422` field `status`); completed plans are final. Pausing/resuming with an open session → `422` field `session`. Resuming additionally requires the current cycle to still fit the memorized range, otherwise `422` field `cycle` — regenerate first. When the boundary **shrinks**, active plans outside the new range are paused automatically (Prompt 09 rule).
* **Cycle machine:** `pending → active → completed | superseded`. Generation requires a completed current cycle, or `regenerate: true` to supersede an unfinished one. Superseded cycles and their segments/sessions are **kept forever** (history policy §6) — only the plan's `current_cycle_number` moves on.
* **New cycles snapshot today's range**, so memorization growth (Prompt 09 marking) is picked up automatically; cycle generations never rewrite old cycles.
* **Target changes re-segment only the untouched pending tail** after the last completed/skipped segment (from today onward, numbering and `segment_count` kept contiguous). Finished segments and their dates are preserved. If nothing is pending (cycle completed/superseded), the change is config-only: `segments_regenerated: 0`. On a completed plan → `422` field `status`.
* **Sessions:** at most one in-progress row per user (`422` field `session`, open = `ended_at IS NULL`; rows are inserted in progress and finalized exactly once). Starting requires an active plan, a current (not completed/superseded) cycle and a pending/active segment (`422` field `plan` / `cycle` / `segment`). Progress is sequential inside the segment and derived by the server (`pages_completed = last_page_reached - start_page + 1`) — out-of-order or out-of-range progress → `422` field `last_page_reached`. `completed` forces the segment end; `partial`/`interrupted` keep the reported progress verbatim and leave the segment active. Duration is computed in SQL from `started_at`.
* **Missed days are derived, never fabricated.** `is_missed` = pending with `scheduled_date` before today (UTC); `is_today` marks the current pending/active unit; `missed_count` aggregates them. Late completion is allowed; explicit decisions use the segment skip endpoint (blocked while a session is open on it, `422` field `session`).
* **All dates are UTC day arithmetic** — segment `i` is scheduled `generation day + i`.
* **Ownership is enforced in every query** — another user's id yields `404`, never `403`.

## Examples

```http
POST  /api/v1/revision/plans           {"target_unit": "page", "daily_amount": 4}
POST  /api/v1/revision/sessions        {"segment_id": 1}
POST  /api/v1/revision/sessions/30/progress {"last_page_reached": 3}
PUT   /api/v1/revision/sessions/30     {"status": "partial", "last_page_reached": 3}
PUT   /api/v1/revision/segments/2      {"status": "skipped"}
POST  /api/v1/revision/plans/1/cycles  {"regenerate": false}
PUT   /api/v1/revision/plans/1/status  {"status": "completed"}
```
