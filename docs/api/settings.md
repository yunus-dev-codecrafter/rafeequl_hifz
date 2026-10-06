# API — Settings & account

Status: implemented (Prompt 18; export formats & import contract with Prompt 23). Base path `/api/v1`. Every route requires a session (`AuthMiddleware`).

Settings (التفضيلات) cover the user's preferences — theme, sound, screen awake, language and the default revision target — plus the account/privacy controls: profile edits, password change, personal-data export and account deletion. Preferences live entirely in `user_settings` and this domain **never writes Hifz tables**; deletion is a soft delete that anonymizes the identity while retaining the Hifz history pseudonymously.

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/settings` | session | Read preferences (self-healing) → `200` |
| PUT | `/settings` | session | Partial preference update → `200` / `422` |
| PUT | `/auth/profile` | session | Edit display name / email → `200` / `422` |
| PUT | `/auth/password` | session | Change password (revokes all sessions) → `200` / `422` |
| GET | `/account/export` | session | Personal-data export: JSON (default) or `?format=csv` → `200` / `422` |
| DELETE | `/account` | session | Password-confirmed soft delete → `200` / `422` |

Envelope is always `{ok, data, errors}`; `400` malformed JSON, `401` unauthenticated (or a revoked session), `404` unknown user, `422` validation. There is no 409: writing the value a field already has is an idempotent no-op. The only body that is not an envelope is the CSV export (`format=csv`), which returns the raw file; its *error* responses are still envelopes.

## Settings object

`GET /settings` → `data.settings`:

```json
{
  "theme": "auto",
  "sound_enabled": true,
  "screen_awake_enabled": false,
  "locale": "ar",
  "daily_revision_unit": "page",
  "daily_revision_amount": 1.0
}
```

| Field | Values | Notes |
| --- | --- | --- |
| `theme` | `auto`, `light`, `dark` | API-facing names; stored as the schema enum `auto`/`day`/`night` (mapping below). |
| `sound_enabled` | boolean | Interaction/feedback sound. |
| `screen_awake_enabled` | boolean | Wake-lock preference (only applied where the browser supports it). |
| `locale` | `ar`, `en` | Stored but **inert** until an internationalization prompt ships: Arabic is the product language and English is presented as "قريباً" (disabled) in the sheet. |
| `daily_revision_unit` | `page`, `hizb`, `rub`, `juz` | Default target unit for **future** plan creation. |
| `daily_revision_amount` | number `0.1`–`9999` | Rounded to 3 decimals (`DECIMAL(6,3)`); default `1`. |

Register creates the row with `theme='auto'` and `locale='ar'` (Arabic-first). `GET /settings` self-heals a missing row with schema defaults, so the endpoint never 404s for an existing user.

### Theme mapping

| API value | Stored enum |
| --- | --- |
| `auto` | `auto` |
| `light` | `day` |
| `dark` | `night` |

The mapping lives in `App\Models\ThemeMode`; the API never leaks the storage names. The one exception is the personal-data export (`GET /account/export`), which carries the raw stored row (`day`/`night`) — see [`export.md`](export.md).

### Partial update semantics

`PUT /settings` accepts any subset of the six fields:

- Only provided, non-empty fields change; everything else is untouched (`{"sound_enabled": false}` flips one switch).
- An explicit empty string (`""`) — or whitespace-only input — is normalized to "not provided" by the validator, so the field keeps its current value (same convention as task edits).
- Same-value writes are idempotent: `200` with the representation, no `updated_at` churn beyond the write itself.
- Validation (`UpdateSettingsValidator`): theme/locale/unit `in:` whitelists, booleans, amount `numeric|min:0.1|max:9999`. Failures are `422` with the offending field and persist nothing.
- **Unit/amount pair**: the *resulting* stored pair must be one plan creation can consume — for `hizb`/`juz`/`rub` the amount must be a whole number ≥ 1, otherwise `422` (field `daily_revision_amount`). A partial update that would only change one half is checked against the stored value of the other half, so a `200` here can never make the next plan creation fail.

### Preference vs Hifz data (boundary)

`SettingsService` only ever reads/writes `user_settings`. A preference change can never rewrite memorization progress, plans, cycles, segments, sessions, cards or tasks (asserted by a test that counts Hifz rows before/after an update).

The revision defaults influence **only** plan creation that omits an explicit target (`RevisionService::resolveTarget`):

- `createPlan(unit=null, amount=null)` → falls back to the stored defaults.
- `createPlan('page', 4, ...)` → explicit values win.
- An **existing** plan keeps its `target_unit`/`daily_amount` when the preferences change afterwards — segments already generated are never rewritten.

The UI states this consequence next to the control ("تُطبَّق على الخطط الجديدة فقط") before saving.

### Device vs account preferences

| Preference | Storage | Rationale |
| --- | --- | --- |
| Theme, sound, screen awake | localStorage + server (`PUT /settings` write-through) | Signed-in users get the same values on every device: on boot `reconcileFromServer()` applies the server copy over the local cache. |
| Revision defaults, locale | server | Meaningful account data; the sheet loads them from `GET /settings`. |
| Desktop notifications | localStorage only (`rafeeq.notify`) | The browser permission itself is bound to the device — there is nothing meaningful to sync. |

Write-through failures roll back by re-reconciling from the server and surface `notify.error`.

## Profile

`PUT /auth/profile` body: `{"display_name"?: string (≤100), "email"?: valid email (≤190)}` → `data.user` (the safe profile: `id`, `email`, `display_name`, `status`, `created_at`).

- Values are trimmed; the email is lowercased.
- `""` for email is rejected (`422`, field `email`) — empty input means "not provided", never "clear my email".
- Duplicate email → `422` field `email`. Unknown user → `404`.
- No-op payloads (`{}`) are allowed and change nothing.

## Password change

`PUT /auth/password` body: `{"current_password", "new_password"}` (both required, 8–72 chars).

1. `current_password` must verify against the stored hash (`422` field `current_password`, with the same timing equalization as login).
2. New must differ from current (`422` field `new_password`).
3. On success **every session is revoked** and the caller's cookie is cleared — the browser must sign in again (same posture as password reset). Status `200`.

## Export

`GET /account/export` (default JSON) returns the full personal data as `Content-Disposition: attachment; filename="rafeequl-hifz-export.json"`; `?format=csv` returns the same data as a sectioned CSV file (`text/csv`, `rafeequl-hifz-export.csv`), optionally narrowed with `?dataset=<key>`. Unknown `format`/`dataset` → `422`. The full format specification and the **future import/restore contract** live in [`docs/api/export.md`](export.md).

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
  "task_types": [], "flip_card_categories": []
}
```

