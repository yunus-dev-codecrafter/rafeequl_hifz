# Frontend — Accessibility & RTL

Status: implemented (Prompt 20). Keyboard support, screen-reader semantics, contrast, touch targets, reduced motion, and the Arabic/English mixed-text policy for the Arabic-first UI.

## Keyboard & focus

* **Skip link** — first focusable element (`#skip-link`, `href="#app"`); the click handler calls `preventDefault()` and focuses `#app` directly, because the fallback route would otherwise redirect the hash.
* **Route focus** — after every hash change (not the initial boot) the router focuses the `<main id="app" tabindex="-1">` container, so keyboard/SR users land in the new screen instead of a stale position. The active header nav link carries `aria-current="page"`.
* **Focus ring** — global `:focus-visible` outline in `--color-focus` (≥ 3:1 on cards and background); `.app-main:focus` suppresses the ring so route focus does not flash an outline.
* **Overlays** — `components/dialog-focus.js` maintains a focus-trap stack: Tab/Shift+Tab wrap inside the top-most overlay only, Escape closes it, and focus returns to the opener on release. Wired into the bottom sheets (`sheet-task-create`, `sheet-settings`, `sheet-interruption`) and the delete-account modal.
* **Toast region** — never steals focus; the container is `role="status" aria-live="polite"`.

## Screen-reader semantics

| Surface | Pattern |
| --- | --- |
| Toasts | Region `role="status"`/polite; error toasts promote themselves to `role="alert"` (assertive). |
| Progress bars | `role="progressbar"` with `aria-valuemin/max/now` + `aria-valuetext` (`"42%"`). |
| Offline banner | `role="status"`, toggled from `pwa.js`. |
| Settings switches | `role="switch"` + `aria-checked`, updated by every toggle path. |
| Language choices | `role="radiogroup"`/`role="radio"` with APG roving tabindex (one Tab stop), Arrow/Home/End navigation — direction-aware (in RTL, ArrowLeft moves forward). English option is `disabled` (coming later). |
| Task list writes | The list sets `aria-busy` while a status write is in flight; afterwards `restoreActionFocus()` puts focus back on the same action (or a neighbouring action / the task item, `tabindex="-1"`) so focus never drops to `<body>`. |
| Dialogs | `role="dialog"`/`alertdialog` + `aria-modal="true"` + labelled headings (already in the templates). |
| Forms | Visible `<label for>` on every control; server 422 errors set `aria-invalid="true"` on the offending input. |

## Forms & errors

`components/form-errors.js` is the single path for 422 responses:

* Each field error gets a generated `id` and a `role="alert"` message node.
* `aria-describedby` is wired to **both** the error and the authored hint (`pref-revision-hint` etc.) — hints are never dropped.
* `focusFirstInvalid()` moves focus to the first invalid field (used when the submission happens from a sheet or a hidden context the focus helper cannot reach — the session screen closes its sheet first, then focuses).
* Page-level failures (offline, 500, timeouts) surface as `notify.error()` toasts — `role="alert"`, so SR users hear them.

## Contrast

* Audit tool: `php tools/check-contrast.php` — parses `public/css/tokens.css` (light + dark), computes WCAG ratios for **36 pairs** (text pairs min **4.5:1**, focus ring min **3:1**), exits non-zero on any failure. Current result: **36/36 pass, light and dark**.
* One fix surfaced by the first run: dark `--color-primary-soft` was `#173a36` (active toggle label 4.02:1) → darkened to `#122e2b` (4.70:1); inline alerts improved to 7.39:1 as a side effect.

## Touch targets

* `--touch-target: 2.75rem` (44 px) is the minimum for interactive controls: `.btn--sm`, `.pref-option`, `.pref-cycle`, header brand/link (44 px tall), and the settings switch (real `::before` hit-area overlaying the 32 px track, `inset: -0.5rem`).
* Text inputs/areas and primary buttons were already ≥ 44 px from the forms system.

## Reduced motion

`@media (prefers-reduced-motion: reduce)` in `base.css` collapses `--transition-fast`/`--transition-med` to `0.01ms`, so every token-driven transition and the token-driven spinner become instant. `states.css` additionally slows the infinite spinner/skeleton shimmer to 2.4 s (status affordances keep moving, just gently) — that block wins over the token zeroing for those two elements.

## Language & numbers (Arabic-first, English-ready)

* `<html lang="ar" dir="rtl">` — the document is RTL; **all layout uses logical properties** (`inline-size`, `margin-block-start`, …) — there are zero physical direction properties in the stylesheets (enforced by `test_a11y.ps1`).
* **Western digits everywhere**: the app never renders Arabic-Indic digits. API numbers are Western as-is; the one `Intl` call (`dashboard-page.js` `formatToday`) uses `ar-u-nu-latn`.
* **English fragments** get `lang="en" dir="ltr"` islands: e.g. the hero wordmark (`Rafeequl Hifz`), task type names, and all email/password inputs (Latin text must not be reversed in RTL).
* **Mixed Arabic + numbers/dates** are isolated in `<bdi>` so neighbouring runs cannot reorder: `setSlotBdi()` (plan meta, segment label, `X من Y`, page progress line, task meta duration) and explicit `<bdi dir="ltr">` for ISO dates in the segment list.
* **No i18n system** — the UI copy stays Arabic for now; `html lang` flips only when the English locale actually ships (English option is present but disabled in the language radiogroup).
* Page ranges and Quran references stay numeric (`3:5`) — direction-neutral; ranges are ordered low→high and read identically in both directions.

## Verification

```powershell
php tools\check-contrast.php                      # 36 pairs, exit 0
powershell -File $env:TEMP\opencode\test_a11y.ps1 # static a11y/RTL smoke
powershell -File $env:TEMP\opencode\test_pwa.ps1  # includes precache cross-check
```
