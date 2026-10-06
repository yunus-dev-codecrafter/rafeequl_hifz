# Frontend — Notifications & Reminders

Status: implemented (Prompt 21). Optional, opt-in daily reminders for revision (جدولة), ربط, memorization, flip-card review and daily productivity tasks — delivered as **one combined device notification per day**.

## Principles

* **Opt-in** — nothing fires until the user grants browser permission and turns on the existing "التنبيهات المكتبية" switch in Settings. The row is hidden entirely when the browser does not support notifications.
* **Configurable** — reminder time (default 18:00) and five per-category switches (default ON once the master is on). All stored device-locally in `rafeeq.reminders` (localStorage), consistent with the notification permission itself being per-device.
* **Respectful / non-spammy**
  * at most **one notification per day** (per-day stamp `rafeeq.reminder.sent`);
  * only inside a **10-minute window** after the chosen time (if the app wasn't running then, the window closes and the day stays quiet);
  * only categories with **actual pending work** are listed — nothing due → nothing sent;
  * a single combined body (`• line` per category) instead of a burst of notifications.
* **Never assume support** — every capability is feature-detected and degraded (table below).

## Reminder categories & pending-work rules

| Category | API | Line appears when |
| --- | --- | --- |
| جدولة المراجعة (revision) | `listPlans()` → `planDetail()` | active plan has a segment `is_today` with status `pending`/`active` |
| مهام اليوم (tasks) | `dayTasks()` | `summary.pending + summary.active > 0` |
| بطاقات المراجعة (flip) | `listQueue()` | `cards.length > 0` |
| الحفظ (memorization) | `getState()` | `state.established` and `next_page_to_memorize !== null` |
| الربط (rabt) | `rabtRange()` | `rabt.page_count > 0` |

All five run through `Promise.allSettled`: a failed check (offline/401) counts as a *failure*, not as "nothing due" — the tick retries on the next minute while still inside the window. Western digits only; lines are short Arabic sentences.

## Capability / fallback matrix

| Situation | Behaviour |
| --- | --- |
| `Notification` API absent | Settings row + reminders section hidden; scheduler exits (eligible() false). |
| Permission not granted / denied | Master switch reports the denial (existing flow); scheduler never fires. |
| Master switch off | Scheduler idles; config is preserved for when it comes back. |
| `new Notification()` throws (e.g. Firefox Android) | Falls back to `ServiceWorkerRegistration.showNotification()`. |
| No service worker / that also fails | Falls back to an in-app toast (`notify.info`); marked sent only when the tab is visible. |
| iOS Safari (non-installed tab) | Unsupported path above → hidden row, silent — no error, no assumption. |
| App/tab closed | **No reminder** — reminders are device-local; there is no Web Push. Documented limitation. |
| Reminder clicked | Classic notification → `onClick` focuses the app and routes to `/`; SW notification → `notificationclick` focuses an open window or opens `/`. |

## Storage keys (localStorage)

| Key | Shape | Owner |
| --- | --- | --- |
| `rafeeq.notify` | `'on' \| 'off'` — master switch (existing) | `core/prefs.js` |
| `rafeeq.reminders` | `{time: "HH:MM", cats: {revision, rabt, memorization, flip, tasks}}` | `core/prefs.js` |
| `rafeeq.reminder.sent` | `'YYYY-MM-DD'` (device-local date) | `core/prefs.js` |

## Files

* `public/js/features/reminders/reminders.js` — scheduler: `initReminders()` (60 s tick + visibility catch-up), eligibility, collection, delivery chain.
* `public/js/core/prefs.js` — (+) `REMINDER_CATS`, `get/setReminders`, `reminderDayKey`, `get/markReminderSent`.
* `public/js/components/notifications.js` — (+) `showDesktopNotification` accepts `onClick`.
* `public/js/features/settings/settings-sheet.js` — reminders section sync (visibility, time, category switches) + wiring.
* `public/shell.html` — `sheet-settings` reminders section (time input + 5 switches + hint).
* `public/sw.js` — `notificationclick` handler; precaches `reminders.js`; cache `rafeeq-static-v3`.
* `public/css/pages/dashboard.css` — (+) `.pref-time`.

## Verification

```powershell
powershell -File $env:TEMP\opencode\test_reminders.ps1   # static smoke
powershell -File $env:TEMP\opencode\test_a11y.ps1        # a11y/RTL still green
```
