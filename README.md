# Rafeequl Hifz — رفيق الحفظ

Mobile-first Quran memorization, revision, error-management, and daily productivity companion for Huffaz and students of Hifz.

The physical Mushaf remains the primary Quran-reading source. This application is a companion and management system; it does not replace the Mushaf.

## Technology Stack

* HTML5 / CSS3 / Vanilla JavaScript
* PHP
* MySQL
* PWA technologies

No major frameworks (React, Vue, Angular, Laravel, Node.js) unless a later prompt explicitly authorizes one. The application must run on shared hosting such as InfinityFree and remain portable to better PHP/MySQL hosting later.

## Foundation Files

| File | Responsibility |
| --- | --- |
| `README.md` | Project identification, stack summary, setup instructions, foundation file index. |
| `.env.example` | Environment configuration template. Contains placeholders only, never real secrets. |
| `.gitignore` | Version control hygiene: keeps secrets, logs, cache, sessions, uploads, and IDE files out of the repository. |
| `app/bootstrap.php` | Application bootstrap: defines base constants, registers the PSR-4 autoloader, loads the environment, applies base error/logging behavior. No business logic. |
| `app/Helpers/Env.php` | Environment reader: parses the `.env` file and exposes typed accessors (`Env::get`, `getString`, `getInt`, `getBool`). |
| `config/app.php` | Base application configuration (name, environment, debug flag, URL, timezone, locale, session settings) resolved from environment variables. |
| `config/database.php` | Database configuration foundation. All credentials come from environment variables; nothing is hard-coded. |
| `public/index.php` | Front controller: the single HTTP entry point. Loads the bootstrap; routing and features arrive in later prompts. |
| `public/.htaccess` | Apache configuration for shared hosting: routes all non-file requests to `public/index.php` and protects sensitive paths. |

### Backend foundation (Prompt 06)

| File | Responsibility |
| --- | --- |
| `app/Request.php` | Request abstraction over superglobals (method, path, query, JSON/form body, headers, route params). |
| `app/Response.php` | Response object + API envelope `{ok, data, errors}`, JSON encoding, security headers. |
| `app/Router.php` | Lightweight router: route files, `{param}` patterns, middleware pipeline, 404/405 handling. |
| `app/Database.php` | PDO layer: connection from env config, prepared-statement helpers, transactions. |
| `app/Controllers/Controller.php` | Base controller: HTTP-only helpers (success/failure/input/param/validate). |
| `app/Controllers/HealthController.php` | `GET /api/v1/health` deployment check (PHP boots, DB reachable). |
| `app/Repositories/Repository.php` | Base repository: the only seam to `Database`. |
| `app/Models/Model.php` | Base model: row hydration + attribute access, no queries. |
| `app/Validators/Validator.php` | Declarative validator (required/integer/min/max/in/date/each/...), throws 422. |
| `app/Middleware/*` | Middleware contract + security-headers example. |
| `app/Exceptions/*` | `AppException`, `HttpException`, `ValidationException`, `NotFoundException`, `ExceptionHandler`. |
| `routes/api.php` | Route table (`[METHOD, path, [Controller, method], [middleware]]`). |

### Authentication (Prompt 07)

| File | Responsibility |
| --- | --- |
| `app/Services/AuthService.php` | register / login / logout / session lifecycle (hashing, lockout, timing equalization). |
| `app/Services/PasswordResetService.php` | Single-use, time-limited reset tokens; revokes all sessions on reset. |
| `app/Services/PasswordResetNotifierInterface.php` + `LogPasswordResetNotifier.php` | Delivery seam for reset tokens (default logs metadata only, never the token). |
| `app/Repositories/{User,Session,PasswordReset}Repository.php` | Parameterized SQL for users/credentials, sessions, reset tokens. |
| `app/Middleware/AuthMiddleware.php` | 401 unless a valid session cookie identifies an active user. |
| `app/Controllers/AuthController.php` | Auth endpoints + cookie issuance. |
| `app/Validators/{Register,Login,ForgotPassword,ResetPassword}Validator.php` | Input rules for the auth payloads. |
| `app/Models/User.php` | Authenticated user + safe `profile()` shape. |
| `config/auth.php` | Brute-force/reset policy from env. |
| `database/migrations/0009_password_resets.sql` | `password_reset_tokens` (hash-only, single-use). |
| `docs/api/authentication.md` | Endpoint + security documentation. |

### Hifz calculation engine (Prompt 08)

| File | Responsibility |
| --- | --- |
| `app/Models/DivisionType.php` | Canonical division types (juz, hizb, rub) as a typed enum matching `quran_division_types`. |
| `app/Models/RevisionTargetUnit.php` | Daily target units (page, hizb, rub, juz) + mapping to division types. |
| `app/Repositories/QuranStructureRepository.php` | Read-only parameterized queries over `quran_*`; never writes canonical data. |
| `app/Repositories/MemorizationStateRepository.php` | Reads the user's memorization state (start page, boundary). |
| `app/Services/DivisionLocatorInterface.php` | Division-lookup seam so segmentation is unit-testable without the dataset. |
| `app/Services/QuranStructureService.php` | Structure calculations: page bounds/ranges, exact locations, ordinals, divisions; fail-closed when the dataset is missing. |
| `app/Services/RevisionTargetService.php` | Daily target segmentation (division targets end on canonical boundaries; final segment may be smaller; never past the range end). |
| `app/Services/HifzCalculationService.php` | Memorized range, current boundary/page, division coverage, revision targets. |
| `tests/` | Calculation tests: `run.php` runner, unit tests (no DB), integration tests (canonical tables), clearly-synthetic structural fixture (test data only — never presented as Quran data). |

### Memorization progress (Prompt 09)