- Every dataset is scoped `WHERE user_id = ?` — one export can never contain another user's rows.
- `task_types` / `flip_card_categories` are **referenced-vocabulary subsets** (only the seeded rows this user's tasks/cards point at): a future importer resolves foreign keys by `slug`, and a human reads "murajaah" instead of an id.
- **No secrets**: the profile query never selects `password_hash`, and sessions/tokens/reset material are never queried (asserted by tests scanning both JSON and CSV for `password_hash`/`token_hash`).

## Account deletion (soft delete)

`DELETE /account` body: `{"password"}` (required, 8–72) — the password is re-verified before anything happens (`422` field `password`, timing-equalized; a rejected attempt changes nothing).

One transaction then:

1. **Anonymize**: `users.status → 'deleted'`, `display_name → ''`, email freed and rewritten to `deleted+{id}@deleted.invalid` so the address can be registered again.
2. **Revoke**: every session row is deleted and the caller's cookie is cleared (`200`).
3. **Retain pseudonymously**: the Hifz history (memorization, revision, rabt, cards, tasks) stays attached to the tombstone row — it no longer carries any personal identifier.

The tombstone can never sign in again: the status check and the missing email both fail with `401 Invalid credentials`. This is the schema's designed delete (`ON DELETE CASCADE` is not used for users, so history survives without identity).

## Frontend map

| File | Responsibility |
| --- | --- |
| `public/js/features/settings/settings-api.js` | HTTP for the settings/account endpoints (unwraps `data.settings` / `data.user`) + export wrappers (JSON payload, raw CSV text via `api.getText`). |
| `public/js/features/settings/settings-sync.js` | `reconcileFromServer()` — applies server preferences to the device cache at boot. |
| `public/js/features/settings/settings-sheet.js` | The settings bottom sheet: display/sound switches, theme cycle, locale row, revision defaults + consequence hint, profile/password forms, export (JSON + CSV buttons) / delete. |
| `public/js/components/modal.js` | Centered confirmation modal (delete-account dialog). |
| `public/shell.html` | `sheet-settings` (+ privacy export/CSV/delete buttons) + `modal-delete-account` templates. |
| `tests/Unit/SettingsValidatorsTest.php` | Validator rules (27 checks). |
| `tests/Integration/{Settings,Account}Test.php` | Round-trips, mapping, boundary, export (JSON + CSV), soft delete (41 + 56 checks). |
| `tests/Unit/ValidatorEmptyStringTest.php` + `tests/Integration/QaEdgeCasesTest.php` | Prompt 24: empty input normalizes to keep-current, `email: ""` 422, unit/amount pair validation (24 + 32 checks, shared with the other QA rules). |
| `%TEMP%\opencode\test_qa.ps1` | HTTP-level QA smoke incl. settings pair and empty-input contract (57 checks). |
