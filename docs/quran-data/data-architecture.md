# Canonical Quran Data Architecture — Madinah Mushaf

Status: **implemented (Prompts 04 + 24A)** — `quran_*` tables exist (migration `0002`) and the verified Tanzil dataset is imported (sources, checksums and results: `docs/quran-data/dataset-pipeline.md`). This document still records no boundary values by design — read them from the dataset.
Location: `docs/quran-data/data-architecture.md`

This document defines how the verified Madinah Mushaf structural dataset will be modeled, stored, imported, and verified. It is the authority for any later migration or importer built against these rules.

**Hard rule:** every boundary value in this system comes from a verified authoritative dataset. Nothing in this document records, estimates, or implies any actual page/ayah/juz boundary. Expected *row totals* (e.g. how many surahs exist) are used only as verification invariants, and each total must be confirmed against the chosen source during import — never hard-coded as application logic.

---

## 1. Principles

1. **Source of truth is layered:** raw authoritative file → normalized files → verified files → database. Each layer is reproducible from the one above it.
2. **Immutable.** Canonical rows are never `UPDATE`d or `DELETE`d by application code. A correction means a new dataset version + full re-verification.
3. **Separate.** Canonical Quran data lives in `quran_*` tables and `data/quran/`. It never mixes with user-generated data (no user columns, no timestamps of user origin, no FK cascades from user data into it).
4. **Server-authoritative.** All range/location calculations run in PHP/MySQL against these tables. Nothing is computed in JavaScript.
5. **Two independent representations are kept on purpose** (page ranges vs. page→ayah segments) so verification can cross-check them against each other.
6. **Fail closed.** If any verification check fails, import stops and the database stays untouched.

## 2. Storage architecture

```text
data/quran/madinah/
├── source/         raw, untouched download + SOURCE.md + SHA-256 sidecar files
├── processed/      machine-generated normalized JSON (regenerable, never hand-edited)
└── verification/   verification reports (one JSON per run: checks, counts, checksums, verdict)

tools/quran-data/   normalize.php · verify.php · import.php · audit.php  (contracts defined in §7–8)
database/migrations/  the quran_* DDL lands here when implementation is authorized
```

Two runtime forms of the same data:

| Form | Role | Mutability |
| --- | --- | --- |
| JSON files in `processed/` | reproducible artifact of the source; input to verification and tools | regenerated only |
| `quran_*` MySQL tables | runtime read path for the application | read-only to the app; written only by the importer |

`quran_dataset_meta` records which file-set version is loaded, so the app and audits can prove which dataset it is running against.

## 3. Data model

```text
quran_division_types ──< quran_divisions
                                │ start/end (surah, ayah) FK
quran_surahs ──< quran_ayahs >──┴── quran_page_ayahs >── quran_pages
     │                                (page, surah, ayah segments)
     └── start_page / end_page (verified, no FK — cycle)
quran_dataset_meta  (standalone key/value)
```

> DDL implemented in `database/migrations/0002_quran_canonical.sql` (Prompt 05) — matches the block below. The migration still inserts no data; rows arrive only via the verified importer.

### 3.1 `quran_surahs` — 1 row per surah (114 expected; confirm against source)

| Field | Type | Notes |
| --- | --- | --- |
| `surah_number` | `TINYINT UNSIGNED` PK | canonical surah order, from source |
| `name_arabic` | `VARCHAR(64)` | from source |
| `name_transliteration` | `VARCHAR(64)` | from source |
| `name_english` | `VARCHAR(64)` | from source |
| `revelation_type` | `ENUM('meccan','medinan')` | value taken from source, never assumed |
| `ayah_count` | `SMALLINT UNSIGNED` | from source; must equal count of its ayah rows |
| `start_page` / `end_page` | `SMALLINT UNSIGNED` | no FK (would create a cycle); verified to equal min/max of its ayahs' pages |

### 3.2 `quran_ayahs` — 1 row per ayah (6236 expected for Hafs/Kufi counting; confirm source counting)

| Field | Type | Notes |
| --- | --- | --- |
| `surah_number` | `TINYINT UNSIGNED` | composite PK part, FK → `quran_surahs` |
| `ayah_number` | `SMALLINT UNSIGNED` | composite PK part (within surah) |
| `ayah_index` | `INT UNSIGNED` UNIQUE | global ordinal from source — enables “next ayah” arithmetic across surah boundaries |