| File | Responsibility |
| --- | --- |
| `app/Models/BoundaryChangeReason.php` | Why the boundary moved (manual, recalc, restart) — mirrors `memorization_boundary_history.reason`. |
| `app/Models/MemorizationSource.php` | Where a page history row came from (manual, import) — mirrors `memorization_history.source`. |
| `app/Repositories/MemorizationStateRepository.php` | State row + append-only boundary/page history writes (transaction callers only). |
| `app/Repositories/RevisionPlanRepository.php` | Pauses active revision plans whose range no longer fits a shrunken boundary. |
| `app/Services/MemorizationProgressService.php` | Establish / mark / confirmed correction / read-only state; append-only history; dependent recalculation. |
| `app/Services/HifzCalculationService.php` | (+) `nextPageToMemorize()`: boundary + 1, `null` at the dataset edge, fails closed beyond it. |
| `app/Controllers/MemorizationController.php` | Progress endpoints (`GET/POST /memorization/state`, `POST /memorization/history`). |
| `app/Validators/{EstablishState,CorrectBoundary,MarkMemorized}Validator.php` | Input rules for the progress payloads. |
| `docs/api/memorization.md` | Endpoint, payload and rule documentation. |

### Revision system (Prompt 10)

| File | Responsibility |
| --- | --- |
| `app/Models/Revision{Plan,Cycle,Segment,Session}Status.php` | Status enums mirroring the `revision_*` tables (+ transition helpers). |
| `app/Repositories/RevisionPlanRepository.php` | Plan rows: create, one-unfinished-plan count, target/status writes. |
| `app/Repositories/RevisionCycleRepository.php` | Cycle rows: latest/by-plan reads, status transitions, segment count. |
| `app/Repositories/RevisionSegmentRepository.php` | Segment rows with cycle/plan context, tail regeneration helpers, skip/complete writes. |
| `app/Repositories/RevisionSessionRepository.php` | Session attempts: open/read/history, progress + finalize guarded by `ended_at IS NULL`. |
| `app/Repositories/SettingsRepository.php` | (+) `findDefaultRevisionTarget()`: saved `user_settings` daily revision defaults. |
| `app/Services/RevisionService.php` | Plans, cycles and segments: segmentation from the memorized range, status machines, missed-day derivation, target changes, cycle generation/supersession. |
| `app/Services/RevisionSessionService.php` | Sessions: open/resume (progress inheritance), sequential server-derived progress, finalize + cycle settlement. |
| `app/Controllers/RevisionController.php` | Plan/cycle/segment endpoints (`/revision/plans…`, `/revision/cycles…`, `/revision/segments…`). |
| `app/Controllers/RevisionSessionController.php` | Session endpoints (`/revision/sessions…`). |
| `app/Validators/{CreatePlan,UpdateTarget,PlanStatus,GenerateCycle,SegmentStatus,StartSession,SessionProgress,FinishSession}Validator.php` | Input rules for the revision payloads. |
| `docs/api/revision.md` | Endpoint, payload and rule documentation. |

### Revision session experience (Prompt 11)

| File | Responsibility |
| --- | --- |
| `public/shell.html` | The single HTML document (`lang="ar"`, `dir="rtl"`): app header, `<main>` mount, toast region, `<template>` blocks for login, register, revision view, session panel and interruption sheet. |
| `public/css/tokens.css` | Design tokens (colors, spacing, type scale, radii, z-index) once, incl. `prefers-color-scheme` dark overrides. |
| `public/css/base.css` | Reset, typography, header/main layout, button styles. |
| `public/css/components/*` | Forms, cards, progress bar, toast, bottom sheet. |
| `public/css/pages/*` | Login and revision screen layout. |
| `public/js/core/dom.js` | DOM helpers (query, clear, template cloning, slot text) — no DOM access at import time. |
| `public/js/core/api-client.js` | The only `fetch()` in the app: `/api/v1` envelope handling, `ApiError` with per-field errors, same-origin cookies. |
| `public/js/core/auth.js` | Session state (`fetchMe`/`login`/`register`/`logout`); identity itself stays in the HttpOnly cookie. |
| `public/js/core/router.js` | Hash router (`#/login`, `#/register`, `#/revision`) — deep links never hit the server. |
| `public/js/core/app.js` | Entry: auth boot, route table, auth guards, logout wiring. |
| `public/js/pages/*` | Login and register form wiring (field errors, navigation). |
| `public/js/components/*` | Toast, bottom sheet, progress bar, form error helpers. |
| `public/js/features/revision/revision-api.js` | Typed wrappers over the revision endpoints. |
| `public/js/features/revision/session-page.js` | The revision screen: today's state machine (no plan / paused / ready / in session / done), live session panel (next/pause/finish/interrupt), segment list. |
| `app/Controllers/ShellController.php` | `GET /` → serves `public/shell.html`. |
| `routes/web.php` | Non-API route table (currently just `/`). |
| `app/Response.php` | (+) `Response::html()` factory for the shell. |
| `app/Services/RevisionService.php` | (+) `mapSessionWithDisplay()`: server-computed `current_page` / `pages_remaining` / `percent_complete` on every session payload. |
| `docs/frontend/revision-session.md` | Frontend architecture, states, data flow and manual test checklist. |

### Rolling Rabt — الربط (Prompt 12)

| File | Responsibility |
| --- | --- |
| `app/Services/HifzCalculationService.php` | (+) `MAX_RABT_PAGES = 30`, pure `rabtBounds()` + `rabtRange()`: the newest ≤30 memorized pages ending at the boundary, derived at read time (slides automatically with every boundary change; never includes an unmemorized page). |
| `app/Controllers/MemorizationController.php` | (+) `GET /memorization/rabt`. |
| `routes/api.php` | (+) `/api/v1/memorization/rabt` (AuthMiddleware). |
| `tests/Unit/RabtRangeTest.php` | Pure window rules without a database: the 30 cap, the prompt's slide example, fewer-than-30 fallback, window seams, fail-closed guards. |
| `tests/Integration/RabtTest.php` | State-derived behaviour: 404 without state, sliding on mark/correction/growth, memorized-only invariant, user isolation. |
| `docs/api/memorization.md` | Endpoint, payload and rule documentation. |

