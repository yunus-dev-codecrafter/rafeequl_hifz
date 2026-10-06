# Performance Baseline & Optimization — رفيق الحفظ

| | |
|---|---|
| **Prompt** | 26 — Performance Optimization |
| **Status** | Complete — baseline + deltas recorded |
| **Measured** | 2026-10-05 (local Windows, PHP 8.3 built-in server, MariaDB 11.4) |
| **Principle** | Measure before optimizing. Quran-data correctness is never traded for speed. |

---

## 1. How to re-measure

All numbers below were produced by two throwaway probes (kept out of the
repository; method is described so they can be recreated):

1. **Payload probe** — boots `php -S 127.0.0.1:<port> -t public` with
   `APP_ENV=testing`, registers a throwaway user, seeds a representative
   workload (600 history rows, 600 tasks, 300 flip cards, 1 plan/100
   segments/200 sessions, boundary = 16 to stay inside the synthetic
   fixture), then GETs the seven logged-in boot endpoints and reports raw
   bytes vs `gzencode($body, 6)` bytes per endpoint.
2. **EXPLAIN sweep** — seeds the same workload for one user in the test
   database and runs `EXPLAIN` for every hot query shape; Quran queries are
   EXPLAINed against the main `rafeequl_hifz` database (604-page dataset).

> Caveat: local built-in server — **byte counts are the stable metric**,
> not wall-clock latency. Production latency is dominated by network RTT,
> which is exactly what payload size and request count control.

## 2. Baseline (before optimization)

### 2.1 Logged-in boot API (7 calls, populated user)

| Endpoint | Raw bytes | With gzip | Ratio |
|---|---:|---:|---:|
| `GET /api/v1/auth/me` | 177 | 163 | 1.1× |
| `GET /api/v1/settings` | 179 | 141 | 1.3× |
| `GET /api/v1/tasks?date=` | 177 | 146 | 1.2× |
| `GET /api/v1/memorization/state` | 309 | 206 | 1.5× |
| `GET /api/v1/memorization/rabt` | 176 | 142 | 1.2× |
| `GET /api/v1/flip-cards/queue?limit=20` | 9,104 | 574 | **15.9×** |
| `GET /api/v1/progress/summary` | 1,076 | 420 | 2.6× |
| **Total** | **11,198** | **1,792** | **6.2×** |

- Fresh empty user: 1,596 raw / 1,096 gzipped bytes.
- `flip-cards/queue` is 81% of the boot payload (20 cards × ~455 B JSON).
- **Baseline server sends no compression at all** (`Response::send()`
  writes raw bytes) → users pay the full 11.2 KB + 183 KB static cost.

### 2.2 HTML shell & static assets (cold, no service worker)

| Asset | Files | Raw bytes |
|---|---:|---:|
| `GET /` (shell.html via ShellController) | 1 | 34,062 (gzip: 6,213) |
| CSS (17 render-blocking stylesheets) | 17 | 32,440 |
| JS (full module graph incl. eager page routes) | 33 | 105,212 |
| `manifest.json` | 1 | 821 |
| `sw.js` | 1 | 5,168 |
| `icon-192.png` | 1 | 48,997 |
| **Cold first load (shell+CSS+JS+API, no gzip)** | — | **≈ 182,900** |
| `app-icon.png.png` → moved to `tools/app-icon.png` in Prompt 26 (1.27 MB source master, referenced only by `tools/generate-icons.php` + docs — was shipped in the docroot, never served to users) | 1 | 1,301,980 |

### 2.3 PWA precache (installed on first visit)

| | Entries | Bytes |
|---|---:|---:|
| **Total PRECACHE (`rafeeq-static-v4`)** | 56 | **761,425** |
| — 4 PNG icons | 4 | 588,890 (**77%**) |
| — shell + manifest | 2 | 34,883 |
| — CSS | 17 | 32,440 |
| — JS | 33 | 105,212 |

`icon-512.png` (297,020) + `icon-maskable-512.png` (199,179) +
`apple-touch-icon-180.png` (43,694) = **539,893 B** sit in PRECACHE but
are only needed at OS install time (browsers fetch them via the manifest
on demand) — trimming them cuts precache to ≈ 221 KB (−71%).

### 2.4 Request graph (logged-in boot, no SW yet)

`GET /` → 17 CSS (render-blocking) → full JS module graph (routes.js
eagerly imports dashboard/login/register/revision pages) → 7 boot API
calls → on PWA install, 56-entry precache.

## 3. Query plan verdict — EXPLAIN sweep

Hot queries EXPLAINed with seeded volume (600-row per-user tables,
604-page Quran tables):

| Query shape | Plan | Verdict |
|---|---|---|
| login email lookup | `const` via `uq_users_email` | optimal |
| session auth join | `const`/impossible-WHERE | optimal |
| tasks day / history range / join | `ref`/`range` via `idx_daily_tasks_user_date` | optimal |
| flip queue (user + status + sort) | category-covering index + `ref` join, `Using temporary; Using filesort` | OK — filtered to ≤ limit per user, sort on non-indexable expression |
| flip due (user, next_review_at) | `range` via `idx_flip_cards_user_due` | optimal |
| history range/recent/user+page | `range`/`ref` via both `memorization_history` user indexes | optimal |
| plans list / unfinished count | PK scan / `range` via `idx_revision_plans_user_status` | OK (≤ handful of plans/user) |
| segments by user+date | `ref` via `idx_revision_segments_user_date` | optimal |
| sessions by user | `range` via `idx_revision_sessions_user_status` + filesort | OK |
| Quran ayah/surah/page/juz lookups | `const`/`ref`/`range`/`index` (PK or covering) | optimal — all PK-backed |

