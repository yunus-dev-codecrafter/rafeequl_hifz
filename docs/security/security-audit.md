# Security Audit — Rafeequl Hifz

**Audit date:** 2026-10-05 · **Prompt:** 25 (complete security audit) · **Scope:** entire application (PHP 8.3 / MySQL, `public/` front controller, API + PWA frontend) · **Auditor approach:** source-level review of every category below, backed by the existing automated test suites and a new HTTP security smoke (`test_security.ps1`).

**Verdict: 0 Critical · 0 High · 2 Medium (both fixed) · 3 Low (documented) · 6 Informational.** The two Medium findings (missing CSP, missing rate limiting on unauthenticated auth endpoints) were remediated in this prompt and are now covered by automated tests. No Critical or High findings were identified — the deployment blockers required by the prompt are resolved.

---

## Methodology

1. **Attack-surface inventory** — all 49 routes (`routes/api.php`, `routes/web.php`), middleware chain (`public/index.php` → global `SecurityHeadersMiddleware` → route middleware → controller), front door (`Request::fromGlobals`, `Router`, `ExceptionHandler`), cookie/header surface (`Response`), auth stack (`AuthService`, `AuthMiddleware`, `SessionRepository`, `PasswordResetService`).
2. **Category-by-category review** — the 16 categories required by Prompt 25, each traced through controllers → services → repositories with `file:line` evidence (summarised below).
3. **Dynamic-code sweep** — repo-wide scans for `innerHTML`, `eval`, `new Function`, `document.write`, `setAttribute('style')`, `exec/shell_exec/system/unserialize`, dynamic `ORDER BY`/`LIMIT` concatenation, variable `include/require`, CORS headers, hardcoded credentials/tokens.
4. **Verification** — findings confirmed or refuted against a live `php -S` instance (headers, status codes, throttle windows) plus the project's regression suites.
5. **Remediation** — all Critical/High (none found) plus the two approved Mediums fixed, with regression tests (`tests/Integration/RateLimitTest.php`, `test_security.ps1`).

Environment note: audit executed against the local development stack (PHP built-in server, plain HTTP). Production TLS termination is assumed to sit in front (see F-04 residual notes).

---

## Category verdicts (16/16)

