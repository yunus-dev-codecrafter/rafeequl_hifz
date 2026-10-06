# Database Schema — Rafeequl Hifz

Status: **implemented as migration files** (Prompt 05). DDL only — no application features, no Quran data rows.
Location: `docs/database/schema.md`

## 1. How to apply

Migrations are plain SQL files in `database/migrations/`, applied **in numeric order, once each**:

```text
0001_schema_migrations.sql   migration registry (version + file checksum + applied_at)
0002_quran_canonical.sql     quran_* reference tables (structure only — empty)
0003_users_auth.sql          users, user_auth, user_sessions, user_settings
0004_hifz.sql                memorization state + history
0005_revision.sql            plans, cycles, segments, sessions (المراجعة)
0006_rabt.sql                rolling ربط activity + page tracking
0007_flip_cards.sql          categories (seeded), cards, reviews
0008_productivity.sql        task types (seeded), daily tasks, completions
0009_password_resets.sql     password reset tokens
0010_flip_cards_status_in_review.sql  ALTER flip_cards.status += in_review
0011_rate_limits.sql        fixed-window throttle counters (SHA-256 bucket keys)
```

Rules:

- Apply in order; each file assumes all earlier files ran.
- Files are **run-once** (no `DROP`/`CREATE IF NOT EXISTS`) so schema drift is never hidden; `schema_migrations` records what ran, with the SHA-256 of the file.
- Engine: InnoDB, `utf8mb4` / `utf8mb4_unicode_ci` everywhere (Arabic-safe).
- `updated_at` is maintained by application code — **no triggers**.
- `0002` inserts **zero Quran rows**; canonical data enters only via the verified importer after verification (see `docs/quran-data/data-architecture.md`).

## 2. Entity overview

```text
users ──< user_auth            (1:1)
     ──< user_settings         (1:1)
     ──< user_sessions         (1:n)
     ──< memorization_states   (1:1)
     ──< memorization_history  (1:n, append-only)
     ──< memorization_boundary_history (1:n, append-only)
     ──< revision_plans ──< revision_cycles ──< revision_segments ──< revision_sessions
     ──< rabt_sessions ──< (last_session) ── rabt_page_progress
     ──< flip_cards ──< flip_card_reviews          categories: flip_card_categories (seeded)
     ──< daily_tasks ──< task_completions          types:      task_types (seeded)

quran_surahs ──< quran_ayahs ──< quran_page_ayahs >── quran_pages
quran_division_types ──< quran_divisions (juz / hizb / rubʿ, extensible)
quran_dataset_meta (provenance)
```

User tables hold Quran **coordinates** (`surah_number`, `ayah_number`, `page_number`) as plain integers — deliberately **no FK into `quran_*`**, so a dataset re-import can never orphan user history. Integrity is enforced at write time by `App\Validators` against the canonical data (architecture §9).

## 3. Domain tables

### 3.1 Users / auth / settings (`0003`)

| Table | Purpose | Key columns |
| --- | --- | --- |
| `users` | profile | `email` (unique), `display_name`, `status` `active\|suspended\|deleted`, `email_verified_at`, `last_login_at` |
| `user_auth` | credentials, 1:1 | `password_hash` (never plaintext), `failed_login_count`, `locked_until` |
| `user_sessions` | persistent logins | `token_hash` (raw token never stored, unique), `last_seen_at`, `expires_at` |
| `user_settings` | dashboard controls | `theme` `day\|night\|auto`, `sound_enabled`, `screen_awake_enabled`, `locale` `en\|ar`, `timezone`, default revision target (`daily_revision_unit`, `daily_revision_amount`) |

### 3.2 Quran structure (`0002`)

Exactly the architecture model: `quran_surahs`, `quran_ayahs`, `quran_pages`, `quran_page_ayahs`, `quran_division_types`, `quran_divisions`, `quran_dataset_meta`. Read-only to the application; loaded only by the verified importer. **Currently empty by design.**

### 3.3 Hifz / memorization (`0004`)

