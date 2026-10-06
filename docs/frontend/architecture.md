# Frontend — JavaScript Architecture

Status: implemented (Prompt 16). The reusable-module layout of the Vanilla JS (ES modules) application: application initialization, API communication, state management, authentication, notifications, routing and the per-feature modules.

## Folder layout

| Folder | Owns | Rule |
| --- | --- | --- |
| `public/js/core/` | App shell, router glue, state store, API client, session, preferences, route table | No feature knowledge beyond the route table. |
| `public/js/features/<name>/` | One folder per domain: `dashboard`, `tasks`, `revision`, `ribat`, `flip-cards`, `settings` | Feature modules import from `core/` and `components/`, never from another feature's internals except documented API wrappers. |
| `public/js/components/` | Reusable UI primitives: toast, bottom sheet, form errors, progress bar, notifications facade, focus trap | No business logic, no `fetch`. |
| `public/js/pages/` | Route-level screens with no sibling feature: login, register, soon | Rendered by the route table. |

## Core modules

| File | Responsibility |
| --- | --- |
| `core/app.js` | Application initialization only: `initPwa()`, `initPrefs()`, `initReminders()`, `appState.subscribe(→ header)`, logout wiring, skip-link focus handler, `registerRoutes()`, auth boot (offline-aware failure message), `startRouter()`. |
| `core/routes.js` | The route table (`/`, `/login`, `/register`, `/revision`, `/rabt`, `/flip-cards`) with auth guards (`requireSession` / `isAuthenticated`) and the fallback redirect. |
| `core/api-client.js` | The single owner of HTTP: envelope `{ok, data, errors}` → resolve `data` or throw `ApiError {status, message, errors}`; `api.get/post/put/delete`, plus `api.getText()` for raw file downloads (CSV export) — JSON error envelopes still throw `ApiError`. **No other module calls `fetch`.** |
| `core/state.js` | `createStore({…})` — tiny observable store (`get/snapshot/set/subscribe`) and the shared `appState` session store (`user`). Login/logout anywhere re-syncs consumers. |
| `core/auth.js` | Session against `/auth/me|login|register|logout`; user backed by `appState`; `requireSession()` guard navigates to `/login` and returns false. |
| `core/router.js` | Hash routing: `route(path, handler)`, `setFallback`, `navigate`, `startRouter`. Static paths are registered before `{param}` routes. Accessibility (Prompt 20): after each route change focuses `#app` (except initial boot) and maintains `aria-current="page"` on header nav links. |
| `core/prefs.js` | Persisted preferences (`localStorage`, no DOM at import time): theme `auto → light → dark`, Wake Lock toggle (+visibility re-acquire), sound, desktop-notification opt-in (`rafeeq.notify`), daily reminders (`rafeeq.reminders` + per-day stamp); `initPrefs()` at boot. |
| `core/pwa.js` | PWA glue (Prompt 19): registers `/sw.js` (secure-context guarded), one-time "update available" toast, `#offline-banner` + `html[data-offline]` state on `online`/`offline` events, `isOffline()` helper. No DOM at import time. |
| `core/dom.js` | `qs`, `byId`, `clear`, `make`, `setSlot`, `setSlotBdi` (bidi-isolated slot text), `renderInto` helpers. |

## Notifications

`components/notifications.js` is the facade every feature calls:

* `notify.info/success/error(message)` → toast (renderer stays in `components/toast.js`). **Features never import `showToast` directly.**
* Desktop (system) helpers: `isDesktopSupported()`, `desktopPermission()`, `requestDesktopPermission()`, `showDesktopNotification(title, options)` — all gated by the `rafeeq.notify` preference and the browser permission; used when the dedicated API/settings land.

## Feature modules

| Folder | Responsibility |
| --- | --- |
| `features/tasks/` | `tasks-api.js` (8 task endpoints), `task-list.js` (group rendering + quick status transitions), `task-create.js` (create sheet: vocabulary, mirrored validation, 422 field errors). |
| `features/ribat/` | `memorization-api.js` — `/memorization/state|history|rabt` wrappers (establish, boundary correction, memorize, ربط window); `memorize-sheet.js` — the three write sheets (establish / mark / correct) opened from the dashboard (PR-01). |
| `features/flip-cards/` | `flip-cards-api.js` — the 8 flip-card endpoints (queue, categories, CRUD, review, status); `flip-cards-page.js` — the `#/flip-cards` screen: flag / review / status moves / delete (PR-01). |
| `features/revision/` | `revision-api.js` + `session-page.js` (screen from Prompt 12). |
| `features/dashboard/` | `dashboard-page.js` (render orchestration), `controls.js` (control row only). |
| `features/settings/` | `settings-api.js` (endpoints), `settings-sync.js` (server→device reconcile + last-good snapshot for offline rollback), `settings-sheet.js` — sheet extracted from the dashboard: theme, awake, sound, desktop-notification switch + daily-reminder config, account, logout. |
| `features/reminders/` | `reminders.js` — daily reminder scheduler (Prompt 21): eligibility gates, pending-work checks against the documented API wrappers, one combined notification/day with desktop→SW→toast fallback. |
| `features/progress/` | `progress-api.js` — `GET /progress/summary` wrapper for the dashboard's analytics snapshot (Prompt 22); payload only, no client math. |

## Accessibility (Prompt 20)

* `components/dialog-focus.js` — focus-trap stack used by both overlays: `trapFocus(container, onEscape)` returns `{release}`, keeps the top-most overlay in control (Tab wraps inside it, Escape closes it, focus restores to the opener). `bottom-sheet.js` and `modal.js` wrap their open/close flow around it.
* `components/form-errors.js` — `showFieldErrors` wires each 422 error to its input via `aria-describedby` (preserving authored hint ids) + `role="alert"`, and `focusFirstInvalid` moves focus to the first bad field.
* Keyboard map, ARIA patterns, contrast results and the bidi/digit policy live in `docs/frontend/accessibility.md`.

## Rules

1. **One HTTP owner**: every network call goes through `core/api-client.js`.
2. **One notify facade**: `notify.*` (not raw toasts) for user feedback.
3. **Server-side math**: the client never computes Hifz, summary, scheduling or progression numbers — it renders what the API returns (coding-conventions §14).
4. **No DOM at import time**: modules only touch `document` inside functions.
5. **No duplicate API logic**: one wrapper per endpoint in its feature's `*-api.js`.
6. **No giant files**: routes, boot, state, sheets and APIs are separate modules.