| # | Category | Verdict | Key evidence |
|---|----------|---------|--------------|
| 1 | SQL injection | **Pass** | Prepared statements only; the only interpolations are literal `ORDER BY`/table/column names from internal code and `LIMIT ?` bound parameters (`FlipCardRepository.php:61`). Route ids pass `routeId()` → `ctype_digit` before reaching SQL. `Config::load()` builds a path from internal literals only. Zero dynamic SQL with user input found. |
| 2 | XSS | **Pass (F-02 fixed)** | Frontend renders exclusively via `textContent` / template cloning (`public/js/core/dom.js`); repo-wide scan found **0** `innerHTML`/`eval`/`document.write`/`setAttribute('style')` in `public/**`. `shell.html` has no inline `<script>`/`<style>`/`style=` attributes. `X-Content-Type-Options: nosniff` + new strict CSP (F-02) as defence in depth. |
| 3 | CSRF | **Pass, Low F-01** | All mutations are non-GET; session cookie is `SameSite=Lax` + `HttpOnly`; no CORS headers anywhere, so cross-site responses are unreadable; form bodies are accepted but state changes still require the Lax cookie. No explicit anti-CSRF token → F-01 (Low, hardening recommendation). |
| 4 | Session vulnerabilities | **Pass** | 256-bit random tokens (`TOKEN_BYTES = 32` → 64-hex), stored only as SHA-256; token **rotated on every login** (old row deleted — fixation defence, asserted by `test_auth.ps1`); absolute 120-min expiry enforced in SQL (`SessionRepository.php:31`), sliding `last_seen` write throttled to 1/min; all sessions revoked on password change **and** reset; reset claims are atomic single-use; garbage/absent cookies all yield the same generic 401. |
| 5 | Insecure cookies | **Pass, Info** | Session cookie: `HttpOnly`, `SameSite=Lax`, `Path=/`, `Secure` whenever the request is HTTPS (incl. `X-Forwarded-Proto`), cleared with `Max-Age=0` on logout/revoke/reset. Info: no `__Host-` prefix (would force `Secure` on plain-HTTP local dev — accepted). |
| 6 | Authentication weaknesses | **Pass** | `password_hash(PASSWORD_DEFAULT)` + `password_needs_rehash` on login; constant-shaped failure path: `DUMMY_HASH` compare + 200 ms delay on **both** unknown-user and wrong-password branches; uniform 401 message (no account enumeration, asserted); `hash_equals` for token comparisons; forgot-password returns one identical response regardless of registration state; raw reset tokens are never logged (`LogPasswordResetNotifier` unsets them) and never returned in responses. |
| 7 | Authorization failures | **Pass** | Every non-public route carries `AuthMiddleware`; identity comes only from the session cookie — client-supplied user ids are never trusted (`AuthMiddleware` doc-block, conventions §12); `requireUser()`/`authUser()` on every protected controller action. |
| 8 | IDOR | **Pass, Low F-05** | `{id}` params are positive-int validated (`Controller::routeId()` → 422) then ownership-checked: `TaskService::requireTask(userId, taskId)`, `RevisionService::requirePlan(userId, planId)`, `RevisionSessionService::requireOpenSession(userId, sessionId)`, `segments->findWithContext(userId, segmentId)`, session revoke scoped `WHERE id = ? AND user_id = ?` (`SessionRepository.php:77`). Foreign ids → 404 (asserted cross-user in `test_auth.ps1` + integration suites). Low: F-05 (some internal `UPDATE … WHERE id = ?` rely on the same-request pre-check). |
| 9 | Information leakage | **Pass, Low F-04** | Generic 500 in production, debug detail only when `APP_DEBUG`; no stack traces; API responses `Cache-Control: no-store`; sessions list contains no token material (asserted); logs carry no passwords/tokens/keys (password-reset log line contains e-mail only). Low: F-04 (`/api/v1/health` discloses `environment`). |
| 10 | Unsafe file uploads | **Pass (Info)** | No upload endpoint exists anywhere; `public/uploads/` is empty; only static assets are served. `AccountController::export` writes nothing to disk (streams the response). |
| 11 | Exposed secrets | **Pass** | Configuration is environment-only (`config/*.php` via `Env`); `.env` is git-ignored and outside the docroot; `.env.example` contains placeholders only; repo/docs/tools sweep found no passwords, API keys or tokens (test fixture password `Password123!` is a documented non-secret); Quran tools require no credentials. Info: `APP_KEY` placeholder in `.env.example` is currently unused by the app. |
| 12 | Insecure API endpoints | **Pass (F-03 fixed)** | `application/json` enforced (malformed body → 400); public surface is exactly `health` + `login`/`register`/`password/*`; `405` on method mismatch; uniform `{ok, data, errors}` envelope; no state-changing GET; high-risk unauthenticated endpoints now rate-limited (F-03). |
| 13 | Insufficient validation | **Pass, Info** | Every input-taking endpoint validates through a dedicated `*Validator` (whitelisted fields — no mass assignment); `routeId()` gates path params; `export` format/dataset strict whitelists (422 asserted); query params validated by query validators. Info: request body size = PHP defaults (`post_max_size` 8 MB) — no app-level cap. |
| 14 | Brute-force vulnerabilities | **Pass (F-03 fixed)** | Login: per-account lockout (5 fails / 15 min → uniform 429, persisted, asserted end-to-end) + constant 200 ms failure delay. Register / forgot-password / reset-password: **now throttled** per (route, IP) 10/min and per (route, e-mail) 5/min with fixed-window 429s (F-03). Accepted: no per-IP login throttle (per-account lockout + delay covers it; avoids shared-IP lockouts). |
| 15 | Privilege escalation | **Pass** | Single-tier model — no roles/capabilities to escalate; profile/settings/password updates write only validator-whitelisted columns (asserted: unknown fields ignored, partial updates isolated); password change and account delete require the current password. |
| 16 | User-data isolation failures | **Pass** | 47 ownership predicates (`user_id = ?`) across repositories; every service method takes `$userId` from the authenticated session, never from the request body; dedicated cross-user isolation assertions green in the integration suites (settings, tasks, flip cards, revision, sessions, export). |