| Table | Purpose | Key columns |
| --- | --- | --- |
| `memorization_states` | current state, 1:1 | `memorized_start_page`, `current_boundary_page`, `status` `active\|paused`, `last_boundary_changed_at` |
| `memorization_history` | append-only page log | `page_number`, `memorized_at`, `source` `manual\|import`, `note` |
| `memorization_boundary_history` | append-only boundary changes | `previous_boundary_page`, `new_boundary_page`, `changed_at`, `reason` `manual\|recalc\|restart` |

No unique on `(user_id, page_number)` in history on purpose: a repeated record is itself historical information (re-memorization), never overwritten.

### 3.4 Revision — المراجعة (`0005`)

Hierarchy: `revision_plans` → `revision_cycles` → `revision_segments` → `revision_sessions`.

| Table | Purpose | Key columns |
| --- | --- | --- |
| `revision_plans` | one pass config | `target_unit` `page\|hizb\|rub\|juz`, `daily_amount` (DECIMAL, allows e.g. half a hizb), `range_start/end_page`, `boundary_page_snapshot`, `status` `active\|paused\|completed` |
| `revision_cycles` | a completed pass generation | `cycle_number` (unique per plan), `boundary_page_snapshot` = boundary **at generation time**, `segment_count`, `status` `pending\|active\|completed\|superseded` |
| `revision_segments` | daily chunks | `segment_number` (unique per cycle), `start/end_page`, `page_count` (final segment may be smaller), `scheduled_date`, `status` `pending\|active\|completed\|skipped` |
| `revision_sessions` | attempts, exact progress | `status` `completed\|partial\|interrupted`, `total_pages`, `pages_completed`, `last_page_reached`, `started_at`/`ended_at`, `duration_seconds`, `resumes_session_id` (self-FK chain after interruption) |

Guarantees encoded here:

- Segments never extend past the cycle's captured range → revision is never scheduled beyond the memorized boundary.
- The next cycle is generated from the **current** boundary (new `revision_cycles` row with a new snapshot), never by rewriting old ones.
- An interrupted session keeps `last_page_reached` / `pages_completed` verbatim; resuming adds a linked row instead of mutating history.
- `CHECK (pages_completed <= total_pages)`.

### 3.5 ربط (`0006`)

| Table | Purpose | Key columns |
| --- | --- | --- |
| `rabt_sessions` | rolling activity log | window snapshot (`window_start/end_page`, `boundary_page_snapshot`), `pages_total`/`pages_reviewed`, `status` `completed\|partial\|interrupted` |
| `rabt_page_progress` | per-page tracking | unique `(user_id, page_number)`, `times_reviewed`, `first/last_reviewed_at`, `last_session_id` |

- `CHECK (window_end - window_start + 1 <= 30)` encodes the 30-page maximum as defense-in-depth beside the service-level constant.
- The current rolling window is **derived** from `memorization_states.current_boundary_page` (server-side) — never stored, so it cannot go stale.

### 3.6 Flip cards (`0007`)

| Table | Purpose | Key columns |
| --- | --- | --- |
| `flip_card_categories` | seeded vocabulary, unique `slug` | 7 system categories (see §4) |
| `flip_cards` | the flagged error | Quran coordinates, `error_note`, `context_note`, `severity` `low\|medium\|high`, `status` `active\|in_review\|mastered\|archived` (`in_review` added by `0010`), `review_count`, `next_review_at`, `mastered_at` |
| `flip_card_reviews` | review history | `result` `recalled\|partial\|forgotten`, `reviewed_at`, `duration_seconds` |

Cards stay queryable after leaving the active queue; only `status` changes — reviews are never deleted while the card exists. `category_id` is `ON DELETE RESTRICT`.

### 3.7 Productivity (`0008`)

| Table | Purpose | Key columns |
| --- | --- | --- |
| `task_types` | seeded vocabulary, unique `slug` | `category` `quran\|general`, `default_duration_minutes`, `is_active` |
| `daily_tasks` | the task | `scheduled_date`, `duration_minutes`, `status` `pending\|active\|completed\|skipped`, optional custom `title`, `completed_at`, `actual_duration_seconds` |
| `task_completions` | completion history | unique `task_id` (one completion), `completed_at`, `duration_seconds` |

Completion percentage / counts are computed server-side from `daily_tasks` for the dashboard.

### 3.8 Security counters (`0011`)