### Flip cards — بطاقات الأخطاء (Prompt 13)

| File | Responsibility |
| --- | --- |
| `database/migrations/0010_flip_cards_status_in_review.sql` | `ALTER` appending the fourth card state `in_review` to `flip_cards.status` (run-once, registered with its SHA-256). |
| `app/Models/FlipCardStatus.php` | 4-state enum (`active`/`in_review`/`mastered`/`archived`) with `inQueue()`. |
| `app/Models/FlipCardReviewResult.php` | Review outcome enum (`recalled`/`partial`/`forgotten`). |
| `app/Models/FlipCardSeverity.php` | Severity enum (`low`/`medium`/`high`, column default `medium`). |
| `app/Validators/FlipCardCreateValidator.php` | Flag-error payload rules (location + required note ≤500). |
| `app/Validators/FlipCardReviewValidator.php` | Review payload rules (result, optional duration/notes). |
| `app/Validators/FlipCardStatusValidator.php` | Status transition rules (all four states). |
| `app/Validators/FlipCardQueryValidator.php` | List/queue query rules (`limit` 1–100, optional filters). |
| `app/Repositories/FlipCardRepository.php` | Cards CRUD + category checks, queue query (never-reviewed → least recently reviewed → oldest), status/review updates, seeded vocabulary. |
| `app/Repositories/FlipCardReviewRepository.php` | Append-only `flip_card_reviews` history (newest first reads). |
| `app/Services/FlipCardService.php` | Canonical location validation (ayah exists, page carries it), review tracking with the atomic `active → in_review` first-review promotion, status machine (same state = idempotent), explicit delete with history cascade. |
| `app/Controllers/FlipCardController.php` | (+) 8 flip-card endpoints (envelope + status codes only). |
| `routes/api.php` | (+) `/api/v1/flip-cards...` (AuthMiddleware; static `categories`/`queue` paths registered before `{card_id}`). |
| `tests/Unit/FlipCardValidatorsTest.php` | 41 checks — validator rules without a database. |
| `tests/Integration/FlipCardTest.php` | 65 checks — location validation, queue ordering, review tracking + auto-promotion, status machine, ownership isolation, delete cascade. |
| `docs/api/flip-cards.md` | Endpoint, payload and rule documentation. |

### Daily productivity — المهام اليومية (Prompt 14)

| File | Responsibility |
| --- | --- |
| `app/Models/TaskStatus.php` | 4-state enum (`pending`/`active`/`completed`/`skipped`): `canTransitionTo()` — pending ⇄ active, both can skip/revert, `completed` terminal. |
| `app/Models/TaskCategory.php` | Type category enum (`quran`/`general`) with `isGeneral()`. |
| `app/Validators/{CreateTask,UpdateTask,TaskStatus,TaskDayQuery,TaskHistory}Validator.php` | Input rules: create (type, title ≤150, duration 1–1440, date), update (all optional, **no `scheduled_date`**), status, day query, history range. |
| `app/Repositories/TaskRepository.php` | Day/history reads with type + completion join, create, partial edit (null = unchanged), guarded status/complete writes (`AND status <> 'completed'`), delete. Seeded vocabulary reads. |
| `app/Repositories/TaskCompletionRepository.php` | Append-only `task_completions` history (UNIQUE one-per-task). |
| `app/Services/TaskService.php` | `DEFAULT_HISTORY_LIMIT = 50`, `PERCENT_PRECISION = 2`; day view + server-owned summary (counts, planned minutes, `round(…, 2)` %), status machine (same state idempotent, completed terminal, completion = one transaction), edit rules (date immutable, general needs title), history, **Hifz isolation** — productivity tables never touch memorization data. |
| `app/Controllers/TaskController.php` | (+) 8 task endpoints (envelope + status codes only). |
| `routes/api.php` | (+) `/api/v1/task-types`, `/api/v1/tasks...` (AuthMiddleware; static `task-types`/`history` paths registered before `{task_id}`). |
| `tests/Unit/TaskValidatorsTest.php` | 38 checks — validator rules without a database. |
| `tests/Integration/TaskTest.php` | 80 checks — vocabulary, day summary math, terminal status machine, edit rules, history, ownership, snapshot-proven Hifz isolation, delete cascade. |
| `docs/api/tasks.md` | Endpoint, payload, status machine and summary-math documentation. |

### Main dashboard (Prompt 15)

| File | Responsibility |
| --- | --- |
| `public/shell.html` | (+) `view-dashboard` (hero identity + date, control row, summary card, task groups, Quran activity grid), `item-task`, `sheet-task-create`, `sheet-settings`, `view-soon` templates; SVG data-URI favicon; header brand links to `#/`. |
| `public/css/pages/dashboard.css` | Dashboard layout: hero, 4-up controls, 2×2/4-up stats, task groups/items, activity grid, settings sheet switches, placeholder screen. |
| `public/css/tokens.css` | (+) `:root[data-theme='light'/'dark']` manual overrides (auto = system `prefers-color-scheme`). |
| `public/js/core/prefs.js` | Persisted preferences: theme cycle auto/light/dark, Wake Lock toggle with visibility re-acquire, sound toggle (`localStorage`, no DOM at import time). |
| `public/js/features/dashboard/dashboard-page.js` | Orchestration: date, server-owned summary + motivational line (no numbers), task groups, independent activity cards with graceful fallbacks, error/retry. |
| `public/js/features/dashboard/controls.js` | Control row (screen awake, sound, day/night, settings) — settings sheet extracted in Prompt 16. |
| `public/js/pages/soon-page.js` | `view-soon` placeholder for the reserved `#/rabt` and `#/flip-cards` routes. |
| `public/js/core/app.js` | (+) routes `/` (dashboard), `/rabt`, `/flip-cards`; `initPrefs()` at boot; auth redirects now land on `/`. |
| `public/js/pages/{login,register}-page.js` | (+) post-auth navigation → `/`. |
| `docs/frontend/dashboard.md` | Architecture, data flow, screen states, manual verification checklist. |