---

## Findings register

### F-02 — Missing Content-Security-Policy · **Medium** · ✅ FIXED
- **Location:** `app/Response.php::withSecurityHeaders()` (was lines 108–115).
- **Evidence:** baseline headers were `nosniff / X-Frame-Options / Referrer-Policy / X-Permitted-Cross-Domain-Policies` only — no CSP, no Permissions-Policy, no HSTS.
- **Impact:** injected markup (e.g. via a future stored-XSS regression) would execute unimpeded; no browser-enforced origin allow-list.
- **Remediation (this prompt):** strict CSP on **every** response incl. errors: `default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'` — verified compatible with the frontend (zero inline scripts/styles, zero external origins, `data:` favicon, same-origin service worker in `sw.js`). Also added `Permissions-Policy` (camera/microphone/geolocation/…) and conditional `Strict-Transport-Security: max-age=31536000` when the request is HTTPS (browsers ignore HSTS on plain HTTP, so local dev is unaffected).
- **Tests:** `test_security.ps1` asserts CSP + Permissions-Policy on 200 **and** 404 responses, and HSTS absence over HTTP.

### F-03 — No rate limiting on register / forgot-password / reset-password · **Medium** · ✅ FIXED
- **Location:** `routes/api.php` (three public POST routes), new `app/Middleware/RateLimitMiddleware.php`, `app/Services/RateLimitService.php`, `app/Repositories/RateLimitRepository.php`, `database/migrations/0011_rate_limits.sql`, `config/auth.php`.
- **Evidence:** repo-wide scan found exactly one throttle — the per-account login lockout. Register (account/email-spam vector) and forgot/reset (e-mail/token-guessing spam vector) were unlimited; login had no per-IP component either.
- **Impact:** an attacker could mass-register accounts or hammer the reset endpoint (e-mail flooding, token grinding at application level) from one address without limit.
- **Remediation (this prompt):** fixed-window counters, config-driven (`RATE_LIMIT_IP_MAX=10`, `RATE_LIMIT_EMAIL_MAX=5`, `RATE_LIMIT_WINDOW_SECONDS=60`): per (route, IP) **and** per (route, e-mail body, when syntactically valid). Storage holds **only SHA-256 hashes** of the bucket key (no raw IPs/addresses persisted); atomic `INSERT … ON DUPLICATE KEY UPDATE` upsert (race-safe); stale rows pruned opportunistically; exceeded → `429` with the standard envelope. Disabled automatically under `APP_ENV=testing` so the 13-smoke regression (all from 127.0.0.1) can never trip itself — HTTP behaviour is instead asserted by `test_security.ps1`, which boots with `APP_ENV=production`.
- **Tests:** `tests/Integration/RateLimitTest.php` (16 checks: window semantics, reset on expiry, key isolation, hash-only storage, prune, escape hatches, APP_ENV flag) + `test_security.ps1` (email bucket blocks 6th same-address call, IP bucket blocks 11th overall, register route unaffected).

### F-01 — No explicit anti-CSRF token · **Low** · documented (accepted posture)
- **State-changing endpoints** rely on `SameSite=Lax` + `HttpOnly` + non-GET verbs + absence of CORS headers. All modern browsers enforce Lax; a cross-site POST arrives without the session cookie → 401. Residual exposure is limited to legacy browsers without SameSite support.
- **Hardening recommendation (optional, not required for deployment):** reject state-changing API requests that lack a custom header (e.g. `X-Requested-With`) — impossible to send cross-origin without CORS pre-approval.

### F-04 — `/api/v1/health` discloses the environment name · **Low** · documented
- Returns `APP_ENV` (e.g. `production`/`staging`). Useful for deployment verification; leaks nothing else (no versions, paths, credentials). Accepted; consider removing the field if health checks move to an internal port.

