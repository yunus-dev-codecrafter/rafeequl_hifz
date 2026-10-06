# API — Data export & backup (Prompt 23)

Status: implemented (Prompt 23; the JSON endpoint itself ships with Prompt 18). Base path `/api/v1`. Both routes require a session (`AuthMiddleware`).

The export is the user's portable copy of their personal data: a **JSON bundle** (the machine-readable, import-oriented format) and a **sectioned CSV** (the human/spreadsheet format). Both are rendered from the same whitelisted query results, so the two formats are equivalent by construction.

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/account/export` | session | Full personal data as a JSON attachment → `200` |
| GET | `/account/export?format=csv` | session | Full personal data as a sectioned CSV attachment → `200` |
| GET | `/account/export?format=csv&dataset=<key>` | session | One CSV section only → `200` |

Query parameters:

| Parameter | Values | Notes |
| --- | --- | --- |
| `format` | `json` (default), `csv` | Anything else → `422` field `format`. |
| `dataset` | an export dataset key (below) | Only with `format=csv`; unknown key → `422` field `dataset`. With `format=json` → `422` field `dataset` (the filter is a CSV concept). |

Responses:

* **JSON** — the standard envelope `{ok, data, errors}` (the client unwraps `data`), with `Content-Disposition: attachment; filename="rafeequl-hifz-export.json"`.
* **CSV** — **raw** `text/csv; charset=utf-8`, body = the file itself (no envelope), with `Content-Disposition: attachment; filename="rafeequl-hifz-export.csv"`. Error responses (401/422) are still JSON envelopes; `public/js/core/api-client.js::getText()` parses those and throws `ApiError`, otherwise returns the text.

## JSON bundle

```json
{
  "exported_at": "2026-10-05T12:00:00+00:00",
  "format": "rafeequl-hifz-export-v1",
  "profile": { "id": 1, "email": "...", "display_name": "...", "status": "active", "email_verified_at": null, "last_login_at": null, "created_at": "...", "updated_at": "..." },
  "settings": { "...": "user_settings row" },
  "memorization_state": { "...": "or null" },
  "memorization_history": [],
  "memorization_boundary_history": [],
  "revision_plans": [], "revision_cycles": [], "revision_segments": [], "revision_sessions": [],
  "rabt_sessions": [], "rabt_page_progress": [],
  "flip_cards": [], "flip_card_reviews": [],
  "daily_tasks": [], "task_completions": [],
  "task_types": [],
  "flip_card_categories": []
}
```

Dataset keys (17, in this order): `profile`, `settings`, `memorization_state` are single rows (assoc arrays, `memorization_state` may be `null`); the remaining 14 are lists.

The last two — `task_types` and `flip_card_categories` — are **referenced-vocabulary subsets**: only the seeded rows the user's `daily_tasks.task_type_id` / `flip_cards.category_id` actually point at, carrying `id`, `slug`, names and category metadata. They exist so a future importer can resolve foreign keys by `slug` (stable across installs) instead of trusting auto-increment ids, and so a human reading the file sees "murajaah" instead of an opaque id.

**Values are the raw stored rows, not the API's presentation mapping.** The one place where the two differ is `settings.theme`: the export (JSON and CSV) carries the database value `day` / `night` / `auto`, while `GET /api/v1/settings` represents the same preference as `light` / `dark` / `auto`. An importer must write `day`/`night` back as-is (it is what `user_settings.theme` expects); a human mapping this file to the API reads `day` → `light`, `night` → `dark`. Nothing else in the bundle is translated.

## CSV format

One file, one section per dataset — **every JSON dataset key becomes a section**:

```text
# dataset: profile
id,email,display_name,status,...
1,....

# dataset: daily_tasks
id,user_id,task_type_id,title,scheduled_date,...
1,1,1,,2026-10-05,...
```

* Each section: a `# dataset: <key>` marker line (fputcsv may quote it), then the column-header row (`array_keys` of the first row), then the data rows — **same keys, same order, same rows as the JSON export**.
* Sections are separated by one blank line.
* Empty dataset (no rows, or a `null` single row) → marker only; no header (the column set is unknowable without a row).
* Encoding: UTF-8 **with BOM** (Excel detects Arabic), `fputcsv` quoting/escaping, `\n` line endings.
* Cell normalization: `NULL` → empty cell, booleans → `0`/`1`, everything else scalar → its string form; rows keep their JSON order (`ORDER BY id`, `rabt_page_progress` by `page_number`).
* `?dataset=<key>` emits only that one section (marker + header + rows).

CSV is a faithful rendering for humans and spreadsheets; **JSON is the canonical, import-oriented format**.

## What is never exported

Structurally impossible — the queries are column/table whitelists, not post-filtering:

* passwords / `user_auth.password_hash` (or any credential material),
* `user_sessions.token_hash` (or any session credential),
* `password_reset_tokens` (reset secrets),
* server secrets / config / keys.

Tests scan both formats for `password_hash` and `token_hash`, and assert another user's email can never appear (every query is `WHERE user_id = ?`).

## Import/restore contract (future system)

A future import/restore can rely on:

1. **Version marker** — `format: "rafeequl-hifz-export-v1"`. *Additive* changes (new keys/fields) stay on `-v1`; any breaking change (renamed/removed keys, changed semantics) bumps to `-v2`. An importer must refuse unknown majors.
2. **Stable dataset keys and deterministic order** — the 17 keys above, rows ordered by `id` (or `page_number`): two exports of unchanged data are byte-comparable, which enables diff/merge and dry-run tooling.
3. **Original ids are preserved** — every row keeps its `id` and `user_id`; an importer must **remap** them (old → new) rather than trust them, and re-resolve `daily_tasks.task_type_id` / `flip_cards.category_id` through the exported vocabulary subsets (matching by `slug`) or the live seeds.
4. **No secrets, structurally** — an import can only ever write the same whitelisted columns; it can never create credentials, sessions or reset tokens from a bundle (credentials are re-established by registration/password flows).
5. **Format roles** — JSON is the restore input; CSV is a view of the same arrays (never a divergent source of truth).
6. **Restore semantics are the importer's decision** — the export itself makes no merge/replace promises; the future importer defines them (recommended: dry-run report first, then per-dataset insert/replace behind explicit user confirmation).

## Ownership

* `app/Repositories/AccountRepository.php` — whitelisted read-only queries (17 datasets), all `WHERE user_id = ?`.
* `app/Services/AccountService.php` — `export()` (JSON bundle) and `exportCsv()` (sectioned CSV renderer, dataset validation).
* `app/Controllers/AccountController.php` — format/dataset query validation (`422`), attachment headers.
* `public/js/features/settings/settings-api.js` + `settings-sheet.js` — two export buttons, Blob downloads.
* `public/js/core/api-client.js` — `api.getText()` (raw-body GET for file downloads; JSON errors still raise `ApiError`).

## Verification

* `tests/Integration/AccountTest.php` — JSON keys/secrets/isolation plus CSV: BOM, 17 sections, header row equals JSON keys, dataset filter, `422`, secret scans (56 checks).
* `tests/Integration/QaEdgeCasesTest.php` — CSV cells starting with `=` are neutralized while the JSON export keeps the raw value (Prompt 24).
* `%TEMP%\opencode\test_export.ps1` — 401 gates, JSON additive keys, CSV 200/headers/sections/filter, `422` on `format`/`dataset`, shell button + asset probes (38 checks).
