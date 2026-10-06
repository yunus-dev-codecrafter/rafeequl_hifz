# Production Readiness Review — Rafeequl Hifz (Prompt 28)

**Date:** 2026-10-06 · **Scope:** all 20 review areas · **Method:** static sweeps + live probes + full regression; no application code was modified as part of this review.

**Verdict: DO NOT DEPLOY yet.**

* **1 BLOCKER** (functional completeness — core write flows have no UI, PR-01).
* **0 CRITICAL** in the Prompt 28 gate categories: Quran-data, security, authentication and data-integrity all pass re-verification.
* **0 HIGH · 0 MEDIUM · 3 LOW · 4 OPTIONAL.**

The gate sentence ("do not recommend deployment while critical Quran-data, security, authentication, or data-integrity issues remain") is **satisfied** — those four categories are clean. PR-01 independently blocks a genuine launch: a real user cannot record memorization, so revision planning fails closed and the product cannot perform its stated purpose end-to-end.

## 1. Severity scale

* **BLOCKER** — must not ship: the product cannot deliver its core purpose, or data/security is compromised.
* **CRITICAL** — the Prompt 28 gate categories: Quran-data correctness, security, authentication, data integrity.
* **HIGH** — major functional shortfall that degrades a headline feature.
* **MEDIUM** — real defect with a workaround or limited blast radius.
* **LOW** — accuracy/hygiene issues with no runtime impact.
* **OPTIONAL** — polish or nice-to-have documentation.

## 2. Baseline (re-measured this session)

| Check | Result |
| --- | --- |
| PHP lint (`lint11.ps1`, excludes `vendor`/`storage`/`deploy\dist`) | 153 files, 0 errors |
| ES module import check (`check_imports.mjs`) | 33/33 |
| CSS tokens/rules (`check_css.js`) | 56/56 across 17 files |
| Contrast (`check-contrast.php`) | exit 0 |
| Test suite (`tests/run.php`, live DB) | 23 files, 1,065 assertions, all green |
| HTTP smokes (sequential) | 14/14 (`test_auth` 7.5 s … `test_deploy` 11.2 s) |
| `tools/quran-data/audit.php` (canonical DB) | 17/17 PASS |
| `tools/quran-data/verify.php` (canonical DB) | full PASS — checksums, round-trip determinism, cross-source agreement |

Environment note: the portable MariaDB instance was down at session start; the first suite run exited 0 with per-file `SKIPPED: database not reachable` lines — that baseline was discarded, MariaDB was restarted (port 3307), and the suite above was re-run green **before** any review conclusions were recorded. A green suite with skips is not a valid baseline.

## 3. Findings

### PR-01 — BLOCKER — Core write flows from Prompts 09 and 13 have no UI

Areas: 7 (memorization progress) and 10 (flip cards), with dependency impact on 8 (revision).

**Evidence (static, conclusive):**

* `public/js/features/ribat/memorization-api.js` exports `establishState`, `markMemorized`, `correctBoundary` — **zero callers** anywhere in `public/` (only the read-only `getState` and `rabtRange` are used, by the dashboard and reminders).
* `public/js/features/flip-cards/flip-cards-api.js` exports `createCard`, `reviewCard`, `setCardStatus`, `deleteCard` — **zero callers** (only `listQueue` is used).
* The only direct `api.post(...)` calls outside `*-api.js` modules are login/register/logout (`core/auth.js`).
* Server-side, the boundary moves exclusively through `MemorizationProgressService::updateBoundary` (via `POST/PUT /memorization/state` and `POST /memorization/history`) — no other service writes it, and no UI reaches those endpoints.

**Impact chain for a real user:**

