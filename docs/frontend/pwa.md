# PWA — Installable Offline Shell (Prompt 19)

Rafeequl Hifz installs as a standalone app (`display: standalone`, Arabic,
RTL) and stays usable on flaky connections. The design rule is one
sentence: **the shell may be served offline; data may not.**

## Pieces

| File | Responsibility |
| --- | --- |
| `public/manifest.json` | Web app manifest: Arabic `name`/`short_name`, `lang: ar`, `dir: rtl`, `start_url`/`scope` `/`, `display: standalone`, `theme_color: #0f766e`, `background_color: #ffffff`, icons (`any` 192/512 + `maskable` 512). |
| `public/assets/icons/` | Generated: `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `apple-touch-icon-180.png` (master source lives at `tools/app-icon.png`, outside the docroot — Prompt 26). |
| `tools/generate-icons.php` | Pure-PHP PNG pipeline (decode → unfilter → box downsample → compose maskable → encode, no GD). Re-run after changing the source icon: `php tools/generate-icons.php`. |
| `public/sw.js` | Service worker: versioned precache (`rafeeq-static-v5`), navigation + asset strategies. |
| `public/js/core/pwa.js` | Registration, update-available toast, online/offline banner state, `isOffline()`. |
| `public/shell.html` | Manifest/apple metas, `viewport-fit=cover`, `#offline-banner` element. |
| `app/Middleware/SecurityHeadersMiddleware.php` | `Cache-Control: no-store` for `/api/*`, `no-cache` otherwise (error responses mirror this in `ExceptionHandler`). |

## Caching strategy

| Request | Strategy | Offline result |
| --- | --- | --- |
| `GET /` (navigations, SPA shell) | cache-first (Prompt 26) — instant paint from the cached shell, background fetch re-caches the fresh copy | cached shell renders instantly |
| `/css/*`, `/js/*`, icons, `manifest.json` | stale-while-revalidate (precache + background refresh) | instant cached copy |
| `/api/v1/*`, `/uploads/*` | **never intercepted, never stored** | raw network failure → feature shows a fast-fail message |
| non-GET requests, cross-origin | passthrough | unchanged |

Why API responses are excluded from `Cache Storage`:

- no personal data (ayahs, plans, tasks) at rest in the SW cache;
- no chance of a stale response being replayed over newer server data;
- `Cache-Control: no-store` on API responses backs this up at the HTTP layer.

Mutations are **never queued**: offline writes fail fast with an explicit
toast (settings sheet says *بلا اتصال — لم تُحفظ التغييرات على الحساب*), so
optimistic local state cannot diverge silently from the account.

## Sync safety (settings write-through)

The settings sheet is optimistic: device preference changes apply
immediately, then write through to `PUT /api/v1/settings`.

1. Success → response is the server truth → stored as the **last-good
   snapshot** (`localStorage: rafeeq.settingsSnapshot`, alongside the ones
   saved by boot-time `reconcileFromServer`).
2. Failure → notify (offline wording when `isOffline()`), then reconcile
   from the server; if the server is unreachable, `restoreFromSnapshot()`
   re-applies the last-good theme/sound/awake values and the revision
   defaults so the optimistic change never lingers as fake state.

The snapshot holds non-sensitive display preferences only.

## Update flow

- `install`: precache the full static manifest, then `skipWaiting()`.
- `activate`: delete every cache whose name is not the current version,
  then `clients.claim()` — first install takes over immediately.
- A new worker reaching `installed` **while a controller exists** fires one
  `notify.info('يتوفر تحديث جديد…')` toast; the update applies on the next
  reload. The session is never force-reloaded mid-review.
- Bump `CACHE` in `public/sw.js` whenever the precache list or shell
  changes (the smoke test cross-checks the list against disk).

## Offline UX

- `#offline-banner` (fixed bottom strip, warning tokens) appears on the
  `offline` event and hides on `online`; `html[data-offline]` offsets the
  toast region so messages never sit under the banner.
- Boot with no connection: `fetchMe` failure reports
  *أنت غير متصل بالإنترنت* instead of a generic server error.
- Reconnect: one `notify.success('عاد الاتصال بالإنترنت')`.
- Service worker registration is guarded by `'serviceWorker' in navigator`
  and `window.isSecureContext` — plain-HTTP LAN access just runs without a
  SW.

## Not cached / not synced

Desktop-notification permission (device-bound), session cookies, and
everything under `/api/v1/`. Offline, browsing previously rendered data
works; reading fresh data or writing anything requires the network —
by design (Prompt 19).

## Verification

`test_pwa.ps1` (63 checks): manifest fields + icon responses, precache
coverage vs disk (17 CSS + 30 JS, no API/uploads entries, all entries
exist), SW guards, shell wiring, `no-store`/`no-cache` headers on success
and error paths, module wiring, banner/safe-area CSS.
