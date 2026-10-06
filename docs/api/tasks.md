# API — Daily Tasks

Status: implemented (Prompt 14). Base path `/api/v1`. Every route requires a session (`AuthMiddleware`).

Daily productivity (المهام اليومية) covers the current day's tasks: the seeded Quran task vocabulary (مراجعة، ربط، حفظ جديد، مراجعة بطاقات), the day view with its server-computed summary, completion tracking with an append-only history, and free-form personal tasks. Productivity data is deliberately separate from the canonical Quran dataset and has **no link into the Hifz domain** — editing or deleting a task can never corrupt memorization progress.

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/task-types` | session | Active task vocabulary → `200` |
| GET | `/tasks/history` | session | Task history (date range) → `200` / `422` |
| GET | `/tasks` | session | One scheduled day (default today) + summary → `200` / `422` |
| POST | `/tasks` | session | Schedule a new pending task → `201` |
| GET | `/tasks/{task_id}` | session | One task + its completion → `200` / `404` |
| PUT | `/tasks/{task_id}` | session | Edit title/type/duration/notes → `200` / `404` |
| PUT | `/tasks/{task_id}/status` | session | Status transition → `200` / `404` |
| DELETE | `/tasks/{task_id}` | session | Explicit delete (completion with it) → `200` / `404` |

Envelope is always `{ok, data, errors}`; `400` malformed JSON, `401` unauthenticated, `404` unknown or foreign task, `422` validation. There is no 409: repeating a status is an idempotent no-op.

## Task object

```json
{
  "id": 3,
  "task_type_id": 1,
  "type_slug": "murajaah",
  "type_name_en": "Murajaah",
  "type_name_ar": "مراجعة",
  "type_category": "quran",
  "title": null,
  "scheduled_date": "2026-10-05",
  "duration_minutes": 30,
  "status": "pending",
  "completed_at": null,
  "actual_duration_seconds": null,
  "notes": "Surah morning review",
  "created_at": "2026-10-05 08:00:00",
  "updated_at": "2026-10-05 08:00:00",
  "completion": null
}
```

After completion `completion` holds the append-only record: `{"id": 1, "completed_at": "...", "duration_seconds": 600, "note": "finished early"}`.

## Status machine

```
pending ⇄ active          pending/active ⇄ skipped          → completed (terminal)
```

- Same state again → `200` idempotent no-op (no duplicate writes).
- **`completed` is terminal**: `422` (field `status`, "A completed task cannot be reopened") for any other target.
- **`skipped` must be reopened first**: `skipped → completed` is `422` (field `status`, "A skipped task must be reopened before it can be completed") — skipping a task never accrues a completion record; go through `pending`/`active` instead.
- Entering `completed` stamps `completed_at` + `actual_duration_seconds` on the task and appends the single `task_completions` row in one transaction (UNIQUE `task_id` keeps it one-per-task).

## Day view and summary

`GET /tasks?date=YYYY-MM-DD` (date optional, defaults to today in UTC) returns `{"date", "tasks", "summary"}`. The summary is computed in PHP on every read (conventions §14):

```json
{"total": 3, "completed": 1, "active": 1, "pending": 1, "skipped": 0, "planned_minutes": 85, "completion_percent": 33.33}
```

`completion_percent = round(completed / total * 100, 2)`; empty day → `0.0`; `planned_minutes` sums the day's `duration_minutes`.

## Payloads

### `POST /tasks` — schedule

```json
{
  "task_type_id": 1,
  "title": "optional, required for general types, max 150",
  "duration_minutes": "optional 1–1440 (default: type default)",
  "scheduled_date": "optional, default today (server UTC)",
  "notes": "optional, max 255"
}
```

Response `201`: `{"task": {...}}`. `task_type_id` must name an active seeded type (`422`, field `task_type_id`); general tasks without a title are rejected (`422`, field `title`).

### `PUT /tasks/{task_id}` — edit

Only `title`, `task_type_id`, `duration_minutes`, `notes` (all optional; omitted = unchanged). **`scheduled_date` is immutable** (not accepted by the validator). Completed tasks stay editable. Switching to a general type requires an effective title (`422`, field `title`).

### `PUT /tasks/{task_id}/status` — transition

```json
{"status": "pending | active | completed | skipped", "actual_duration_seconds": "0–86400, completion only", "note": "completion only, max 255"}
```

### `GET /tasks/history?from=&to=&limit=`

Inclusive date range, newest day first. `from`/`to` required (`from` after `to` → `422`, field `from`); `limit` 1–100, default 50.

## Rules

- **Hifz isolation (hard rule)**: `daily_tasks` / `task_completions` have no foreign keys into memorization, revision or flip-card tables. Creating, editing, completing or deleting a task touches only productivity tables (verified by snapshot equality in `TaskTest`).
- **Quran vs general**: the four seeded Quran types (`murajaah`, `rabt`, `new_memorization`, `flip_card_review`) name canonical activities with default durations; `personal` is the general bucket and requires a caller-supplied title. No Quran content is generated here — types are vocabulary only.
- **Ownership**: every read/write filters `user_id`; foreign tasks are `404`.
- **Delete**: task + its completion row leave together (FK cascade); siblings keep their history.
