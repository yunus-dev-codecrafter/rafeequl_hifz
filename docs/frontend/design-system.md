# Frontend — CSS Design System

Status: implemented (Prompt 17). Mobile-first, responsive, RTL/LTR-safe, dark-mode-ready styles for the whole application. All values live as custom properties in `css/tokens.css` — never duplicate a hex color or pixel value in another file.

## Load order

`shell.html` links, top to bottom: `tokens.css` → `base.css` → `components/*` (buttons, navigation, forms, card, flip-cards, task-list, quran-cards, states, alerts, modal, progress-bar, toast, bottom-sheet) → `pages/*` (login, revision, dashboard). Page files may only override what is genuinely page-specific.

## Tokens (root stylesheet)

| Group | Tokens |
| --- | --- |
| Color | `--color-bg/surface/surface-2/text/text-muted/border`, `--color-primary/-strong/-soft`, `--color-on-primary`, `--color-success/-soft`, `--color-danger/-soft`, `--color-warning/-soft`, `--color-muted-soft`, `--color-focus`, `--color-backdrop` |
| Typography | `--font-sans`, `--font-size-xs…3xl`, `--font-weight-regular/semibold/bold`, `--line-tight/normal` |
| Spacing | `--space-1…7` (0.25rem → 3rem), `--touch-target` (2.75rem = 44px) |
| Radii / shadow | `--radius-sm/md/lg/full`, `--shadow-sm/md` |
| Motion | `--transition-fast` (150ms), `--transition-med` (240ms) |
| Loading | `--skeleton-base`, `--skeleton-shine` (derive from surface tokens → themes automatic) |
| Layering | `--z-header` 100, `--z-toast` 1000, `--z-sheet` 1100, `--z-modal` 1150 |
| Layout | `--layout-max-width` (42rem) |

**Theming**: `color-scheme: light dark` + `prefers-color-scheme: dark` defaults; manual `:root[data-theme='light'|'dark']` overrides from the dashboard control (auto = follow the system). New tokens that depend on the palette must be added to all three blocks; derived tokens (`var(...)` references) only to `:root`.

## Components

| File | Classes | Notes |
| --- | --- | --- |
| `components/buttons.css` | `.btn`, `--primary/--success/--danger/--ghost`, `--block/--wide/--sm` | Base height = `--touch-target`. |
| `components/navigation.css` | `.app-header`, `__inner/__brand/__nav/__link` | Sticky header. |
| `components/forms.css` | `.form`, `__field/__label/__input/__error/__hint/__check/__check-input/__fieldset` | Inputs `aria-invalid="true"` turn danger; checkbox/radio rows outline danger. |
| `components/card.css` | `.card` | Surface + border + radius + shadow. |
| `components/flip-cards.css` | `.flip-view*`, `.flip-section*`, `.flip-list`, `.flip-item*` (`__badge--active/--in-review/--mastered/--archived`), `.activity__actions` | Flip-cards screen layout + the dashboard's memorization action row (PR-01). |
| `components/task-list.css` | `.task-group*`, `.task-item*` | Status groups, count chips, item badges, action row. |
| `components/quran-cards.css` | `.dash-activities`, `.activity*` | Link cards in a 1→2 column grid; combine with `.card`. |
| `components/states.css` | `.state-card*` (`--icon`, `--icon--done/--error`, `--title`, `--text`), `.spinner(--lg)`, `.skeleton(--title/--line)` | Empty/error/success messages, retry cards, loaders. |
| `components/alerts.css` | `.alert`, `--success/--warning/--danger` (default = info), `.offline-banner` | Inline tinted messages + fixed offline strip (Prompt 19). |
| `components/modal.css` | `.modal__backdrop`, `.modal`, `__title/__text/__actions` | Centered dialog for confirmations; sheets stay the primary overlay. |
| `components/progress-bar.css` | `.progress-bar`, `__fill` | Fill width set through `--fill` custom property (JS sets a property, not a style attribute). |
| `components/toast.css` | `.toast-region`, `.toast(--error/--success/--leaving)` | Renderer behind `notify.*`. |
| `components/bottom-sheet.css` | `.bottom-sheet__backdrop`, `.bottom-sheet`, `__title/__actions` | Mobile sheet overlays. |

## Page files (legitimately page-specific)

`pages/login.css` (auth column), `pages/revision.css` (session/segment timeline, `.badge*`), `pages/dashboard.css` (hero, 4-up controls, stats grid, tasks section wrapper, settings rows, placeholder). Everything reusable was extracted out of `dashboard.css` in Prompt 17 — put new reusable pieces in `components/`, not in a page.

## Rules

1. Mobile-first: base styles for small screens, `min-width` media queries enhance (canonical step: `30rem`).
2. Logical properties only (`inset-inline`, `padding-inline`, `margin-block`, `text-align: start/end`) — RTL/LTR both work from `<html dir>`; never `left/right`.
3. BEM-ish naming: lowercase blocks/elements/modifiers with hyphens (`.task-item__badge`, `.state-card--error`).
4. No `!important`; no inline `style` attributes — dynamic values go through custom properties (e.g. `--fill`).
5. Contrast: text on soft backgrounds uses the strong token (`--color-danger` on `--color-danger-soft`, etc.); muted text only for secondary copy.
6. Touch: interactive controls ≥ `--touch-target` (compact `--sm` controls stay ≥ 36px and are pointer-only helpers).
7. Motion: respect `prefers-reduced-motion` (loaders slow down; keyframe transitions stay short).