### F-05 — Internal `UPDATE … WHERE id = ?` without `user_id` · **Low** · documented (defence in depth)
- `RevisionPlanRepository::updateTarget/changeStatus/pause/activate/complete`, `RevisionCycleRepository::*`, `RevisionSegmentRepository::*`, `RevisionSessionRepository::updateProgress/finish` update by primary key only. Every call site is pre-scoped in the same request by `requirePlan/requireOpenSession/requireContext(userId, …)` (verified for all paths), so no exploit path exists today.
- **Recommendation:** add `AND user_id = ?` to these statements so the ownership invariant lives in the query itself (mirrors `SessionRepository::deleteForUser`).

### Informational notes (no action required)
- **I-01** `public/uploads/` exists but no upload code path — keep it that way or add a virus/type-scanning gate if uploads ever ship.
- **I-02** `APP_KEY` in `.env.example` is a placeholder for a currently unused key (no encrypted-cookie/payload feature).
- **I-03** Password-reset delivery in the default install is log-based (`LogPasswordResetNotifier`); the raw token appears only in the user's mail/log channel, never in HTTP responses or the DB.
- **I-04** `X-Forwarded-Proto` is honoured for cookie `Secure` + HSTS decisions. Spoofing it over plain HTTP can only *add* `Secure`/HSTS to responses browsers then ignore (HSTS is only honoured on secure transports). TLS deployments must strip/overwrite this header at the proxy.
- **I-05** Client IP is taken from `REMOTE_ADDR` only (no `X-Forwarded-For` trust). Behind a reverse proxy the per-IP throttle therefore counts per-proxy — operators should raise `RATE_LIMIT_IP_MAX` or add trusted-proxy handling at that layer.
- **I-06** Sessions use an absolute 120-minute lifetime (no sliding renewal) and request bodies rely on PHP's `post_max_size` (8 MB) — both are deliberate, conservative defaults.
- **I-07** The rate limiter is disabled when `APP_ENV=testing` (regression-suite guard). Any non-`testing` environment — including production and staging — is protected; behaviour is covered by `test_security.ps1` in a production-env boot.

---

## Residual risks / accepted posture

| Risk | Why accepted |
|------|--------------|
| No per-IP throttle on **login** | Per-account lockout (5/15 min) + 200 ms constant delay; IP throttling would let one attacker lock out a shared-NAT campus via failed logins. |
| CSRF token absent (F-01) | SameSite=Lax + no-CORS covers all supported browsers; custom-header hardening listed as optional. |
| Health discloses environment (F-04) | Operational value outweighs a single low-sensitivity string. |
| No `__Host-` cookie prefix | Requires `Secure` unconditionally → breaks plain-HTTP local development; Secure is already set automatically on HTTPS. |
| Reverse-proxy IP semantics (I-05) | Deployment-specific; documented for operators. |

## Re-audit checklist

1. `php tests/run.php` with `DB_*` env — full suite incl. `RateLimitTest`.
2. 14 smokes (see `docs/testing/test-matrix.md` §"Running the full regression"), especially `test_security.ps1` (CSP/Permissions-Policy/HSTS/429 envelopes).
3. `php tools/database/apply-migrations.php --database=<db>` — must print `applied/ok` with **no DRIFT** for all 11 migrations.
4. Re-run the sweeps after touching frontend or auth code: no `innerHTML|eval|document.write` in `public/**`; no `style=`/inline `<script>` in `shell.html`; no new public routes without an explicit threat note.
5. Header spot-check: `curl -I http://…/api/v1/health` → `Content-Security-Policy`, `Permissions-Policy`, `X-Content-Type-Options` present; `Strict-Transport-Security` present **only** over HTTPS.
6. Never commit `.env`; grep the repo for new `password|api_key|token` literals before release.

---

## Maintenance note

While applying migration 0011, a **pre-existing** checksum drift was found for `0001_schema_migrations.sql` in `rafeequl_hifz_test` only (recorded hash from an earlier comment-only edit to the file header; the main database already matched). Verified the drift was metadata-only by comparing the recorded DDL (`SHOW CREATE TABLE schema_migrations`) against the file — identical — then explicitly re-registered the current checksum. All 11 migrations now verify clean in both databases.