**All dashboard statistics come from the backend** (`GET /tasks` summary, `GET /memorization/state`, `GET /memorization/rabt`, `GET /flip-cards/queue`) — nothing is hard-coded client-side.

### JavaScript architecture (Prompt 16)

| File | Responsibility |
| --- | --- |
| `public/js/core/app.js` | Application initialization only: preferences, header subscription on the session store, logout, route registration, auth boot, router start. |
| `public/js/core/routes.js` | Route table (`/`, `/login`, `/register`, `/revision`, `/rabt`, `/flip-cards`) with auth guards and fallback redirect. |
| `public/js/core/state.js` | `createStore` observable store + shared `appState` (user); login/logout re-sync the header. |
| `public/js/core/auth.js` | Session handling backed by `appState`; `requireSession()` guard. |
| `public/js/core/prefs.js` | (+) desktop-notification preference (`rafeeq.notify`). |
| `public/js/components/notifications.js` | `notify.info/success/error` facade over toasts + desktop-notification helpers (permission, opt-in, show). |
| `public/js/features/tasks/` | `tasks-api.js` (8 endpoints), `task-list.js` (groups + quick actions), `task-create.js` (create sheet) — split from the dashboard. |
| `public/js/features/ribat/memorization-api.js` | `/memorization/state|history|rabt` wrappers (establish, boundary, memorize, ربط window). |
| `public/js/features/flip-cards/flip-cards-api.js` | The 8 flip-card endpoints (queue, categories, CRUD, review, status). |
| `public/js/features/settings/settings-sheet.js` | Settings sheet: theme, awake, sound, desktop-notification switch, account, logout. |
| `public/shell.html` | (+) `data-slot="notify-row"` preference row in `sheet-settings`. |
| `docs/frontend/architecture.md` | Folder layout, core modules, notifications facade, feature map, binding rules. |

All user feedback goes through `notify.*`; all HTTP goes through `core/api-client.js`; the client performs no Hifz/summary math.

### CSS design system (Prompt 17)

| File | Responsibility |
| --- | --- |
| `public/css/tokens.css` | (+) `--font-weight-*`, `--touch-target`, `--transition-med`, `--skeleton-base/shine`, `--z-modal` — all design tokens in one place, three theme blocks. |
| `public/css/base.css` | Shell only: reset, full heading scale (h1–h6), `.text-sm/.text-muted`, `.app-main`, boot message. Buttons/header moved out. |
| `public/css/components/buttons.css` | `.btn` + tone/size modifiers (moved from `base.css`; `.btn--sm` moved from `dashboard.css`). |
| `public/css/components/navigation.css` | `.app-header*` sticky header (moved from `base.css`). |
| `public/css/components/task-list.css` | `.task-group*`, `.task-item*` (extracted from `dashboard.css`, names unchanged). |
| `public/css/components/quran-cards.css` | `.dash-activities` grid + `.activity*` cards (extracted from `dashboard.css`). |
| `public/css/components/states.css` | `.state-card` family (moved from `card.css`) + `--error` icon tone + `.spinner`, `.skeleton` loaders with reduced-motion handling. |
| `public/css/components/alerts.css` | `.alert` + `--success/--warning/--danger` inline messages (wired into the dashboard error card). |
| `public/css/components/modal.css` | Centered `.modal` dialog (confirmations); bottom sheet stays the primary overlay. |
| `docs/frontend/design-system.md` | Token map, component inventory, load order, CSS rules. |

### Settings & account (Prompt 18)

| File | Responsibility |
| --- | --- |
| `app/Models/ThemeMode.php` | API `auto\|light\|dark` ↔ storage `auto\|day\|night` theme enum mapping. |
| `app/Validators/{UpdateSettings,UpdateProfile,ChangePassword,DeleteAccount}Validator.php` | Input rules for preferences, profile, password change and deletion. |
| `app/Repositories/SettingsRepository.php` | `user_settings` reads/writes (whitelisted columns, self-healing read, revision-default lookup); never touches Hifz tables. |
| `app/Services/SettingsService.php` | Preference round-trip: partial updates, theme mapping, keep-current empty strings, 3-dp amount rounding. |
| `app/Services/AccountService.php` | Personal-data export (per-user datasets, no secrets) + soft delete (anonymize + revoke; Hifz history retained pseudonymously). |
| `app/Repositories/AccountRepository.php` | Per-dataset export queries, every one scoped `WHERE user_id = ?`. |
| `app/Controllers/{SettingsController,AccountController}.php` | `GET/PUT /settings`, export attachment, delete with cookie clearing. |
| `app/Services/AuthService.php` | (+) `updateProfile` (trim, duplicate-email 422) + `changePassword` (verify, must-differ, revokes every session). |
| `app/Controllers/AuthController.php` | (+) `PUT /auth/profile`, `PUT /auth/password` actions. |
| `routes/api.php` | (+) 6 routes: settings GET/PUT, auth profile/password, account export/delete. |
| `public/js/features/settings/{settings-api,settings-sync,settings-sheet}.js` | Endpoint calls, boot-time reconcile, sheet behavior (optimistic write-through with rollback). |
| `public/js/components/modal.js` | Centered confirmation modal used by the delete-account dialog. |
| `public/shell.html` | Rewritten `sheet-settings` (display/sound, language, revision defaults with consequence hint, account, privacy) + `modal-delete-account`. |
| `docs/api/settings.md` | Endpoints, theme mapping, preference-vs-Hifz boundary, export shape, soft-delete model. |

### PWA (Prompt 19)

