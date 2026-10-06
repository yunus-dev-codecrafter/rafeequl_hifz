# Deployment to InfinityFree

Complete deployment process for Rafeequl Hifz on [InfinityFree](https://www.infinityfree.com)
free hosting — plus the portability rules that keep a move to any other host
a re-upload. Produced by Prompt 27.

> **Never expose, anywhere on any host:** database credentials · environment
> secrets (`.env`) · private Quran source files (`data/`) · logs
> (`storage/logs/`) · private configuration (`config/`, `routes/`,
> `database/`). The compliance table in §7 maps each item to why it cannot be
> reached.

---

## 1. How the layout maps

The app has been layout-invariant since Prompt 05: `public/` is the document
root and sits **one level below** the project root (`index.php` resolves
`dirname(__DIR__) . '/app/…'`). InfinityFree's structure is exactly that
split, so deployment is a mapping, not a restructure:

| This repository | InfinityFree (FTP account root) | Web-reachable? |
| --- | --- | --- |
| `public/*` | `htdocs/*` (the document root) | yes |
| `app/` | `app/` | **no** — above `htdocs/` |
| `config/` | `config/` | **no** |
| `routes/` | `routes/` | **no** |
| `storage/` | `storage/` (logs/cache/sessions, empty) | **no** |
| `.env` (you create it) | `.env` at account root | **no** |
| `.env.example` | `.env.example` | **no** |
| `tests/ docs/ tools/ data/ database/` | **not uploaded at all** | — |

Any host whose document root is a subfolder of the app root works the same
way (Apache `public_html/`, IIS `wwwroot/`, nginx `public/`): rename `htdocs/`
to that folder, upload the siblings next to it — nothing else changes. This
is the whole portability story (§10).

## 2. Hosting facts that shaped this guide

Verified September–October 2026 (InfinityFree marketing page, forum
announcements, knowledge base):

| Fact | Value | Impact on this app |
| --- | --- | --- |
| PHP | 8.4 on all free servers (panel-selectable; 8.3/8.1 also offered) | App needs **≥ 8.1** (enums, `readonly` props) — pick the highest offered |
| MySQL | MySQL 8.0 / MariaDB 11.4 | `utf8mb4_unicode_ci`, InnoDB, `ENUM`s, `CHECK`s all supported |
| Database | up to 400 DBs, ~50 MB each, **external connections blocked** | PDO connects from the same server (our only mode); our bundle ≈ 2 MB |
| Connections | `max_user_connections = 4` | One PDO connection per request — fine |
| Web root | `htdocs/`, not changeable | Our §1 mapping |
| .htaccess | full support (Apache) | `public/.htaccess` ships as-is, every block `IfModule`-guarded |
| SSL | free Let's Encrypt, enabled per domain | Required for the PWA (§6) |
| Upload | FTP (port 21) + File Manager (zip upload/extract) | §4 |
| Database import | phpMyAdmin in the panel | §5 (no SSH, no cron — that's why the SQL is one file) |
| Cron | **none** (disabled platform-wide since 2023) | Reminders/notifications are client-side JS — unaffected |
| Email | **none** on free accounts | Password reset logs intent server-side, never mails — unaffected |
| Limits | 30–50k HTTP hits/day, ~30k inodes, 5 GB disk | Boot ≈ 40 requests/visit → ≈750–1,250 visits/day inside the cap; we have ~176 payload files |
| Region | United Kingdom | Higher latency elsewhere; nothing to configure |

## 3. Prerequisites

1. InfinityFree account (free tier is enough: 5 GB disk, 400 DBs).
2. Domain: the free `*.infy.app`-style subdomain **or** your own domain
   pointed at InfinityFree's nameservers.
3. In the panel:
   - **PHP configuration** → select the highest PHP version (8.3 or 8.4).
   - **MySQL Databases** → create a database; note *host* (e.g.
     `sql303.infinityfree.com` — the exact value is shown in the panel),
     *database name*, *username*, *password*.
   - **SSL/TLS** (or client area → SSL) → enable the free certificate for your
     domain; wait until it reports active. The PWA (service worker,
     installability) requires HTTPS.
4. A local PHP ≥ 8.1 CLI to build the bundle (the same one you run tests
   with) and any FTP client (FileZilla) or just the File Manager.

## 4. Build and upload

```sh
# from the repository root, with DB_* env pointing at the local canonical DB
php tools/deploy/build-bundle.php --force
```

Output:

- `deploy/dist/` — the upload payload (176 files, ≈1.1 MB):
  `htdocs/` (the contents of `public/`) + `app/ config/ routes/ storage/
  .env.example` as siblings;
- `deploy/MANIFEST.sha256` — SHA-256 of every payload file;
- `deploy/sql/rafeequl-hifz.sql` — the database bundle (§5);
- `deploy/sql/MANIFEST.sha256` — SHA-256 of the SQL file.

Printed summary includes both hashes — keep them; they verify the upload.

**Upload (FTP):** connect to `ftp.<yourdomain>` with your panel credentials,
then:

1. **Delete** the default `htdocs/index.html` (if present).
2. Upload the **contents of** `deploy/dist/htdocs/` **into** `htdocs/`
   (overwrite).
3. Upload `app/`, `config/`, `routes/`, `storage/`, `.env.example` from
   `deploy/dist/` to the **account root** (siblings of `htdocs/`).

**Upload (File Manager):** zip the *contents* of `deploy/dist/` → upload to
the account root → extract there. Then confirm `htdocs/` inside it merged
with the existing one (no second `dist/htdocs` nesting).

The payload audit inside the builder guarantees no `.env`, no
`tests/ docs/ tools/ data/ database/`, no `*.log`/`*.sql` can be uploaded —
`tools/`, `data/` (private Quran sources) and `database/` never leave your
machine.

## 5. Database import (phpMyAdmin)

The single file `deploy/sql/rafeequl-hifz.sql` contains, in order:

1. migrations `0001…0011` **verbatim** (full schema + base seeds:
   `task_types` ×5, `flip_card_categories` ×7);
2. the 11 `schema_migrations` registry rows (same version + SHA-256
   semantics as `tools/database/apply-migrations.php`, so future tool runs
   see an honest registry);
3. all `quran_*` canonical data (6,236 ayahs, 604 pages, 330 divisions,
   114 surahs, division types, dataset meta) — dumped from your verified
   local import; the builder refuses to build if the canonical counts don't
   match;
4. `SET FOREIGN_KEY_CHECKS` wrappers; **no `DROP TABLE`** — importing into a
   populated database fails loudly instead of destroying data.

Steps: panel → **phpMyAdmin** → select the empty database → **Import** →
choose `rafeequl-hifz.sql` → Go. Expect no errors (≈110 statements).

Local equivalent (what `test_deploy.ps1` runs before any upload):

```sh
php tools/deploy/import-sql.php --database=<name> --drop
# prints: {"tables":29,"registry":11,"quran_ayahs":6236,...}
```

## 6. Configuration (.env)

Create `.env` **at the account root** (one level above `htdocs/`), based on
`.env.example`:

```ini
APP_NAME="Rafeequl Hifz"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.example
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_KEY=

SESSION_NAME=rafeequl_hifz_session
SESSION_LIFETIME=120

DB_DRIVER=mysql
DB_HOST=sql303.infinityfree.com        <- exact host from the panel
DB_PORT=3306
DB_DATABASE=<database name>
DB_USERNAME=<username>
DB_PASSWORD=<password>
DB_CHARSET=utf8mb4

LOG_CHANNEL=storage
LOG_LEVEL=info
```

Rules:

- `APP_DEBUG=false` in production. `APP_ENV=production` activates the
  Prompt-25 rate limiter (429s on brute-force register/forgot/reset).
- `APP_URL` must be the **https://** URL — it feeds redirects and links.
- Real environment variables always win over `.env` (hosting-panel env
  vars are fine).
- `.env` is never uploaded by the builder and never served (§7).

**HTTPS / cookies / sessions on this host:**

- `Response::isHttps()` recognises `X-Forwarded-Proto: https`, `$_SERVER['HTTPS']`,
  and — added in Prompt 27 — a direct **`SERVER_PORT = 443`** connection, so
  `secure` cookies and HSTS work regardless of how the certificate is
  terminated.
- Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS,
  `path=/`.
- Auth sessions are **rows in `user_sessions` + one signed cookie** — no PHP
  session files at all, so shared-hosting session storage is a non-issue
  (`storage/sessions/` stays empty).

**URL routing:** the SPA is hash-routed, so deep links never hit the server;
every non-file path (`/api/v1/…`) reaches `index.php` through the
`public/.htaccess` front-controller rewrite. On hosts without Apache, the
equivalent is in §10.

**PWA:** needs HTTPS (§3) — then `sw.js`, `manifest.json` and the icons are
served from the same origin; the Prompt-26 cache layer (`rafeeq-static-v5`,
cache-first shell + background revalidate) applies unchanged.

**Caching:** three layers, all host-portable: PHP gzip inside
`Response::send()` (text bodies, when the client accepts it), the
`.htaccess` compression/expiry layer (each block `IfModule`-guarded — a host
without `mod_deflate`/`mod_expires` skips them instead of 500-ing), and the
service worker. `sw.js`/`manifest.json`/`shell.html` are pinned to
`no-cache, must-revalidate`.

**File permissions:** upload with defaults (files 644, directories 755 —
FTP clients do this). The account runs as its own user, so PHP can always
write `storage/logs/`. If logs show permission errors, `chmod -R 755
storage/`.

**Upload restrictions:** the app performs no server-side file uploads and
sends no e-mail — none of InfinityFree's upload/mail limits apply. (Uploads
would go to `htdocs/uploads/`, which exists but is not used yet.)

## 7. "Never expose" compliance

| Item | Where it lives after deploy | Why it can't be reached |
| --- | --- | --- |
| Database credentials | `.env` at account root | Above `htdocs/`; builder never includes `.env`; `.htaccess` denies all dotfiles as a second layer |
| Environment secrets (`APP_KEY`, …) | `.env` at account root | Same |
| Private Quran source files (`data/`, `*.zip`, verification) | **not uploaded** | Excluded by the whitelist copy + payload audit in `build-bundle.php` |
| Logs | `storage/logs/` at account root | Above `htdocs/`; never uploaded with content (empty dirs + `.gitkeep` only) |
| Private configuration (`config/`, `routes/`), `database/`, `app/` sources | account root (or not uploaded) | Above `htdocs/`; a request like `/app/bootstrap.php` hits a path that does not exist in the document root |
| Tests, docs, tools | not uploaded | Same audit |
| SQL bundle | stays on your machine | phpMyAdmin reads it from your disk; nothing lands on the server |

## 8. Verification checklist (first run)

Run in order after §4–§6:

1. `https://yourdomain/` → app shell (200, no `500`).
2. `https://yourdomain/api/v1/health` → `{"ok":true,…}`.
3. Register a real account → lands on the dashboard; log out; log in.
4. DevTools → Network: document/API responses carry `Content-Encoding:
   gzip` where expected; `sw.js`/`manifest.json` show `no-cache`.
5. DevTools → Application → Service Workers: `activated`; install the PWA.
6. `http://` → redirects/behaves as HTTPS (certificate active).
7. `https://yourdomain/.env` → 404/403 (never the file); same for
   `/app/bootstrap.php`.
8. Password reset ("forgot") → `200` envelope; the request appears in
   `storage/logs/password-reset.log` (token deliberately **not** logged —
   by design there is no e-mail on this host).
9. Run a revision session offline → PWA serves from cache; reconnect →
   reconcile succeeds.

The automated equivalent of items 1–7 is the 14th smoke:
`powershell -File %TEMP%\opencode\test_deploy.ps1` (builds, audits, imports
into a fresh DB, boots from the exact `htdocs/` layout, probes).

## 9. Host limits: app fit

| Limit | Our usage |
| --- | ≈40 HTTP requests per boot → ~750–1,250 visits/day under a 30k hits/day cap (raise via caching; SW makes repeat visits ≈ 1–2 hits) |
| ~30k inodes | ≈176 payload files + SQL rows — no impact |
| 50 MB per DB | ≈2 MB (6,236 ayahs ×2 tables + app rows) — no impact |
| 4 MySQL connections | 1 PDO connection per request — no impact |
| No cron | Reminders are client-side — by design |
| No e-mail | Password reset logs intent server-side — by design |

## 10. Portability (moving to another host)

**Host-specific (re-do on a new host):** panel steps (PHP version, DB
creation, SSL), `.htaccess` (Apache-only; nginx equivalent below), the
`DB_*`/`APP_URL` values in `.env`, the upload itself.

**Portable (unchanged everywhere):** the `htdocs`/app-root split (or any
document-root-below-app-root split), the SQL bundle (standard
`utf8mb4`/InnoDB, no vendor extensions), env-driven configuration, PHP ≥ 8.1
code, MySQL 5.7+/8/MariaDB, gzip + expiry logic (both `IfModule`-guarded,
re-expressed for nginx below), the service worker, DB-backed sessions.

Move checklist: build the bundle → create DB on the new host → import the
SQL → upload `deploy/dist/` per §4 → rewrite `.env` → point the domain →
rerun §8.

nginx equivalent of the `.htaccess` layer (for non-Apache hosts):

```nginx
root /path/to/htdocs;                 # the htdocs/ folder
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ { fastcgi_pass unix:/run/php/php8.3-fpm.sock; include fastcgi_params; }
# dotfiles never served
location ~ /\. { deny all; }
gzip on; gzip_types text/html text/css text/javascript application/javascript application/json application/manifest+json image/svg+xml;
# expires: 1w for css/js/png/svg; no cache for sw.js / manifest.json / shell.html
location ~* ^(sw\.js|manifest\.json|shell\.html)$ { add_header Cache-Control "no-cache, must-revalidate"; }
```

## 11. Updating a deployed site

1. Ship schema changes: apply new migrations locally, then export the **new
   migration files only** as an incremental SQL (or re-run
   `tools/database/apply-migrations.php` logic by importing just those
   statements) — or simply rebuild the full SQL and import into a *fresh*
   database, then migrate users (never overwrite a live DB blindly).
2. `php tools/deploy/build-bundle.php --force`.
3. Re-upload changed files under `htdocs/` and any changed app files
   (FTP keeps timestamps — or upload everything, it's ≈1.1 MB).
4. Verify §8 items 1–5.
5. `deploy/MANIFEST.sha256` and `deploy/sql/MANIFEST.sha256` re-verify what
   was uploaded.

## 12. Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `500` on `/` | `.env` missing/typo (DB connect fails) or `storage/` not writable — both at account root; `APP_DEBUG=true` **locally** only, never on the host |
| "Error establishing a database connection" | Wrong `DB_HOST` — use the exact panel value; external hosts are blocked, and `localhost` usually isn't the right name on InfinityFree |
| Shell 404 / "Application shell not found" | Document root not pointing at the `htdocs/` contents (on InfinityFree the root is fixed — check the files actually landed *inside* `htdocs/`) |
| SSL not active | Certificate still issuing (can take minutes); re-check panel SSL page; PWA needs HTTPS |
| `.htaccess` rules ignored | InfinityFree supports them; if migrating elsewhere, confirm `AllowOverride All` — or use the nginx block in §10 |
| Duplicate-key errors importing SQL | You imported into a non-empty database — create a fresh one (the builder never emits `DROP TABLE` on purpose) |
| Register always `429` | `APP_ENV=production` rate limits (10/min/IP) are working; they auto-disable under `APP_ENV=testing` |
| PWA won't install | HTTPS missing, or stale `sw.js` cache — bump the cache name (Prompt 26 procedure in `docs/frontend/pwa.md`) |