Ayah **text is out of scope** for this stage (the physical Mushaf is the primary reading source). If added later it comes from a separate verified Uthmani source and a separate table.

The page where an ayah starts is **not** stored here — it is derived from `quran_page_ayahs` (`MIN(page_number)` for that ayah), avoiding duplicate/circular data.

### 3.3 `quran_pages` — 1 row per Mushaf page (604 expected for the Madinah print; confirm source)

| Field | Type | Notes |
| --- | --- | --- |
| `page_number` | `SMALLINT UNSIGNED` PK | contiguous, no gaps (invariant) |
| `start_surah`, `start_ayah` | | composite FK → `quran_ayahs` (ayāh starting this page) |
| `end_surah`, `end_ayah` | | composite FK → `quran_ayahs` (ayāh ending this page) |

### 3.4 `quran_page_ayahs` — page ↔ ayah segments (the exact “what is on this page” relation)

An ayah may span several pages; each page it appears on gets one segment row.

| Field | Type | Notes |
| --- | --- | --- |
| `page_number` | | composite PK part, FK → `quran_pages` |
| `surah_number`, `ayah_number` | | composite PK part, FK → `quran_ayahs` |
| `segment_order` | `SMALLINT UNSIGNED` | position of this ayah among the page’s ayahs |
| `segment_index` | `INT UNSIGNED` UNIQUE | global ordinal of the segment (contiguity checks) |
| `continues_previous` | `TINYINT(1)` | segment resumes an ayah begun on the previous page |
| `continues_next` | `TINYINT(1)` | segment continues onto the next page |

PK `(page_number, surah_number, ayah_number)` — an ayah appears at most once per page.

### 3.5 `quran_division_types` — extensible registry (answers “other verified divisions”)

| Field | Notes |
| --- | --- |
| `type_key` PK | `'juz'`, `'hizb'`, `'rub'`, … later divisions added without schema change |
| `name_arabic`, `name_english` | labels for UI |
| `parent_type_key` NULL FK → self | `hizb → juz`, `rub → hizb`; NULL for the top type |
| `per_parent` NULL | expected count of children per parent — seeded from source metadata and verified, not assumed |
| `count_expected` NULL | expected total, used only as a verification invariant once confirmed by the source |

### 3.6 `quran_divisions` — 1 row per division instance

| Field | Type | Notes |
| --- | --- | --- |
| `division_type` | | composite PK part, FK → `quran_division_types` |
| `division_number` | `SMALLINT UNSIGNED` | composite PK part (numbering restarts per type) |
| `parent_type`, `parent_number` | NULL | explicit parent (e.g. rub → its hizb), taken from source and cross-checked against arithmetic |
| `start_surah`, `start_ayah` | | composite FK → `quran_ayahs` |
| `end_surah`, `end_ayah` | | composite FK → `quran_ayahs` |
| `start_page`, `end_page` | | denormalized from its ayahs; **verified** against them (kept for fast range overlap queries) |

Pages are intentionally **not** rows in this table: pages need the segment relation (§3.4) and get their own table.

### 3.7 `quran_dataset_meta` — key/value provenance

`meta_key` PK, `meta_value` `TEXT`. Holds: dataset name, source URL, source license, source file SHA-256(s), processed file checksums, dataset version, normalization tool version, verification report reference, `imported_at` (UTC), importer version, verified counts.

### 3.8 Proposed DDL (documentation only — no migration file yet)