| File | Responsibility |
| --- | --- |
| `public/manifest.json` | Arabic RTL manifest: standalone display, teal theme, `any` 192/512 + `maskable` 512 icons. |
| `public/assets/icons/` | Generated icons (master source: `tools/app-icon.png`, outside the docroot): `icon-192/512`, `icon-maskable-512`, `apple-touch-icon-180`. |
| `tools/generate-icons.php` | Pure-PHP (no GD) icon pipeline: decode → downsample → maskable compose → PNG encode. |
| `public/sw.js` | Versioned precache; network-first navigations, stale-while-revalidate assets; `/api/*` + `/uploads/*` never cached; skipWaiting + claim, old-cache pruning. |
| `public/js/core/pwa.js` | SW registration (secure-context guarded), one-time update toast, offline banner state, `isOffline()`. |
| `public/js/core/app.js` | (+) `initPwa()` at boot; offline-aware `fetchMe` failure message. |
| `public/shell.html` | (+) manifest/apple metas, `viewport-fit=cover`, `#offline-banner`. |
| `public/css/components/alerts.css` | (+) `.offline-banner` fixed strip + toast offset under `html[data-offline]`. |
| `app/Middleware/SecurityHeadersMiddleware.php` | (+) `Cache-Control: no-store` for `/api/*`, `no-cache` elsewhere; `ExceptionHandler` mirrors it. |
| `public/js/features/settings/{settings-sync,settings-sheet}.js` | (+) last-good snapshot (`saveSnapshot`/`loadSnapshot`/`restoreFromSnapshot`) for offline write-through rollback. |
| `docs/frontend/pwa.md` | Manifest, caching table, sync-safety rules, update flow, offline UX. |

The shell installs and renders offline; API data never enters Cache Storage and offline writes fail fast instead of queueing.

### Accessibility & RTL (Prompt 20)

| File | Responsibility |
| --- | --- |
| `public/js/components/dialog-focus.js` | Focus-trap stack: Tab wraps inside the top-most overlay, Escape closes it, focus restores to the opener (sheets + modal). |
| `public/js/components/form-errors.js` | (+) generated error ids, `aria-describedby` with hint preservation, `role="alert"` messages, `focusFirstInvalid()`. |
| `public/js/core/router.js` | (+) focus `#app` after each route change (except boot) + `aria-current="page"` on the active header link. |
| `public/js/core/app.js` | (+) skip-link click handler (focuses `#app`). |
| `public/js/core/dom.js` | (+) `setSlotBdi()` — bidi-isolated slot text (`<bdi>`) for mixed Arabic/numeric fragments. |
| `public/shell.html` | (+) skip link, `main#app[tabindex="-1"]`, `dir="ltr"` on Latin inputs, `lang="en" dir="ltr"` wordmark, roving-tabindex locale radiogroup, `tabindex="-1"` task items. |
| `public/css/base.css` | (+) `.skip-link`, route-focus outline suppression, global `prefers-reduced-motion` token collapse. |
| `public/js/components/toast.js` | (+) error toasts promote the live region to `role="alert"`. |
| `tools/check-contrast.php` | Zero-dep WCAG audit: 36 token pairs (light + dark), min 4.5 text / 3.0 focus, non-zero exit on failure. |
| `docs/frontend/accessibility.md` | Keyboard map, ARIA patterns, contrast results, 44 px targets, reduced-motion policy, bidi/digit rules. |

All layout uses logical properties (zero physical direction CSS), dates render with Western digits (`ar-u-nu-latn`), and mixed Arabic/English/numeric runs are isolated in `<bdi>`/`lang="en"` islands.

### Notifications & reminders (Prompt 21)

| File | Responsibility |
| --- | --- |
| `public/js/features/reminders/reminders.js` | Daily scheduler: one combined notification/day inside a 10-min window after the chosen time, only for enabled categories with actual pending work; delivery chain `new Notification` → SW `showNotification` → in-app toast. |
| `public/js/core/prefs.js` | (+) `get/setReminders` (`rafeeq.reminders` = `{time, cats}`), per-day `get/markReminderSent` stamps. |
| `public/js/features/settings/settings-sheet.js` | (+) reminders section: time input + 5 category switches (visible only while notifications are usable). |
| `public/shell.html` | (+) `sheet-settings` reminders section (وقت التذكير + جدولة/ربط/حفظ/بطاقات/مهام switches + hint). |
| `public/sw.js` | (+) `notificationclick` (focus open window or open `/`); precaches `reminders.js`; cache `rafeeq-static-v3`. |
| `docs/frontend/notifications.md` | Opt-in principles, respect rules, pending-work table, capability/fallback matrix, storage keys. |

Reminders are device-local and opt-in (master = the existing device-notification switch): at most one combined notification per day, silent when nothing is due, and graceful degradation everywhere — no Web Push, so an app that is closed sends nothing.

### Progress analytics (Prompt 22)

| File | Responsibility |
| --- | --- |
| `app/Repositories/ProgressRepository.php` | Aggregate SQL for the snapshot: window counts, distinct active days, status groups, bounded recent-activity feeds (all user-scoped). |
| `app/Services/ProgressAnalyticsService.php` | Server-side math: 14-day consistency window, 7-day productivity window, percent helpers, time-ordered merge of recent activity (read-only). |
| `app/Controllers/ProgressController.php` | `GET /progress/summary` → `{analytics}` behind `AuthMiddleware`. |
| `public/js/features/progress/progress-api.js` | Endpoint wrapper (payload only — no client math). |
| `public/shell.html` | (+) dashboard `.dash-progress` section: three stat cards (حفظ / المراجعة / البطاقات والمهام) + consistency bar + recent-activity list. |
| `public/js/features/dashboard/dashboard-page.js` | (+) `renderAnalytics`/`renderRecent` — neutral labels, Western digits, `—` fallbacks, UTC timestamps formatted with `ar-u-nu-latn`. |
| `public/css/pages/dashboard.css` | (+) `.dash-progress*` and `.dash-recent*` (logical properties, tokens only). |
| `tests/Integration/ProgressAnalyticsTest.php` | Fresh zeros, window math, counts, merge order, read-only guarantee, isolation (61 checks). |
| `docs/api/progress.md` | Endpoint, analytics object, window definitions, recent-activity rules, ownership. |

