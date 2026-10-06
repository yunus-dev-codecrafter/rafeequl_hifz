# Testing & QA matrix (Prompt 24)

Status: implemented (Prompt 24). How Rafeequl Hifz is verified: the four
layers, what each one owns, where a rule is covered, and the gaps that are
deliberate (with the reason).

## Layers

| Layer | Runner | Scope |
| --- | --- | --- |
| Unit (PHP) | `php tests/run.php` (or `php tests/Unit/<File>.php`) | Validators and pure calculators — no database, no HTTP. |
| Integration (PHP) | same runner → `tests/Integration/*Test.php` | Services + repositories against the test DB (`DB_*` env, synthetic 16-page fixture). Each file runs in its own process. The two `Canonical*` files instead cross-copy the imported Madinah dataset (env `QURAN_CANONICAL_DB`, default `rafeequl_hifz`) and skip with exit 0 when it is absent. |
| HTTP smoke | `%TEMP%\opencode\test_*.ps1` (PowerShell, ASCII-only) | Real `php -S` server, cookie sessions, envelope/status assertions over the same test DB. Run one at a time (they share the fixture). |
| Static | `lint11.ps1` (PHP `-l`), `check_imports.mjs` (JS module graph), `check_css.js` (tokens/braces), `calc_lum.php` (contrast), `nobom.ps1` (no BOM in public assets) | Source hygiene without running anything. |

## Coverage by area

| Area | Unit | Integration | HTTP smoke |
| --- | --- | --- | --- |
| Auth, sessions, lockout, reset | — | `AccountTest` (profile/password/delete) | `test_auth.ps1`, `test_qa.ps1` (lockout expiry starts a fresh attempt window) |
| Validators & empty-input contract | `ValidatorEmptyStringTest` (24), `SettingsValidatorsTest` (27), `TaskValidatorsTest` (38), `FlipCardValidatorsTest` (41), `RevisionValidatorsTest` (46), `ProgressValidatorsTest` (23) | `QaEdgeCasesTest` (empty query params default server-side) | `test_qa.ps1` (`?date=`, `from=`, `email: ""`, `theme: ""`) |
| Daily tasks & status machine | `TaskValidatorsTest` | `TaskTest` (80), `QaEdgeCasesTest` (`skipped → completed` blocked) | `test_task.ps1`, `test_qa.ps1` |
| Flip cards & duplicates | `FlipCardValidatorsTest` | `FlipCardTest` (65), `QaEdgeCasesTest` (duplicate rule + re-flag after mastered/archived + cross-user isolation) | `test_flipcard.ps1`, `test_qa.ps1` |
| Settings & preferences | `SettingsValidatorsTest` | `SettingsTest` (41), `QaEdgeCasesTest` (unit/amount pair) | `test_settings.ps1`, `test_qa.ps1` |
| Revision / ربط / Hifz / memorization | `RevisionValidatorsTest`, `RevisionTargetServiceTest` (34), `RabtRangeTest` (27) | `RevisionTest` (166), `RabtTest` (35), `HifzCalculationTest` (36), `MemorizationProgressTest` (63), `QuranStructureCalculationTest` (67) | `test_revision.ps1`, `test_rabt.ps1`, `test_task.ps1` |
| Progress analytics | `ProgressValidatorsTest` | `ProgressAnalyticsTest` (61), `QaEdgeCasesTest` (future-dated rows excluded from windows) | `test_progress.ps1`, `test_qa.ps1` |
| Export & backup | — | `AccountTest` (56), `QaEdgeCasesTest` (CSV formula escaping vs raw JSON) | `test_export.ps1`, `test_qa.ps1` |
| Schema & migrations | — | `SchemaConstraintsTest` (11: enum shapes, migration 0010 row preservation, UNIQUE email, one completion per task, cascades) | — |
| Canonical dataset & Prompt 08 engine on real data (Prompt 24A) | — | `CanonicalDatasetTest` (30: invariants + §5/§6 + provenance on the imported DB copy), `CanonicalEngineTest` (46: page boundaries, ranges, division targets, 30-page Rabt window, final shorter segment, fail-closed edges) — skip cleanly without a canonical import; see `docs/quran-data/dataset-pipeline.md` | — |
| PWA / a11y / reminders | — | — | `test_pwa.ps1` (63), `test_a11y.ps1` (51), `test_reminders.ps1` (54) |
| Security: headers, throttle, auth surface (Prompt 25) | — | `RateLimitTest` (16: fixed-window semantics, hash-only storage, prune, APP_ENV guard) | `test_security.ps1` (23: CSP/Permissions-Policy on 200 + 404, no HSTS over HTTP, cookie flags, email + IP throttle `429` envelopes; boots with `APP_ENV=production` so the limiter is active) |
| Performance (Prompt 26) | — | — | Static: `check_imports.mjs` (dynamic `import()` route table still parses), `test_pwa.ps1` (trimmed precache still >50 entries and covers every CSS/JS on disk, SW guards intact). gzip / cache-first / EXPLAIN numbers come from documented probes — method + results in `docs/deployment/performance.md` (deliberate: transport-layer behavior, gated in `Response::send()`, not asserted per-request) |
| Deployment (Prompt 27) | — | — | `test_deploy.ps1` (43: payload whitelist audit + SHA-256 manifest, SQL registry/canonical counts, fresh-DB import through `tools/deploy/import-sql.php`, boot from the exact `htdocs/` split — shell/SW/manifest/health/gzip/envelopes, source + `.env` unreachable, register/login on the fresh DB). Host limits and panel steps stay manual — documented in `docs/deployment/infinityfree.md` |
| Responsive & CSS rules | — | — | `test_qa.ps1` static section (mobile-first `min-width: 30rem`, `prefers-reduced-motion`, `prefers-color-scheme`, no `max-width` media queries, no `!important`) |