| Table | Purpose | Key columns |
| --- | --- | --- |
| `rate_limits` | fixed-window throttle for the unauthenticated auth endpoints (register / forgot / reset) — audit finding F-03 | `bucket_key` `CHAR(64)` (SHA-256 of route+IP / route+e-mail — **never the raw values**), `window_started_at`, `attempts`, `updated_at` |

Standalone (no `user_id` / no FK): buckets exist for requests that may have no account at all. Rows are pruned opportunistically once idle for over a day; the window resets through an atomic `INSERT … ON DUPLICATE KEY UPDATE`.

## 4. Seeded reference data (application vocabulary — not Quran data)

`flip_card_categories`: `forgotten_adjacent_ayah`, `verse_mistake`, `similar_ayah_confusion`, `hesitation`, `recurring_mistake`, `weak_location`, `other` (each with English + Arabic names).

`task_types` (quran): `murajaah` (مراجعة), `rabt` (ربط), `new_memorization` (حفظ جديد), `flip_card_review` (مراجعة البطاقات); (general): `personal` (مهمة شخصية).

No surah, ayah, page, juz, hizb, or rubʿ values are inserted anywhere in the migrations.

## 5. Status machines

```text
revision_plans:      active ⇄ paused → completed
revision_cycles:     pending → active → completed   (superseded = replaced by a regenerated cycle)
revision_segments:   pending → active → completed | skipped
revision_sessions:   started → completed | partial | interrupted ─(resumes_session_id)→ new session
rabt_sessions:       started → completed | partial | interrupted
flip_cards:          active → in_review → mastered → (archived)   [any cross-state move allowed, same state = no-op; review history retained]
daily_tasks:         pending → active → completed | skipped   (skipped must be reopened before completion)
users:               active ⇄ suspended → deleted
memorization_states: active ⇄ paused
```

All enums are `ENUM` columns; adding a state requires an `ALTER` migration (never an in-place edit of an applied file).

## 6. History policy (boundary changes)

A user's memorization boundary will move over time. Historical records stay understandable because:

1. Every generation level **snapshots** the boundary active when it was created (`revision_plans.boundary_page_snapshot`, `revision_cycles.boundary_page_snapshot`, `rabt_sessions.boundary_page_snapshot`).
2. Range columns (`range_*_page`, `start_page`/`end_page`, window pages) are stored, never re-derived from today's boundary.
3. History tables (`memorization_history`, `memorization_boundary_history`, `flip_card_reviews`, `task_completions`, session rows) are **append-only** — changes create rows, they don't rewrite them.
4. `revision_cycles.status = superseded` marks regeneration without destroying the old cycle.

## 7. Constraints & indexes applied

- **PKs**: auto-increment `id` for user-owned entities; natural/composite keys for reference and link tables.
- **FKs**: user-owned tables → `users` (`ON DELETE CASCADE`); child→parent within a domain (`CASCADE`); `flip_cards.category_id` / `daily_tasks.task_type_id` → `RESTRICT`; `rabt_page_progress.last_session_id` → `SET NULL`; `revision_sessions.resumes_session_id` → `SET NULL`. **No FKs into `quran_*`** (§2).
- **Unique**: `users.email`, `user_sessions.token_hash`, `(plan_id, cycle_number)`, `(cycle_id, segment_number)`, `(user_id, page_number)` in ربط tracking, `flip_card_categories.slug`, `task_types.slug`, `task_completions.task_id`.
- **Composite indexes** on the hot access paths: `(user_id, status)`, `(user_id, scheduled_date[, status])`, `(user_id, started_at)`, `(user_id, next_review_at)`, `(division_type, start_page, end_page)` in `quran_divisions`.
- **CHECK**: positive page/ayah/surah numbers, ordered ranges, `pages_completed <= total_pages`, `pages_reviewed <= pages_total`, ربط window ≤ 30 pages, `daily_amount > 0`, `duration_minutes > 0`. (Parsed but unenforced on MySQL < 8.0.16 — services must still validate; enforcement is defense-in-depth.)

## 8. Out of scope here

- No application features, queries, or repository code.
- No Quran data rows (importer + verification arrive with a later prompt).
- No passwords/credentials/secrets in any file.