**ALL (full scan) appearances** — `tasks completed since`,
`flip list filtered`, `segments active list`, `revision sessions range` —
occur **only on per-user tables at ≤ 600 rows** immediately after bulk
seeding (stale optimizer stats; planner judges a linear scan of one
user's rows optimal). Per-user cardinality is bounded by design (a user
has hundreds of rows, never millions), so no index changes are warranted.

**Decision: no migration `0012`.** Indexes already match the access
patterns; every Quran-critical query is PK/covering-index driven.

## 4. Optimization deltas (after fixes)

| Metric | Before | After | Change |
|---|---:|---:|---|
| Boot API wire bytes (browser UA) | 11,198 raw, **no compression** | ≈1,792 (gzip in `Response::send()`) | **−84%** |
| HTML shell on the wire | 34,062 raw | 6,213 (gzip) | **−82%** |
| CSS + JS served on Apache | raw | gzip via `mod_deflate` (`public/.htaccess`) | text ≈6× smaller |
| Boot serial round trips | 4 (`me` → reconcile → day → activities) | 2 (`me` → [reconcile ∥ page chunk ∥ day ∥ activities]) | **−2 RTT** |
| PWA precache install | 761,425 B / 56 entries | 216,027 B / 56 entries (icons trimmed to icon-192; re-measured 2026-10-06 after the PR-01 UI screens) | **−72%** |
| Entry JS graph | all four page trees pulled eagerly by `routes.js` | page trees behind dynamic `import()`; **19,039 B** of page files leave the entry graph | lazy on navigation |
| Icon source master in `public/` | 1,301,980 B file in docroot | moved to `tools/app-icon.png` (regenerator verified byte-identical) | docroot −1.27 MB |
| Query indexes | 15 shapes EXPLAINed | unchanged — no migration `0012` | correctness kept, complexity not added |

Implementation notes:

- **gzip gate** (`Response::send()`): only when the client offers
  `Accept-Encoding: gzip`, `zlib.output_compression` is off, the result is
  actually smaller, and the UA is not PowerShell (legacy PS 5.1 clients get
  raw bytes so automation/smoke traffic stays byte-exact — probe: PS UA
  skips compression, browser UA compresses and decodes to the exact original
  byte count).
- **SW cache-first shell**: navigations paint from the cached shell and
  revalidate in the background (`event.waitUntil`); API/uploads/non-GET
  guards run first, so no personal data changes hands.
- **Lazy routes**: `routes.js` keeps `soon-page` static (الربط route still
  shares it) and dynamic-imports dashboard/login/register/revision/flip-cards
  behind a nav token that drops stale chunks on fast navigation.
- **Static assets are only gzip-compressed under Apache** (`.htaccess`);
  the PHP built-in dev server serves them raw — a dev-only caveat.

## 5. Hosting & deployment checklist

`public/.htaccess` implements the Apache half of this list and is a no-op
module-by-module on hosts that lack any of them. The app runs correctly
without it.

- [ ] **Apache**: `mod_deflate` + `mod_expires` + `mod_headers` +
      `mod_rewrite` loaded (`.htaccess` guards each with `IfModule`).
      nginx equivalent: `gzip on;` for the text types + `location ^~ /css|/js|/assets`
      with `expires 7d;`, and `location = /sw.js { add_header Cache-Control "no-cache"; }`.
- [ ] **PHP OPcache enabled** (`opcache.enable=1`, `opcache.max_accelerated_files≥10000`);
      it costs nothing on shared hosting and removes per-request compile time.
- [ ] **HTTPS** so HTTP/2 (one multiplexed connection for shell + 17 CSS +
      JS + API) and the HSTS header are active; HTTP/1.1 fallback still works.
- [ ] **Database on the same host** as PHP (localhost/socket): every boot
      query is sub-millisecond locally; never put MySQL across a WAN link.
- [ ] **`DocumentRoot = public/`** so `../app`, `../database`, `../tests`
      and `tools/` are unreachable; `.htaccess` additionally denies dotfiles.
- [ ] **Older-device policy**: no transpilation, no polyfills — target
      evergreen Chromium **63+** (dynamic `import()`), Safari 11.1+, Firefox
      67+; devices that cannot run ES modules get the HTML/JS-error path,
      not a broken half-app. Data volume per user stays small by design
      (EXPLAIN §3: per-user scans ≤ 600 rows), so old CPUs are bounded by
      network, not queries.
- [ ] **Never cache**: `/api/v1/*` (`no-store`, enforced in PHP and skipped
      by the SW) — Quran-derived user data must not be replayed stale.
- [ ] **Re-measure after deploy**: browser devtools → Network: boot JSON
      should show `content-encoding: gzip`, precache install size ≈216 KB,
      and a second load of `/` should come from the SW cache instantly.

### Quran-data correctness guard

Compression, caching and lazy loading are transport/UX concerns only: no
SQL, no payload shapes, no arithmetic, and no dataset files changed in
Prompt 26. Canonical fixtures, checksums and `CanonicalDatasetTest` /
`CanonicalEngineTest` remain the authority (verified by the full
regression in `docs/testing/test-matrix.md`).
