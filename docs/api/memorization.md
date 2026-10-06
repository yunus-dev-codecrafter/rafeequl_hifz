# API — Memorization Progress

Status: implemented (Prompt 09; rolling Rabt window added in Prompt 12). Base path `/api/v1`. Every route requires a session (`AuthMiddleware`).

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/memorization/state` | session | Read-only progress snapshot → `200` |
| POST | `/memorization/state` | session | Establish memorized range + boundary → `201` (once per user) |
| PUT | `/memorization/state` | session | Correct the boundary (explicit `confirm`) → `200` |
| POST | `/memorization/history` | session | Explicitly mark `start_page..end_page` memorized → `201` |
| GET | `/memorization/rabt` | session | Rolling Rabt (ربط) window over the newest memorized pages → `200` |

Envelope is always `{ok, data, errors}`; `400` malformed JSON, `401` unauthenticated, `404` no state yet, `422` validation.

## Payloads

### `state` (GET response, and inside every mutation response)

```json
{
  "established": true,
  "memorized_start_page": 1,
  "current_boundary_page": 5,
  "next_page_to_memorize": 6,
  "status": "active",
  "last_boundary_changed_at": "2026-10-04 10:00:00",
  "last_page_memorized_at": null,
  "progress": { "page_count": 5, "total_pages": 16, "percent_memorized": 31.25 }
}
```

Before establishing: `established: false` and every other field `null`.

### `POST /memorization/state` — establish

```json
{ "memorized_start_page": 1, "current_boundary_page": 5, "note": "optional, max 255 chars" }
```

### `PUT /memorization/state` — correct

```json
{ "current_boundary_page": 5, "confirm": true, "note": "optional" }
```

### `POST /memorization/history` — mark memorized

```json
{ "start_page": 5, "end_page": 7, "note": "optional" }
```

Mutation responses also carry `boundary_change` (`{previous_boundary_page, new_boundary_page, reason}`, or `null` when the value did not move); marking/correcting add `revision_plans_paused`, marking adds `marked`.

### `GET /memorization/rabt` — rolling Rabt (ربط) window

```json
{
  "rabt": {
    "start_page": 241,
    "end_page": 270,
    "page_count": 30,
    "max_pages": 30,
    "memorized_page_count": 270,
    "pages": [241, 242, 243, "...", 270]
  }
}
```

`404` before the memorized range is established.

## Rules (server-authoritative)

* **Viewing never writes.** `GET /memorization/state` writes nothing, and no endpoint marks a page from simply opening/viewing it.
* The boundary is the **inclusive last-memorized page**; `next_page_to_memorize = boundary + 1`, `null` when the boundary already covers the dataset's last page.
* **Establish** runs once — afterwards `422` on field `established`. It writes the state row plus one boundary-history row (`previous_boundary_page = NULL`, reason `manual`) and fabricates **no** page history: pre-app pages have no honest `memorized_at`.
* **Marking** requires `start_page` inside the range or on its next page (`start <= boundary + 1`), `start <= end`, and both pages inside the loaded dataset (`422` naming the offending field). One `memorization_history` row is appended per page — re-marking appends again. The boundary advances only when the range continues past it.
* **Correction** always requires `"confirm": true` (`422` on field `confirm` otherwise). A value equal to the current boundary is a no-op: nothing is written.
* **History is append-only.** Boundary moves add `memorization_boundary_history` rows (previous value kept); old rows are never updated or deleted. Revision cycles/segments/sessions keep their `boundary_page_snapshot` forever.
* **Rabt (ربط) is a derived rolling window, never stored** (Prompt 12): the newest `min(30, memorized page count)` pages ending at the current boundary. Both bounds always sit inside `[memorized_start_page..boundary]`, so an unmemorized page can never appear — a shrinking correction removes pages from the window immediately, a new memorized page slides the oldest one out. `pages[]` lists every page so clients do no arithmetic.
* **Quran data is never invented.** Page numbers are validated against the canonical dataset; a stored boundary beyond the dataset fails closed (`AppException` → `500`) instead of guessing.

## Dependent recalculation

Progress figures (page count, percent, division coverage, revision targets, future dashboard statistics) are **derived at read time** from the current state, so they follow every boundary move automatically — the Rabt window behaves the same way (no cached range can go stale).

Stored plans recalculate conservatively when the boundary **shrinks**: active `revision_plans` whose range no longer fits `[memorized_start_page..boundary]` are paused and the count is returned as `revision_plans_paused`. Growing the boundary never pauses anything. Historical rows are never rewritten — only current state moves.

## Examples

```http
POST /api/v1/memorization/state   {"memorized_start_page": 1, "current_boundary_page": 4}
POST /api/v1/memorization/history {"start_page": 5, "end_page": 7}     -> boundary 7, next 8
PUT  /api/v1/memorization/state   {"current_boundary_page": 5, "confirm": true}
GET  /api/v1/memorization/rabt                                -> 5..7 window (whole range, under the cap)
```