The snapshot is awareness, not competition: no leaderboards, scores, streak counters or comparisons — just the user's own numbers over preserved history.

### Data export & backup (Prompt 23)

| File | Responsibility |
| --- | --- |
| `app/Repositories/AccountRepository.php` | (+) Referenced-vocabulary subsets (`task_types`, `flip_card_categories`): only the seeded rows this user's tasks/cards point at. |
| `app/Services/AccountService.php` | (+) `exportCsv()` — sectioned CSV renderer (UTF-8 BOM, `# dataset:` markers, NULL→empty/bool→0/1 cells, `?dataset` filter) over the same export arrays as JSON. |
| `app/Controllers/AccountController.php` | (+) `?format=json\|csv` + `?dataset=` validation (`422`), attachment headers; CSV is raw `text/csv`, JSON stays enveloped. |
| `app\Response.php` | (+) `Response::csv()` — raw file body (the body is the file, not an envelope). |
| `public/js/core/api-client.js` | (+) `api.getText()` — raw-body GET for file downloads; JSON error envelopes still raise `ApiError`. |
| `public/js/features/settings/settings-api.js` | (+) `exportAccountCsv()` endpoint wrapper. |
| `public/js/features/settings/settings-sheet.js` | (+) shared `runExport()` — JSON and CSV buttons download via Blob (disable/toast/401 handling unchanged). |
| `public/shell.html` | (+) privacy section: `تصدير بياناتي (CSV)` button + updated hint. |
| `tests/Integration/AccountTest.php` | (+) vocabulary subsets, CSV BOM/17 sections/header/filter/422, secret scans (56 checks total). |
| `docs/api/export.md` | Format spec (JSON bundle + sectioned CSV) and the future import/restore contract. |

JSON (`rafeequl-hifz-export-v1`) stays the canonical, import-oriented format — additive keys only, deterministic order, original ids preserved for remapping; CSV is a faithful human/spreadsheet view of the same arrays. Passwords, hashes, session/reset tokens and server secrets are structurally impossible to export (column/table whitelists, scanned by tests in both formats).

### Comprehensive testing & QA (Prompt 24)

| File | Responsibility |
| --- | --- |
| `app/Validators/Validator.php` | (+) Root fix for the empty-input contract: `""`/whitespace on an optional field normalizes to `null` ("not provided"), with a `rejectEmpty()` hook for fields that must reject present-but-empty input (profile `email`). |
| `app/Validators/UpdateProfileValidator.php` | (+) `rejectEmpty(): ['email']` + the matching 422 message — an explicitly cleared email is never stored or silently dropped. |
| `app/Models/TaskStatus.php` + `app/Services/TaskService.php` | (+) `skipped → completed` blocked (`422`, "must be reopened"); docs in `docs/api/tasks.md` / `docs/database/schema.md` corrected to match. |
| `app/Services/SettingsService.php` | (+) Unit/amount cross-field validation: the resulting stored pair must be plan-creation-consumable (`hizb`/`juz`/`rub` ⇒ whole ≥ 1), checked against the stored other half on partial updates. |
| `app/Services/FlipCardService.php` + `app/Repositories/FlipCardRepository.php` | (+) One queued card per location: duplicate `active`/`in_review` card at the same ayah → `422`; re-flag allowed after `mastered`/`archived`; scoped per user. |
| `app/Services/AuthService.php` + `app/Repositories/UserRepository.php` | (+) Lockout fixes: `locked_until` parsed as UTC, expiry clears the counter (fresh window); register/update-profile `UNIQUE` race answered with `422`, never `500`. |
| `app/Services/PasswordResetService.php` | (+) Timing padding on both forgot-password paths (identical elapsed time, not just identical body). |
| `app/Services/ProgressAnalyticsService.php` + `app/Repositories/ProgressRepository.php` | (+) Date-anchored windows with both bounds — future-dated rows (e.g. ahead-of-time tasks) can no longer inflate the 14/7-day counts. |
| `app/Services/AccountService.php` | (+) CSV formula-injection escape (leading `= + - @` tab/CR prefixed with `'`, CSV rendering only — JSON stays canonical). |
| `tests/Unit/ValidatorEmptyStringTest.php` | Empty-input contract across validators (24 checks). |
| `tests/Integration/QaEdgeCasesTest.php` | The behavioral fixes end-to-end + cross-user isolation (32 checks). |
| `tests/Integration/SchemaConstraintsTest.php` | DDL guarantees: enum shapes, migration 0010 preserves rows, UNIQUE email, one completion per task, cascades (11 checks). |
| `tests/Integration/CanonicalDatasetTest.php` | Imported canonical dataset: invariants + full §5/§6 structural suite + provenance chain on the DB copy (30 checks; SKIPPED without a canonical import). |
| `tests/Integration/CanonicalEngineTest.php` | Prompt 08 engine on the real 604-page Madinah data: page boundaries, memorized ranges, division targets, 30-page Rabt window, final shorter segment (46 checks; SKIPPED without a canonical import). |
| `tools/quran-data/` | Dataset pipeline CLIs: `acquire` · `normalize` · `verify` · `import` · `audit` (+ shared `lib.php`); see `docs/quran-data/dataset-pipeline.md`. |
| `%TEMP%\opencode\test_qa.ps1` | HTTP QA smoke: gates, empty-query defaults, pair validation, skip/complete over HTTP, duplicates, CSV escape, lockout expiry, responsive static checks (57 checks). |
| `docs/testing/test-matrix.md` | The four test layers, coverage by area, and the deliberate gaps with rationale. |

### Security audit (Prompt 25)

