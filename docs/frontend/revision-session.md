# Frontend — Revision Session Experience

Status: implemented (Prompt 11). The first frontend prompt: a mobile-first, Arabic RTL single-page shell whose only screen so far is the revision session.

## Serving

* `GET /` → `routes/web.php` → `ShellController::shell()` → raw `public/shell.html` (`Response::html()`; security headers still applied by the global middleware).
* Everything else is a static file under `public/` (CSS/JS) or an `/api/v1/...` JSON call.
* Navigation is client-side only (hash router): `#/login`, `#/register`, `#/revision` (default). Deep links never reach the server, so no additional rewrite rules are needed on shared hosting.

## File map

```text
public/
├── shell.html                      single document: header, <main id="app">, toast region, <template>s
├── css/
│   ├── tokens.css                  design tokens (light + prefers-color-scheme dark)
│   ├── base.css                    reset, header/main layout, .btn styles
│   ├── components/{forms,card,progress-bar,toast,bottom-sheet}.css
│   └── pages/{login,revision}.css
└── js/
    ├── core/{dom,api-client,auth,router,app}.js
    ├── pages/{login-page,register-page}.js
    ├── components/{toast,bottom-sheet,progress-bar,form-errors}.js
    └── features/revision/{revision-api,session-page}.js
```

Conventions enforced here (see `docs/architecture/coding-conventions.md` §3–§5): vanilla ES modules, no bundler/npm, no `innerHTML`, no inline styles/handlers, `fetch()` only in `js/core/api-client.js`, every network call carries the HttpOnly session cookie (`credentials: 'same-origin'`). Modules touch no DOM at import time (node import-smoke compatible; `app.js` guards its boot with `typeof document !== 'undefined'`).

## Data flow

1. `app.js` boots: `GET /auth/me` → sets the in-memory user (or null on 401) → starts the router.
2. Route `/revision` (auth-guarded) → `renderRevision()`:
   `GET /revision/plans` → pick the first active (else paused, else any) plan → `GET /revision/plans/{id}`.
3. The detail payload alone drives the screen. The server is the single source of truth for state — there is **no** localStorage state to go stale; every user action is a POST/PUT followed by either an in-place repaint (page advance) or a full re-fetch (start/finish/pause/resume).

## Screen states

| State | Condition (server fields) | UI |
| --- | --- | --- |
| Loading | request in flight | status card "جارٍ تحميل المراجعة…" |
| Error | network/500 | status card + "إعادة المحاولة" |
| No plan | `plans: []` | status card + "ابدأ خطة مراجعة" → `POST /revision/plans` (server defaults) |
| Paused | `plan.status = paused` | status card + "استئناف الخطة" → `PUT .../status {status:"active"}` |
| Plan completed | `plan.status = completed` | status card + "ابدأ خطة جديدة" → `POST /revision/plans` |
| Ready | active plan, `segments[].is_today` exists, no open session | "مهمة اليوم: مقطع N" + "ابدأ الجلسة" |
| Resumable ready | ready + latest `partial`/`interrupted` session of that segment (from `GET /revision/sessions`) | same button, passes `resumes_session_id` so progress is inherited |
| In session | `in_progress_session != null` (also the unexpected-leave recovery path) | session panel |
| Today done | active plan, no `is_today` segment | "راجعت مقطع اليوم ✓" + next segment/date (server fields only) |

The segment list (current cycle, badges: تم / متخطّى / جارية / فائتة / اليوم / قادمة) is always rendered when a detail loads; `is_missed` / `is_today` flags come pre-derived from the server.

## Session panel

* Big **current page**, start/end pages, `completed X من Y`, remaining — all straight from the session payload (`current_page`, `pages_remaining`, `percent_complete` are computed by PHP in `RevisionService::mapSessionWithDisplay()`, conventions §14).
* Progress bar: `--fill` custom property set from the server percentage (no inline `style` attributes, no `!important`).
* Controls (double-submit guarded by a busy flag; all requests go through the API client):
  * **التالي** → `POST /revision/sessions/{id}/progress {last_page_reached: current_page}`; repaints in place from the response; hidden once `pages_completed == total_pages`.
  * **إنهاء الجلسة** → `PUT /revision/sessions/{id} {status:"completed"}` (server forces the segment end); toast notes `cycle_completed`.
  * **إيقاف مؤقت** → `... {status:"partial"}`; progress kept, resumable later.
  * **انقطاع** → bottom sheet with optional reason (≤190 chars) → `... {status:"interrupted", interruption_reason}`.
* Any 401 mid-session → redirect to `#/login`; any 404/422 (stale state) → toast + full re-fetch to resync.

## Server endpoints used

`GET /auth/me`, `POST /auth/login`, `POST /auth/register`, `POST /auth/logout`, `GET /revision/plans`, `GET /revision/plans/{id}`, `POST /revision/plans`, `PUT /revision/plans/{id}/status`, `GET /revision/sessions`, `POST /revision/sessions`, `POST /revision/sessions/{id}/progress`, `PUT /revision/sessions/{id}`.

Not wired in this prompt (report-only): plan target editing, cycle generation, segment skip, memorization progress screens, PWA (manifest/service worker/offline queue), i18n of server English error messages (toasts show them as-is).

## Verification checklist (manual, browser)

1. `php -S localhost:8000 -t public` → `#/login` loads in Arabic RTL, dark/light follows OS.
2. Register → lands on `#/revision`; no plan → "ابدأ خطة مراجعة" creates one (404 server message shows as toast if memorization state is missing).
3. Start today's segment → panel shows server numbers; refresh the page mid-session → same session resumes (unexpected-leave recovery).
4. **التالي** several times → completed/remaining/percent/bar update after each POST.
5. **إيقاف مؤقت** → reload → "متابعة الجلسة" restores inherited progress.
6. **انقطاع** with/without reason → history shows the reason; resume works.
7. Finish the last page → **إنهاء الجلسة** → segment badge "تم", cycle settles at the end.
8. Pause the plan → status card + resume; logout → back to `#/login`; direct `#/revision` while logged out → `#/login`.