1. Register → no memorization state row is ever created (`established=false`; the dashboard correctly shows `-`).
2. `POST /revision/plans` → `RevisionService::createPlan` → `HifzCalculationService::requireState()` throws NotFound → **404** — the revision loop (the app's namesake feature) is unreachable.
3. The boundary can never advance → Rabt window, dashboard Quran activity and analytics stay frozen at "not established".
4. Prompt 13's "a user must be able to flag an error" and review tracking have no front-end path (`#/flip-cards` renders the documented "soon" placeholder).

**Scope note:** the API layer itself is complete and well-tested (MemorizationProgress 63/63, FlipCard 65/65, Revision 166/166) — the gap is purely user-facing. Prompts 09 and 13 both state user-level capability requirements ("The user must be able to…"), and no later prompt (14–27) built these screens. The `#/rabt` and `#/flip-cards` placeholder routes are documented in the README; the missing **write** screens are not documented as a gap anywhere.

**Remediation:** build (a) an establish/onboarding form (memorized range + boundary), (b) a "mark memorized" action (page/range with confirmation), (c) a boundary-correction confirm dialog, (d) flip-card flag and review/status screens — then re-run the full regression and section 6 below. Alternative: an explicit, documented scoped launch that excludes memorization/revision/flip-cards — but the deployment verdict stays "do not deploy" until one path is decided and recorded.

### PR-02 — LOW — `docs/deployment/performance.md` precache figure is stale

The document claims `221,532 B / 53 entries` and the hosting checklist says "precache install size ≈221 KB". The current `rafeeq-static-v5` precache is **53 entries, 189,068 B** (all entries verified present on disk). The entry count matches; the byte figure is stale (payloads have shrunk since Prompt 26). Fix: update the figure and the checklist line.

### PR-03 — LOW — `docs/database/schema.md` omits `password_reset_tokens`

Migration `0009_password_resets.sql` is listed in §1 (How to apply), but the table has no entity section (§3 stops at 3.8 plus the 0011 counters). Runtime and tests are unaffected (password-reset flows green: Account 56/56 covers the export/delete side, reset paths exercised by the suite).

### PR-04 — LOW — Soft delete retains credential material

`UserRepository::softDelete` sets `status='deleted'`, replaces the e-mail with `deleted+<id>@deleted.invalid`, clears the display name and the sessions are purged — but `user_auth.password_hash` (and lockout fields) remain. Login is correctly blocked (`status` is checked **after** `password_verify`, with the uniform `invalidCredentials` shape and the constant delay), so this is data-minimization hygiene, not an authentication hole. Fix: null the hash and lockout fields in the same transaction.

### PR-05 … PR-08 — OPTIONAL

* **PR-05** `HealthController::status()` runs the constant `SELECT 1` outside `app/Repositories/` — the only SQL-ownership deviation in the codebase. No user input, zero exploitability; move it behind a repository method for convention purity.
* **PR-06** README feature tables label Prompts 10–27 explicitly but not 00–09 (their content is covered in prose under "Foundation Files" and the feature sections).
* **PR-07** `GET /` (SPA shell) and `GET /health` are the only two of 50 routes absent from `docs/api/*` (48/50 documented with full parity after normalization; both are non-feature surfaces).
* **PR-08** `.env.example` defines `APP_KEY=`, which no code reads — drop it or implement its use.

## 4. Area scorecard (20/20)

| # | Area | Verdict | Key evidence |
| --- | --- | --- | --- |
| 1 | Product requirements | PASS* | 50 routes = 49 API + shell; `docs/api` covers 48 with zero phantom endpoints (normalization diff clean); README covers Prompts 10–27 (PR-06/07) |
| 2 | Folder architecture | PASS | Top-level tree matches README; `.gitignore` covers env/vendor/storage/uploads/data/deploy/logs/OS artifacts; no stray files, no secrets, no private keys anywhere in the repo |
| 3 | Code architecture | PASS* | SQL confined to repositories (+1 constant probe, PR-05); prepared statements only, `EMULATE_PREPARES=false`; dynamic `UPDATE` builders whitelisted (`SettingsRepository::WRITABLE`, hardcoded `$sets` literals); envelope centralized in `Response` (only bootstrap/ExceptionHandler last-resort text paths echo); zero `innerHTML`/`eval`/`new Function` sinks in `public/js` |
| 4 | Database | PASS | 11 migrations + SHA-256 drift-refusing registry (`tools/database/apply-migrations.php`); utf8mb4 throughout (58 refs); 35 FKs (22 CASCADE / 2 RESTRICT / 2 SET NULL); **51 non-PK indexes** verified live via `information_schema` covering every hot path; transactional soft delete with login status guard (PR-03 doc gap) |
| 5 | Quran dataset | PASS | Canonical DB live: 6,236 ayahs / 604 pages / 114 surahs / 330 divisions / 6,236 segments / registry 11 / 29 tables; `audit.php` 17/17 PASS; `verify.php` full PASS (source checksums, byte-identical round-trip, cross-source agreement with alquran); CanonicalDataset 30/30 |
| 6 | Hifz calculation engine | PASS | HifzCalculation 36/36, QuranStructureCalculation 67/67, CanonicalEngine 46/46 against real 604-page data; boundary beyond dataset fails closed; all arithmetic server-side |
| 7 | Memorization progress | logic PASS / **UI BLOCKED (PR-01)** | MemorizationProgress 63/63: append-only history, explicit-confirmation correction, transactional writes, view-never-writes rule |
| 8 | مراجعة (revision) | logic PASS / blocked via PR-01 in practice | Revision 166/166; session UI complete (`createPlan` → `start` → `reportProgress` → `finish`); segments derived from the current range, cycles snapshot the boundary — but `createPlan` 404s until a state exists |
| 9 | ربط (rabt) | PASS | Rabt 35/35 + RabtRange 27/27; Prompt 12 was backend-scoped ("backend logic, API endpoints, tests"); read path live on dashboard/reminders; ≤30 newest memorized pages, never below start, slides automatically with the boundary |
| 10 | Flip Cards | logic PASS / **UI gap (PR-01)** | FlipCard 65/65 + validators 41/41; canonical ayah-must-exist-on-page validation; state machine active → in_review → mastered/archived with append-only review history; queue read-only in the UI |
| 11 | Productivity | PASS | Task 80/80; full create/edit/complete/skip UI; `completed` terminal with a single append-only completion row; server UTC "today"; percentages computed server-side |
| 12 | Dashboard | PASS | Server-owned statistics, `Promise.allSettled` per-card degradation, `-`/"not established" fallbacks, no client-side math; covered by `test_qa`, `test_a11y`, `test_pwa` |
| 13 | Authentication | PASS | Per-account lockout (5 fails / 15 min → uniform 429, UTC-parsed, lock expiry clears the counter) + 200 ms constant delay + dummy-hash timing shape; `status` guard after `password_verify` blocks deleted accounts; 256-bit tokens stored as SHA-256, rotated every login; cookies HttpOnly + SameSite=Lax + Secure-on-HTTPS; RateLimitTest 16/16 |
| 14 | Security | PASS | Prompt 25 re-verified live: CSP, Permissions-Policy, X-Frame-Options DENY, nosniff, Referrer-Policy present; `Cache-Control: no-store` on `/api` via `SecurityHeadersMiddleware`; JSON-only public surface = health + register/login/forgot/reset (the latter three throttled); no XSS sinks; SQL-injection sweep clean; `test_security` green (23 checks, boots with `APP_ENV=production` so the limiter is active) |
| 15 | Accessibility | PASS | `test_a11y` green: skip link, route focus + `aria-current`, focus trap/restore, `aria-describedby`/`aria-invalid`, `aria-valuetext`, roving tabindex, `aria-busy`, contrast tool 0 failures; `prefers-reduced-motion` covered by `test_qa` |
| 16 | RTL | PASS | `<html lang="ar" dir="rtl">` + manifest `lang`/`dir`; **zero** direction-sensitive physical CSS properties repo-wide (0 matches for left/right margins, paddings, text-align, floats); logical properties in use; `<bdi>` isolation for mixed numbers + `lang="en" dir="ltr"` islands |
| 17 | PWA | PASS* | Manifest valid UTF-8 JSON, all 3 icon paths resolve; SW v5: atomic 53-entry precache (all present), old-cache purge, notification click-through, offline deep-link falls back to the cached shell; `/api` and `/uploads` never intercepted (no personal data in Cache Storage); non-GET/cross-origin bypassed; one-time Arabic update toast (PR-02 stale figure) |
| 18 | Performance | PASS | gzip in `Response::send()` gated on client support + size win + non-legacy UA; 1-week expiry with must-revalidate trio for `sw.js`/`manifest.json`/`shell.html`; lazy page chunks behind a navigation token; boot concurrency; EXPLAIN sweep documented as const/ref/range with no index migration needed; host-side OPcache items are documented deployment-checklist TODOs |
| 19 | Testing | PASS | 23 test files (8 unit + 15 integration), 1,065 assertions green; 14 sequential HTTP smokes green; `test-matrix.md` documents the four layers plus deliberate gaps with rationale; suite correctly requires a live DB |
| 20 | Deployment | PASS | `test_deploy` 43/43: bundle build + payload whitelist audit + manifest hash verification, SQL registry/canonical gates, fresh-DB import via `import-sql.php` (refuses non-empty targets), boot from the exact `htdocs/` split (shell/SW/health/gzip/envelopes), source + `.env` unreachable, register/login on the fresh DB; `infinityfree.md` complete (12 sections) |

\* = passes with OPTIONAL/LOW notes listed in section 3.

## 5. Accepted design decisions (recorded, no action)

* Per-IP throttle deliberately **not** applied to login (shared-NAT lockout DoS); per-account lockout instead — documented in `docs/security/security-audit.md`.
* Client IP taken from `REMOTE_ADDR` only (no `X-Forwarded-For` trust) — documented I-05; revisit only behind a trusted reverse proxy.
* Rate limiter auto-disabled under `APP_ENV=testing` — documented I-07 (regression-suite guard; any non-testing environment is limited).
* Soft delete (identity anonymization + session purge) instead of hard delete — the schema's designed behavior (PR-04 is hygiene only).
* `#/rabt` and `#/flip-cards` render placeholders by design (README, `docs/frontend/dashboard.md`); Prompt 12/13 APIs are consumed read-only by dashboard/reminders.
* Absolute 120-minute session lifetime, no sliding renewal — documented I-06; request bodies bounded by PHP `post_max_size`.

## 6. Exit criteria to flip the verdict to "deployable"

1. **Resolve PR-01** — ship the memorization/flip-card write UIs, or record an explicit scoped-launch decision covering its consequences. *Mandatory.*
2. Re-run the full 5-step regression + all 14 smokes after any fix. *Mandatory.*
3. PR-02 / PR-03 / PR-04 — recommended before launch; PR-05 … PR-08 optional.
4. Host checklist items in `infinityfree.md` / `performance.md` (OPcache, SSL certificate, `.env` production values) — at deploy time.

## 7. Review limitations

* UI reachability was established by static call-graph evidence (repo-wide path/function sweeps), not browser end-to-end automation; no browser toolchain exists in this codebase's stack.
* Performance claims re-use Prompt 26's documented probes, re-verified through smokes (gzip, precache integrity, boot graph) rather than being re-benchmarked this session.
* Host-side behavior (InfinityFree PHP limits, panel steps) cannot be verified offline; verified-on-host facts are recorded in `docs/deployment/infinityfree.md` from Prompt 27.