| File | Responsibility |
| --- | --- |
| `docs/security/security-audit.md` | Audit report: methodology, 16-category verdict table, findings register (F-01…F-05 + informational notes), residual risks, re-audit checklist. |
| `app\Response.php` | (+) `withSecurityHeaders()` also sends a strict `Content-Security-Policy` (`default-src 'self'`, `frame-ancestors 'none'`, no inline/eval — frontend verified CSP-clean), `Permissions-Policy`, and `Strict-Transport-Security` over HTTPS (finding F-02, fixed). |
| `app/Middleware/RateLimitMiddleware.php` | Fixed-window throttle for the three unauthenticated auth endpoints: per (route, IP) and per (route, e-mail), uniform `429` envelope, auto-disabled under `APP_ENV=testing` (finding F-03, fixed). |
| `app/Services/RateLimitService.php` + `app/Repositories/RateLimitRepository.php` | Window rule (allow up to max → block → reset on expiry) over an atomic `INSERT … ON DUPLICATE KEY UPDATE` counter; bucket keys stored as SHA-256 hashes only (no raw IP/e-mail persisted). |
| `database/migrations/0011_rate_limits.sql` | `rate_limits` table (64-hex bucket key, window index), registered with its SHA-256. |
| `config/auth.php` + `.env.example` | (+) `rate_limit_enabled` / `rate_limit_ip_max` (10) / `rate_limit_email_max` (5) / `rate_limit_window_seconds` (60) — env keys `RATE_LIMIT_*`. |
| `routes/api.php` | (+) `RateLimitMiddleware` on `POST auth/register`, `auth/password/forgot`, `auth/password/reset`. |
| `tests/Integration/RateLimitTest.php` | Window semantics, key isolation, hash-only storage, pruning, escape hatches, APP_ENV flag (16 checks). |
| `%TEMP%\opencode\test_security.ps1` | HTTP security smoke: CSP/Permissions-Policy on 200 + 404, no HSTS over plain HTTP, cookie flags, 405, email- and IP-bucket `429` envelopes (23 checks; boots with `APP_ENV=production` so the limiter is active). |

No Critical or High findings were identified; both Medium findings (missing CSP, unthrottled register/forgot/reset endpoints) are fixed and regression-tested.

### Performance optimization (Prompt 26)

Measure-first pass over queries, payloads, caching and loading. Baselines and re-measurement steps live in `docs/deployment/performance.md`.

| File | Responsibility |
| --- | --- |
| `docs/deployment/performance.md` | Performance report: measured baseline (boot payloads, shell/static weights, precache, EXPLAIN sweep), post-fix deltas, hosting checklist, re-measure instructions. |
| `app\Response.php` | (+) gzip in `send()` — only when the client offers `Accept-Encoding: gzip`, the result is actually smaller, and the UA is not legacy PowerShell (boot API 11.2 KB → ≈1.8 KB, shell 34 KB → ≈6.2 KB on the wire). |
| `public/sw.js` | Precache trimmed from 761 KB to ≈221 KB (only `icon-192`; install-time icons fetched via manifest on demand), cache `rafeeq-static-v5`, navigations now cache-first with background revalidate. |
| `public/js/core/routes.js` | Page modules (dashboard/login/register/revision) load via dynamic `import()` behind a navigation token; initial boot graph shrinks by 19 KB of page files. |
| `public/js/core/app.js` | Boot: settings reconcile runs alongside `startRouter()` instead of blocking the first paint (−1 serial round trip). |
| `public/js/features/dashboard/dashboard-page.js` | Day summary and the four activity calls fire concurrently (−1 serial round trip). |
| `public/.htaccess` | Apache layer: `mod_deflate` compression, 1-week asset expiry (never immutable — assets are unhashed), `no-cache` for `sw.js`/`manifest.json`/`shell.html`, front-controller rewrite, dotfile denial. |
| `tools/app-icon.png` | 1.27 MB icon source master moved out of `public/` (`tools/generate-icons.php` regenerated the full icon set byte-identical afterwards). |
| `docs/frontend/pwa.md` | Updated: cache-first navigation strategy, `rafeeq-static-v5`, icon master location. |

Query review (EXPLAIN sweep over every hot shape, incl. all Quran lookups): every plan is `const`/`ref`/`range`/covering-index; per-user scans stay ≤600 rows by design — **no index migration was needed**, so Quran-data behavior is untouched.

### Deployment to InfinityFree (Prompt 27)

Host-portable deployment: build one bundle, upload it, import one SQL file. The document-root-below-app-root split maps exactly onto shared hosting (`htdocs/` = `public/`), so nothing in the app needed restructuring; the complete process, hosting limits, "never expose" compliance and portability rules live in `docs/deployment/infinityfree.md`.

| File | Responsibility |
| --- | --- |
| `tools/deploy/build-bundle.php` | (+) Builds `deploy/dist/` (upload payload: `htdocs/` ← `public/`, plus `app/ config/ routes/ storage/ .env.example` siblings) and `deploy/sql/rafeequl-hifz.sql` (migrations 0001–0011 verbatim + the 11 `schema_migrations` registry rows with SHA-256 semantics of `apply-migrations.php` + all `quran_*` canonical data, hard-gated on 6,236 ayahs / 604 pages), with SHA-256 manifests; audits that no `.env`, `tests/ docs/ tools/ data/ database/`, `*.log` or `*.sql` can enter the payload. |
| `tools/deploy/import-sql.php` | (+) Local stand-in for phpMyAdmin: imports the deploy SQL into an empty database (`--drop` to recreate), refuses non-empty targets, prints the JSON facts the smoke asserts (29 tables, 11 registry rows, canonical counts, seeds). |
| `app/Controllers/ShellController.php` | (+) Shell resolves from `DOCUMENT_ROOT` first (the deployed layout has no `public/` directory) with the dev path as fallback — fixes the deployed-layout `500` on `/`. |
| `app/Response.php` | (+) `isHttps()` also trusts `SERVER_PORT=443` for TLS terminated without `$_SERVER['HTTPS']`/`X-Forwarded-Proto` markers (shared-host edge case). |
| `docs/deployment/infinityfree.md` | The complete deployment process: layout mapping, verified hosting facts, build/upload/import/config/SSL steps, verification checklist, limits-vs-usage table, troubleshooting, portability (move checklist + nginx equivalent). |
| `.env.example` + `.gitignore` | (+) Hosting-value hints (panel DB host, `APP_URL=https://…`); `/deploy/` output is ignored (generated by the builder). |
| `%TEMP%\opencode\test_deploy.ps1` | HTTP deployment smoke (14th): build + payload audit + manifest hashes, SQL registry/canonical assertions, fresh-DB import, boot from the exact `htdocs/` split (shell, SW cache version, health, gzip over a browser UA, 401/404 envelopes, source + `.env` unreachable), register/login against the fresh DB (43 checks). |

