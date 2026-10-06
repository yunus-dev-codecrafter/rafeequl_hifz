# API — Flip Cards

Status: implemented (Prompt 13). Base path `/api/v1`. Every route requires a session (`AuthMiddleware`).

Flip cards (بطاقات الأخطاء) capture memorization errors at a Quran location, track how often they were reviewed, and move them out of the active review queue as they are mastered.

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/flip-cards/categories` | session | Seeded error-category vocabulary → `200` |
| GET | `/flip-cards/queue` | session | The active review queue → `200` |
| GET | `/flip-cards` | session | List the user's cards (filters) → `200` |
| POST | `/flip-cards` | session | Flag a new error → `201` |
| GET | `/flip-cards/{card_id}` | session | One card + review history → `200` / `404` |
| POST | `/flip-cards/{card_id}/review` | session | Track one review → `201` / `404` |
| PUT | `/flip-cards/{card_id}/status` | session | Explicit status transition → `200` / `404` |
| DELETE | `/flip-cards/{card_id}` | session | Explicit delete (history with it) → `200` / `404` |

Envelope is always `{ok, data, errors}`; `400` malformed JSON, `401` unauthenticated, `404` unknown or foreign card, `422` validation. There is no 409: repeating a status is an idempotent no-op.

## Card object

```json
{
  "id": 4,
  "category_id": 2,
  "category_slug": "verse_mistake",
  "category_name_en": "Verse mistake",
  "category_name_ar": "خطأ في الآية",
  "surah_number": 3,
  "ayah_number": 7,
  "page_number": 12,
  "error_note": "Skipped the opening word",
  "context_note": null,
  "severity": "medium",
  "status": "active",
  "review_count": 0,
  "last_reviewed_at": null,
  "next_review_at": null,
  "mastered_at": null,
  "created_at": "2026-10-05 10:00:00",
  "updated_at": "2026-10-05 10:00:00"
}
```

## Payloads

### `POST /flip-cards` — flag an error

```json
{
  "surah_number": 3,
  "ayah_number": 7,
  "page_number": 12,
  "category_id": 2,
  "error_note": "Skipped the opening word",
  "context_note": "optional, max 500 chars",
  "severity": "optional: low | medium | high (default medium)"
}
```

Response `201`: `{"card": {...}}`.

Validation rules:

- `surah_number`, `ayah_number`, `page_number`, `category_id`, `error_note` are required (`error_note` max 500 chars).
- **Location rule**: the ayah must exist in the canonical dataset (`422`, field `ayah_number`) and the card's page must be a page that actually carries that ayah (`422`, field `page_number`, message names the canonical start page). Ayahs spanning two pages are accepted on either page.
- **One queued card per location**: if you already have a card for the same surah/ayah in `active` or `in_review`, flagging it again is `422` (field `ayah_number`, "This ayah is already flagged (an active card exists)"). `mastered`/`archived` cards sit outside the queue, so the same ayah may be re-flagged after them.
- `category_id` must be one of the seeded categories (`422`, field `category_id`).

### `GET /flip-cards` — list

Query: `limit` (1–100, default 20), `status` (one of the four states), `category_id`. Newest first. Unknown filters simply return no rows.

Response `200`: `{"cards": [...]}`.

### `GET /flip-cards/queue` — active review queue

Query: `limit` (1–100, default 20).

Holds exactly the `active` + `in_review` cards — mastered and archived leave the queue. Order: never-reviewed first, then least recently reviewed, then oldest.

Response `200`: `{"cards": [...]}`.

### `GET /flip-cards/{card_id}` — detail

Response `200`: `{"card": {...}, "reviews": [...]}`, reviews newest first:

```json
{ "id": 9, "result": "forgotten", "duration_seconds": 45, "notes": "Mixed up with 2:3", "reviewed_at": "2026-10-05 10:05:00" }
```

### `POST /flip-cards/{card_id}/review` — review tracking

```json
{ "result": "recalled | partial | forgotten", "duration_seconds": "optional 0..86400", "notes": "optional, max 255 chars" }
```

Response `201`: `{"card": {...}, "reviews": [...]}`.

What one review does, atomically:

- appends a row to `flip_card_reviews` (append-only history),
- increments `review_count` and stamps `last_reviewed_at`,
- promotes a still-`active` card to `in_review` (the first review does this; later reviews leave the status alone — mastering/archiving stays explicit).

Reviews are allowed on any owned card regardless of status, and `next_review_at` is untouched (no scheduling rules in this prompt).

### `PUT /flip-cards/{card_id}/status` — state machine

```json
{ "status": "active | in_review | mastered | archived" }
```

Response `200`: `{"card": {...}}`.

- Same state → idempotent no-op (`200`, card unchanged).
- Any other cross-state move is allowed (master, archive, reopen, re-queue).
- Entering `mastered` stamps `mastered_at`; the stamp is kept afterwards as history.
- `active`/`in_review` sit in the queue; `mastered`/`archived` do not.

### `DELETE /flip-cards/{card_id}` — explicit delete

Response `200`: `{"card_id": 4, "deleted": true}`. The card row and its review history leave together (FK cascade) — the only way history disappears. `404` when the card does not exist or belongs to someone else.

### `GET /flip-cards/categories` — vocabulary

Response `200`: `{"categories": [...]}` — the 7 seeded categories in `sort_order`, each `{id, slug, name_en, name_ar, description_en, description_ar, sort_order}`.
