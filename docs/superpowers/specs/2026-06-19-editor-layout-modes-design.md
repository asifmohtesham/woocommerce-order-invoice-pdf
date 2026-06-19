# Visual Editor Layout Modes — Design

**Date:** 2026-06-19
**Status:** Approved (pending spec review)
**Component:** `assets/visual-editor/` (app.js, editor.css) + `includes/Visual/VisualEditorPage.php`

## Problem

The visual invoice editor renders inside WP admin chrome (`.wrap`, with the left
admin menu and top bar present). `.woi-editor-row` is a horizontal flexbox that
splits the row side-by-side: the GrapesJS editor (~58%) and a fixed-width preview
pane (42%). GrapesJS already has its own internal side panels (Blocks/Layers and
Settings), so surrendering 42% to a separate preview column crushes the design
canvas. The side-by-side layout degraded the editing UX.

## Goal

Give the user three selectable layout modes, defaulting to a full-viewport
editor, so the canvas has room to breathe and the preview is positioned to suit
the task.

## Modes

A single layout-mode control switches between three layouts. The choice persists
across sessions (per browser) and is restored on load. The default is `full`.

### 1. Full screen (`full`) — default

- The editor shell becomes `position: fixed; inset: 0` with a high `z-index`,
  covering (not removing) the WP admin menu and top bar. `body` gets
  `overflow: hidden` while active so the page behind does not scroll.
- The new toolbar (see Markup) renders here as a slim fixed top bar: ◀ back link
  (to PDF Invoices), the title, and the layout switcher. Preview open/close stays
  on the existing GrapesJS panel button (`woi-preview-toggle`); it is not
  duplicated in the toolbar.
- The GrapesJS canvas fills the window.
- The preview is a **right-docked panel, open by default** (~40% width). Because
  the full window is now available, this does not crush the canvas. The user can
  collapse it for maximum canvas.
- Exiting is implicit: switching to another mode removes the fixed positioning
  and restores `body` scroll.

### 2. Split below (`stack`)

- Stays inside WP admin chrome.
- `.woi-editor-row` flips to `flex-direction: column`: editor on top at full
  width, preview docked **below** as a collapsible strip.

### 3. Overlay (`overlay`)

- Stays inside WP admin chrome.
- The editor takes **full width**. The preview pane becomes a slide-in panel
  **floating over the right edge** (`position: fixed`), shown only when the user
  clicks the preview toggle, so it never consumes layout width.

## Architecture

### Markup (VisualEditorPage.php)

- Wrap the order-bar + `.woi-editor-row` in a shell:
  `<div id="woi-editor-shell" data-layout="full"> … </div>`.
- Add a **single always-present toolbar** `#woi-editor-toolbar` as the first
  child of the shell, holding: ◀ back link, title, and the layout switcher
  (segmented buttons with `data-woi-layout="full|stack|overlay"`). This toolbar
  is **repositioned/restyled by CSS per `data-layout`** — in `full` it renders as
  the slim fixed top bar; in `stack`/`overlay` it renders as an inline control
  strip above the editor row.
- Preview open/close and Live/PDF tab switching are **left as-is**: the existing
  GrapesJS panel button `woi-preview-toggle` and the `.woi-preview-tab` buttons
  are reused unchanged. The toolbar does not duplicate them.
- The preview pane (`#woi-preview-pane`) markup is unchanged.

### Styling (editor.css)

- All layout differences are expressed as CSS rules keyed off
  `#woi-editor-shell[data-layout="…"]`.
- `full`: fixed shell, own bar visible, preview docked right and open.
- `stack`: `.woi-editor-row { flex-direction: column }`, preview below.
- `overlay`: editor full width, preview pane `position: fixed` over right edge,
  toggled via a `.is-open` class.
- Preserve the existing rule that the inner `.preview` scrolls (not the panel),
  so the document-switcher dropdown is not clipped.

### Behavior (app.js)

- A single `woiApplyLayout(mode)` function is the source of truth: it validates
  `mode` (fallback `full`), sets `#woi-editor-shell[data-layout]`, toggles the
  `body.woi-fullscreen` class (only for `full`), writes `localStorage`, and calls
  `editor.refresh()`.
- On init: `woiApplyLayout(localStorage.getItem('woiEditorLayout') || 'full')`.
  Then, so the default state matches the design, open the docked pane for
  `full`/`stack` via the existing `woiSetPaneOpen(true)` (it starts `hidden`);
  leave it closed for `overlay` until the user toggles it.
- Switcher click: `woiApplyLayout(button.dataset.woiLayout)`. When switching into
  `overlay`, the pane is hidden until toggled; when switching into `full`/`stack`,
  ensure the pane is docked-open.
- Preview open/close reuses the existing GrapesJS `woi-preview-toggle` button
  unchanged. In `overlay` the pane is positioned by CSS as a right-edge overlay
  (driven by the same `hidden` attribute the toggle already sets); in
  `full`/`stack` the same attribute shows it docked. No new toggle logic is
  needed — only CSS keyed off `data-layout` changes where the visible pane sits.

## State & persistence

- Single key: `localStorage['woiEditorLayout']` ∈ `{full, stack, overlay}`.
- Invalid/missing → `full`.

## Reuse, not rebuild

- The same `#woi-preview-pane` (Live HTML + PDF tabs, Render PDF) is repositioned
  by CSS per mode. JS only switches the attribute, persists it, toggles preview
  visibility, and refreshes GrapesJS.

## Out of scope (YAGNI)

- No new preview features.
- No resizable drag-splitter between editor and preview.
- No per-mode remembered sizes.
- No server-side persistence of the layout choice (browser localStorage only).

## Risks / notes

- GrapesJS must recompute canvas size on mode change → call `editor.refresh()`
  after every switch.
- `WOI_PDF_VERSION` must be bumped (JS + CSS change) or browsers serve stale
  assets.
- Full-screen mode covers WP chrome via fixed positioning + `body
  overflow:hidden`; it must restore cleanly when switching away or leaving.
- The preview iframes (Live HTML, PDF) are unaffected functionally; only their
  container position changes.

## Testing

- Manual/live verification (the editor is browser-only GrapesJS; automated RTE/
  layout gestures are unreliable under synthetic events at DPR 1.25):
  - Default load shows full-viewport with preview docked-open.
  - Switch to each mode; layout matches the design; canvas resizes (no clipped/
    zero-size canvas).
  - Reload preserves the last mode.
  - In full mode, page behind does not scroll; switching away restores scroll.
  - Preview Live/PDF tabs and Render PDF still work in every mode.
  - Document-switcher dropdown in the preview is not clipped.