## Production readiness review (Prompt 28)

Full 20-area production-readiness review with severity-classified findings (BLOCKER, CRITICAL, HIGH, MEDIUM, LOW, OPTIONAL). Verdict: **deployable** — the original 1 BLOCKER (PR-01: core memorization/flip-card write flows had no UI) was resolved on 2026-10-06 by shipping the establish/mark/correct sheets and the full `#/flip-cards` screen, with the full regression + 14 smokes re-run green; PR-02 (stale precache figures) is also fixed. Remaining: 2 LOW (schema doc gap, soft-delete hygiene) + 4 OPTIONAL — none gate deployment; the gate categories (Quran-data, security, authentication, data-integrity) were and remain clean.

| File | Responsibility |
| --- | --- |
| `docs/reviews/production-readiness.md` | 20-area scorecard, re-measured baseline (lint / JS / CSS / suite / 14 smokes / quran audits), findings register PR-01…PR-08, accepted design decisions, exit criteria, review limitations. |

## Documentation

* `docs/testing/test-matrix.md` — testing & QA matrix: layers, coverage by area, deliberate gaps, full regression run order.
* `docs/security/security-audit.md` — security audit (Prompt 25): 16-category verdicts, findings with severities and fixes, residual risks, re-audit checklist.
* `docs/deployment/performance.md` — performance (Prompt 26): measured baseline and deltas (gzip, precache, boot round trips, lazy routes), EXPLAIN verdicts, hosting checklist, re-measure steps.
* `docs/deployment/infinityfree.md` — deployment (Prompt 27): InfinityFree layout mapping, build/upload/phpMyAdmin import, `.env`/HTTPS/sessions, "never expose" compliance, verification checklist, host limits, troubleshooting, portability to any host.

* `docs/reviews/production-readiness.md` — production readiness review (Prompt 28): 20-area scorecard, baseline evidence, findings register (BLOCKER … OPTIONAL), accepted decisions, exit criteria.
* `docs/architecture/coding-conventions.md` — binding coding conventions (PHP, JS, CSS, HTML, database, API, naming, ownership, security). Read before writing any code.
* `docs/frontend/revision-session.md` — revision session screen: architecture, states, data flow, verification checklist.
* `docs/frontend/dashboard.md` — main dashboard: architecture, data flow, screen states, verification checklist.
* `docs/frontend/architecture.md` — JavaScript architecture: folder layout, core modules, notifications facade, feature map, binding rules.
* `docs/frontend/design-system.md` — CSS design system: tokens, components, load order, RTL/dark-mode rules.
* `docs/frontend/pwa.md` — PWA: manifest and icons, service-worker caching table, sync-safety rules, update flow, offline UX.
* `docs/frontend/accessibility.md` — accessibility & RTL: keyboard map, ARIA patterns, contrast, touch targets, reduced motion, Arabic/English mixed-text policy.
* `docs/frontend/notifications.md` — notifications & reminders: opt-in principles, respect rules, pending-work rules, capability/fallback matrix, storage keys.
* `docs/api/progress.md` — progress analytics: endpoint, analytics object, consistency/productivity windows, recent-activity merge rules, ownership.
* `docs/api/export.md` — data export & backup: JSON bundle + sectioned CSV formats, exclusions, future import/restore contract, ownership.
* `docs/api/settings.md` — settings, profile, password change, export (JSON/CSV) and soft-delete account documentation.
* `docs/quran-data/data-architecture.md` — canonical Madinah Mushaf data model, integrity rules, import and verification strategy.
* `docs/quran-data/dataset-pipeline.md` — the implemented Quran dataset pipeline: sources + licenses + checksums, processing/verification/import stages, validation results, reproduction steps, known limitations.
* `docs/database/schema.md` — full MySQL schema: domains, status machines, history policy, how to apply migrations.

## Setup

1. Copy `.env.example` to `.env` and fill in your local values.
2. Point the web server document root to the `public/` directory.
3. Serve with any PHP 8.x web server (e.g. `php -S localhost:8000 -t public`).

`.env` is git-ignored. Never commit real credentials.

## Repository Layout

```text
rafeequl-hifz/
├── public/      web root: assets, css, js, uploads, front controller
├── app/         backend: Controllers, Services, Repositories, Models, Validators, Helpers, Middleware, Exceptions
├── config/      configuration
├── routes/      route definitions
├── database/    migrations, seeds, backups
├── data/quran/  Quran dataset (source, processed, verification) — separate from user data
├── storage/     logs, cache, sessions
├── tests/       Unit, Integration, Feature, Security
├── docs/        product, architecture, database, api, quran-data, testing, security, deployment
└── tools/       database, deploy, quran-data, development utilities
```

## Development Rules

* Quran data must come from a verified, authoritative dataset. Never invent, estimate, or hard-code Quran page/verse boundaries.
* The server is authoritative for all critical Quran/Hifz calculations.
* Configuration stays outside version control; secrets are never committed.
* Development proceeds in controlled, staged prompts — no jumping ahead.