```sql
CREATE TABLE quran_surahs (
  surah_number        TINYINT UNSIGNED NOT NULL,
  name_arabic         VARCHAR(64)  NOT NULL,
  name_transliteration VARCHAR(64) NOT NULL,
  name_english        VARCHAR(64)  NOT NULL,
  revelation_type     ENUM('meccan','medinan') NOT NULL,
  ayah_count          SMALLINT UNSIGNED NOT NULL,
  start_page          SMALLINT UNSIGNED NOT NULL,
  end_page            SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (surah_number),
  KEY idx_quran_surahs_pages (start_page, end_page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_ayahs (
  surah_number TINYINT UNSIGNED NOT NULL,
  ayah_number  SMALLINT UNSIGNED NOT NULL,
  ayah_index   INT UNSIGNED NOT NULL,
  PRIMARY KEY (surah_number, ayah_number),
  UNIQUE KEY uq_quran_ayahs_index (ayah_index),
  CONSTRAINT fk_quran_ayahs_surah FOREIGN KEY (surah_number)
    REFERENCES quran_surahs (surah_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_pages (
  page_number SMALLINT UNSIGNED NOT NULL,
  start_surah TINYINT UNSIGNED  NOT NULL,
  start_ayah  SMALLINT UNSIGNED NOT NULL,
  end_surah   TINYINT UNSIGNED  NOT NULL,
  end_ayah    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (page_number),
  CONSTRAINT fk_quran_pages_start FOREIGN KEY (start_surah, start_ayah)
    REFERENCES quran_ayahs (surah_number, ayah_number),
  CONSTRAINT fk_quran_pages_end FOREIGN KEY (end_surah, end_ayah)
    REFERENCES quran_ayahs (surah_number, ayah_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_page_ayahs (
  page_number        SMALLINT UNSIGNED NOT NULL,
  surah_number       TINYINT UNSIGNED  NOT NULL,
  ayah_number        SMALLINT UNSIGNED NOT NULL,
  segment_order      SMALLINT UNSIGNED NOT NULL,
  segment_index      INT UNSIGNED NOT NULL,
  continues_previous TINYINT(1) NOT NULL DEFAULT 0,
  continues_next     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (page_number, surah_number, ayah_number),
  KEY idx_quran_page_ayahs_reverse (surah_number, ayah_number, page_number),
  UNIQUE KEY uq_quran_page_ayahs_index (segment_index),
  CONSTRAINT fk_quran_page_ayahs_page FOREIGN KEY (page_number)
    REFERENCES quran_pages (page_number),
  CONSTRAINT fk_quran_page_ayahs_ayah FOREIGN KEY (surah_number, ayah_number)
    REFERENCES quran_ayahs (surah_number, ayah_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_division_types (
  type_key       VARCHAR(24) NOT NULL,
  name_arabic    VARCHAR(64) NOT NULL,
  name_english   VARCHAR(64) NOT NULL,
  parent_type_key VARCHAR(24) NULL,
  per_parent     SMALLINT UNSIGNED NULL,
  count_expected SMALLINT UNSIGNED NULL,
  PRIMARY KEY (type_key),
  CONSTRAINT fk_quran_division_types_parent FOREIGN KEY (parent_type_key)
    REFERENCES quran_division_types (type_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_divisions (
  division_type   VARCHAR(24) NOT NULL,
  division_number SMALLINT UNSIGNED NOT NULL,
  parent_type     VARCHAR(24) NULL,
  parent_number   SMALLINT UNSIGNED NULL,
  start_surah TINYINT UNSIGNED  NOT NULL,
  start_ayah  SMALLINT UNSIGNED NOT NULL,
  end_surah   TINYINT UNSIGNED  NOT NULL,
  end_ayah    SMALLINT UNSIGNED NOT NULL,
  start_page  SMALLINT UNSIGNED NOT NULL,
  end_page    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (division_type, division_number),
  KEY idx_quran_divisions_overlap (division_type, start_page, end_page),
  KEY idx_quran_divisions_range (start_page, end_page),
  CONSTRAINT fk_quran_divisions_type FOREIGN KEY (division_type)
    REFERENCES quran_division_types (type_key),
  CONSTRAINT fk_quran_divisions_start FOREIGN KEY (start_surah, start_ayah)
    REFERENCES quran_ayahs (surah_number, ayah_number),
  CONSTRAINT fk_quran_divisions_end FOREIGN KEY (end_surah, end_ayah)
    REFERENCES quran_ayahs (surah_number, ayah_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_dataset_meta (
  meta_key   VARCHAR(64) NOT NULL,
  meta_value TEXT NULL,
  PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 4. Keys and indexes

- **Natural keys everywhere** (numbers as given by the Quran), no surrogate `id`s — the data is canonical and immutable, so natural keys cannot churn.
- Composite FKs enforce that every boundary points at a real ayah.
- `idx_quran_divisions_overlap (division_type, start_page, end_page)` serves the hot query: *divisions of type X overlapping page range [a,b]*.
- `uq_quran_ayahs_index` / `uq_quran_page_ayahs_index` guarantee global ordinals are unique and dense → contiguity is checkable by ordering on the index.
- `idx_quran_page_ayahs_reverse` serves *“where does surah:ayah appear?”* and *“page where the ayah starts”* (`MIN(page_number)`).

## 5. Integrity rules

**Structural (verified per import):**

1. Surah numbers are exactly `1..N` contiguous; ayah numbers per surah are exactly `1..count`; `SUM(ayah_count)` equals the source-stated total.
2. `ayah_index` is dense and strictly increasing in (surah order, ayah order) — no gaps, no duplicates.
3. Pages are exactly `1..P` contiguous; every page has one start and one end ayah.
4. **Adjacency:** for consecutive pages, the next page’s start follows the previous page’s end in global ayah order (a page boundary may split an ayah — that is expressed by `continues_*` flags, never by skipping ordinals).
5. **Segments:** the concatenated `segment_index` sequence is dense; page *n*’s first segment follows page *n−1*’s last segment; each page’s segments have `segment_order` `1..k` with no gaps.
6. **Cross-representation:** `quran_pages.start/end` (§3.3) must exactly match the min/max segment of that page (§3.4) — the two representations are redundant by design and must agree.
7. **Divisions:** within a type, ranges are contiguous and non-overlapping in global ordinal space (next `start` = previous `end` + 1 ordinally; boundary ayahs shared between divisions must be represented exactly as the source states, never approximately).
8. **Nesting:** every rub lies inside its hizb, every hizb inside its juz; `parent_*` equals what the numbering arithmetic implies, and both must match the source.
9. **Denormalized page spans:** each division’s `start_page`/`end_page` equal the pages of its start/end ayahs.
10. **Referential:** every FK resolves; no `NULL` in required boundary columns.
11. **Immutability policy:** no application code path issues `INSERT/UPDATE/DELETE` on `quran_*` tables (enforced by convention §14, by code review, and by DB grants where the host allows SELECT-only users).
12. **Totals** (`count_expected` in `quran_division_types`, row counts in `quran_dataset_meta`) are recorded from the *source file*, then re-checked against the imported rows.

## 6. Supported calculations (query contracts)

All are parameterized, index-backed, and never contain boundary literals. Provided as contracts — actual code belongs to later prompts.

| Need | Pattern |
| --- | --- |
| Pages in memorized range `[a,b]` | `SELECT page_number FROM quran_pages WHERE page_number BETWEEN :a AND :b` (density invariant makes the count `b−a+1`, which audit re-verifies) |
| Juz / Hizb / Rub overlapping `[a,b]` | `SELECT division_number FROM quran_divisions WHERE division_type = :t AND start_page <= :b AND end_page >= :a ORDER BY division_number` → uses `idx_quran_divisions_overlap` |
| Exact location of `surah:ayah` | `SELECT page_number FROM quran_page_ayahs WHERE surah_number = :s AND ayah_number = :a ORDER BY page_number` |
| Page → ayahs on it | `SELECT surah_number, ayah_number, segment_order, continues_* FROM quran_page_ayahs WHERE page_number = :p ORDER BY segment_order` |
| Division boundaries | `SELECT start_*, end_* FROM quran_divisions WHERE division_type = :t AND division_number = :n` |
| “Is page ≤ boundary valid?” (server-authoritative Hifz rules) | existence check against `quran_pages` + the user’s memorized boundary from **user tables** (never from Quran tables) |
| Page ↔ surah/ayah containment | `quran_surahs.start_page/end_page` for surah spans; segment lookups for ayah-level precision |

Counting a division that is *partially* inside a range returns the division as overlapping — exact business semantics for partial coverage is decided by a later feature prompt, never by this data layer.

## 7. Import strategy

Pipeline (each stage must pass before the next runs; tools live in `tools/quran-data/` and are created when implementation is authorized):

```text
acquire → checksum → normalize → verify → import → audit
```

1. **Acquire.** Download the structural dataset from an authoritative source (e.g. Tanzil Quran structure / an official Quran-complex release — chosen and recorded in `source/SOURCE.md`: URL, license, retrieval date). Place the untouched file in `source/` plus a `.sha256` sidecar. *Never edit `source/`.*
2. **Checksum.** `verify` recomputes SHA-256 and compares to the sidecar; mismatch aborts.
3. **Normalize.** `normalize.php` converts source format → `processed/*.json` (`surahs`, `ayahs`, `pages`, `page_ayahs`, `divisions`, `division_types`, `meta`). Deterministic output: same input ⇒ byte-identical output (round-trip check).
4. **Verify.** `verify.php` runs all §5 invariants against `processed/`, optionally diffs a **second independent source** (§8), writes `verification/report-<UTC-timestamp>.json` with per-check pass/fail, counts, and checksums. Any failure ⇒ exit non-zero, pipeline stops.
5. **Import.** `import.php` (dry-run flag first):
   - re-verifies processed checksums, then loads inside a transaction with `FOREIGN_KEY_CHECKS=0` for order-independent inserts (FK cycle: pages ↔ ayahs via segments);
   - preferred publish step: load into shadow tables, re-run invariants, then atomic `RENAME TABLE` swap — readers never see partial data;
   - writes `quran_dataset_meta` (version, checksums, counts, `imported_at`);
   - fully idempotent: re-running replaces the whole canonical set, never patches it.
6. **Audit.** `audit.php` re-runs §5 invariants **against the database** (not the files) and compares row counts to `quran_dataset_meta`. Failure ⇒ alert; app treats canonical data as unusable.

Failure handling: no partial states (transaction/rollback or shadow-swap), no silent skips, all failures logged to `storage/logs/`.

## 8. Verification strategy

1. **Static invariants** — the §5 checklist, machine-checked by `verify.php` (files) and `audit.php` (database).
2. **Cross-source verification** — import two *independent* authoritative sources (not a copy of the same file); diff their boundaries. Any disagreement halts the pipeline; resolution is by consulting the **printed Madinah Mushaf**, and the decision + evidence is recorded in `verification/`.
3. **Redundancy cross-checks** — §5.6 and §5.9: page spans vs. segment rows vs. division denormalization must all agree; disagreement = bug in normalize or source.
4. **Round-trip determinism** — normalize twice, byte-compare outputs; checksums recorded in the report.
5. **Human spot-check** — the verifier emits N random boundary locations; a maintainer confirms each against the physical Mushaf and records the result in the verification report (this is the one check automation cannot replace).
6. **Post-import audit** — `audit.php` after every import, plus integration tests under `tests/Integration/` on every schema or dataset change.
7. **Provenance** — every runtime table state traces to one `quran_dataset_meta` entry and one verification report; audits fail if that link is missing.
8. **Ongoing policy** — a discovered boundary error triggers: new source version → full re-verify → full re-import → audit → record. Never an ad-hoc `UPDATE`.

## 9. Separation from user data

- Canonical tables are `quran_*`; user tables (later) never store Quran reference copies — they store **coordinates** (`surah_number`, `ayah_number`, `page_number`) validated at write time by `App\Validators` against canonical data.
- **No FK cascades from user tables into `quran_*`** — deliberately, so a dataset re-import can never orphan or destroy user history. Referential integrity is enforced by server-side validation instead (the Mushaf coordinate of a flip card is checked when the card is created).
- No `created_by`, `user_id`, or mutable columns on `quran_*` tables.
- Quran files under `data/quran/` are dataset-only; user uploads live under `public/uploads/`; backups of each are kept apart (`database/backups/` holds schema+user data exports, never mixed with `source/`).

## 10. Out of scope at this stage

- No dataset files downloaded or generated; no migrations; no importer code; no seeds.
- Ayah text/translation/tajweed/audio/page images.
- Any recorded boundary values (pages, juz/hizb/rub start points) — they appear only inside `source/` after a later prompt authorizes acquisition.
- Application features that consume these tables (revision, ربط, flip cards) — they arrive after this architecture is implemented and verified.

## 11. Exit checklist (when a later prompt authorizes implementation)

1. Source acquired + recorded in `source/SOURCE.md` with checksum.
2. `normalize.php` deterministic; `processed/` regenerated only by tool.
3. `verify.php` all-green report in `verification/` (+ cross-source diff + human spot-check signed off).
4. Migration in `database/migrations/` matches §3.8 exactly.
5. `import.php` + `audit.php` all-green; `quran_dataset_meta` complete.
6. App DB user is SELECT-only on `quran_*` if the host supports granular grants.
7. Integration tests covering §5 invariants exist under `tests/Integration/`.