## Documented gaps (covered at one layer only — on purpose)

* **`>30 pages` ربط window via API** — unreachable by design: a boundary
  beyond the dataset fails closed (`422`), so no API call can produce it.
  Covered by the unit `RabtRangeTest` instead of a second fixture.
* **Register duplicate-email race** (`UNIQUE` violation → `422`) — cannot be
  triggered deterministically without a concurrent writer. The sequential
  duplicate is tested (`422`), and the constraint itself is asserted in
  `SchemaConstraintsTest`; the `PDOException 23000` catch in `AuthService` is
  the defensive branch between the two.
* **Reset-request timing padding** — identical response bodies are asserted;
  elapsed-time equality is not (timing assertions are flaky), but both code
  paths apply the same configurable delay.
* **Lockout `429` account-enumeration trade-off** — accepted and documented
  in `docs/api/authentication.md`: the lock answer prevents a legitimate
  password holder from being locked out by a stranger.
* **Performance probe automation (Prompt 26)** — the byte-count and EXPLAIN
  baselines are reproducible but not wired into the regression: they need
  seeded bulk data and a measuring client, while the correctness-critical
  parts (gzip gates, precache coverage, module graph, URL/cache guards)
  *are* asserted by existing layers. Method and numbers:
  `docs/deployment/performance.md` §1.

## Running the full regression

```text
1. powershell -File lint11.ps1                       # PHP syntax sweep
2. node check_imports.mjs && node check_css.js       # JS + CSS static rules
3. php calc_lum.php                                  # contrast (exit 0)
4. DB_* env -> php tests/run.php                     # every Unit + Integration file
5. sequentially: test_auth, test_pwa, test_settings, test_task,
   test_flipcard, test_rabt, test_revision, test_reminders,
   test_progress, test_export, test_a11y, test_qa, test_security,
   test_deploy
   # each must exit 0
```

Every layer must be green before a prompt is considered complete.
