# Frontend — Main Dashboard

Status: implemented (Prompt 15). The signed-in landing screen of Rafeequl Hifz: app identity, display controls, today's productivity, quick task management and shortcuts to the Quran activities.

## Serving

Served by the SPA shell (`GET /` → `public/shell.html`, `ShellController`). Hash route `#/` is the authenticated home; `#/login`, `#/register`, `#/revision`, `#/rabt`, `#/flip-cards` are the sibling routes. Deep links never hit the server.

## File map

| File | Responsibility |
| --- | --- |
| `public/shell.html` | `view-dashboard`, `item-task`, `sheet-task-create`, `sheet-settings`, `view-soon` templates; SVG data-URI favicon; header brand links home. |
| `public/css/pages/dashboard.css` | Hero, control row, summary stats, task groups/items, activity grid, progress-analytics section (Prompt 22), settings sheet, placeholder screen. |
| `public/css/tokens.css` | (+) `:root[data-theme='light'|'dark']` manual overrides on top of the system `prefers-color-scheme` default (auto = no override). |
| `public/js/core/prefs.js` | Persisted preferences (`localStorage`): theme cycle `auto → light → dark`, screen wake lock (Wake Lock API + visibility re-acquire), sound toggle; `initPrefs()` at boot. |
| `public/js/features/dashboard/dashboard-page.js` | Render orchestration: date, summary (server math only), task groups, activity cards, progress-analytics section (`renderAnalytics`/`renderRecent`, Prompt 22), error/retry, motivational line. |
| `public/js/features/dashboard/controls.js` | Control row wiring only (settings sheet moved to `features/settings/settings-sheet.js`, Prompt 16). |
| `public/js/features/tasks/{tasks-api,task-list,task-create}.js` | Task endpoints, group rendering + quick actions, create sheet (split out of the dashboard, Prompt 16). |
| `public/js/features/progress/progress-api.js` | `GET /progress/summary` wrapper — the dashboard's analytics snapshot (Prompt 22). |
| `public/js/pages/soon-page.js` | `view-soon` placeholder for `#/rabt` and `#/flip-cards` (reserved until those screens ship). |
| `public/js/core/routes.js` | Route table incl. `/`, `/rabt`, `/flip-cards` (Prompt 16). |
| `public/js/core/app.js` | Boot: preferences, header subscription, logout, route registration, auth fetch, router start (Prompt 16). |

## Data flow

```
GET /tasks                → summary {total, active, completed, completion_percent, …} + today's tasks
GET /task-types           → create-sheet vocabulary (fetched lazily on open)
POST /tasks               → create (201) → reload the day
PUT /tasks/{id}/status    → start / complete / skip / revert → reload the day
GET /memorization/state   → progress card (percent + pages)
GET /memorization/rabt    → rabt activity card (page range) — 404 before the range exists
GET /flip-cards/queue     → flip-cards activity card (queue count)
GET /progress/summary     → التقدم والمتابعة section: memorization, consistency, flip/task counts, recent activity (Prompt 22)
```

**Rule:** every displayed statistic comes from those responses — nothing is computed or hard-coded on the client. The motivational line is a client-side encouragement phrase (no numbers) picked by a bucket derived from the server summary.

## Screen states

1. **Loading** — day content shows `جارٍ تحميل المهام…`.
2. **Loaded** — hero (identity + Arabic Gregorian date), control row, summary card (message, 4 server numbers, progress bar), three task groups (pending incl. skipped with a `متجاهَل` badge, active, completed), activity grid.
3. **Error** — state card with `إعادة المحاولة` (retry re-runs the full refresh); `401` redirects to `#/login`.
4. **Activities** — each card resolves independently (`Promise.allSettled`); a failure or "not established" state degrades to muted text without breaking the page.
5. **Progress analytics (Prompt 22)** — the `التقدم والمتابعة` section loads with the activity batch: three server-numbered stat cards, a 14-day consistency bar, and the merged recent-activity list (neutral labels, Western digits, `ar-u-nu-latn` timestamps). A failure degrades every slot to `—` / `تعذر تحميل التقدم.` without touching the rest of the page.

## Controls

| Control | Behaviour |
| --- | --- |
| الشاشة | Wake Lock toggle (`aria-pressed`), persisted; disabled with a title when the API is missing; re-acquired on `visibilitychange`. |
| الصوت | Persisted on/off preference (`aria-pressed`); no audio exists yet — the switch governs future sounds. |
| الوضع | Theme cycle auto → light → dark, label shows the current mode; `auto` follows the OS via `prefers-color-scheme`. |
| الإعدادات | Bottom sheet: the three preferences as `role="switch"` rows + theme cycle, account email, logout. |

## Server endpoints used

`GET /tasks`, `POST /tasks`, `PUT /tasks/{id}/status`, `GET /task-types`, `GET /memorization/state`, `GET /memorization/rabt`, `GET /flip-cards/queue`, `GET /progress/summary`, `POST /auth/logout` (via settings sheet).

## Verification checklist (manual, browser)

1. Register → lands on `#/` dashboard; header brand and `خروج` behave; login/register redirect to the dashboard when already signed in.
2. Hero shows رفيق الحفظ / Rafeequl Hifz / today's Arabic date; favicon is the teal book.
3. Summary numbers match the API (`GET /tasks`); percent text and progress bar agree; empty day shows a "start" message with 0/0/0 and 0٪.
4. `مهمة جديدة` sheet: type list from `/task-types`, duration prefills the type default, general type reveals the required title, 422 field errors render under inputs, created task appears in the right group.
5. Quick actions: pending → `ابدأ` moves to active; `إنهاء` stamps completed (summary + % update after each action); `تجاهل` moves to the pending group with the `متجاهَل` badge; `استئناف` reverts. Double-taps are ignored while busy.
6. Activity cards: مراجعة → `#/revision`; ربط shows the server page range (or the "not established" text); بطاقات الأخطاء shows the server queue count; نطاق الحفظ shows server percent/pages with the bar.
7. Controls: awake persists across reloads (supported browsers only); sound persists; theme cycles auto/light/dark and survives reload (dark forced even when the OS is light); settings sheet toggles mirror the control row; logout returns to `#/login` and hides the header.
8. `#/rabt` and `#/flip-cards` render the placeholder with a working "back to dashboard" link; `#/revision` still works.
9. 401 anywhere (expired session) → redirected to `#/login`; dashboard load failure shows the retry card.
10. Narrow viewport (≤360px): 4 controls and 2 stat columns stay tappable; no horizontal scroll; keyboard focus is visible on controls, cards and task buttons.
11. `التقدم والمتابعة`: numbers match `GET /progress/summary` (no client-side math); unestablished memorization shows `—`; recent activity lists newest first with Arabic labels and `ar-u-nu-latn` timestamps; the section never shows scores, streaks or comparisons.
