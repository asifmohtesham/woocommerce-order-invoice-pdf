# Block Editor — Comprehensive Styling (alignment, spacing, sizing, colour)

**Date:** 2026-06-21
**Status:** Design approved (brainstorming); pending implementation plans (one per slice).
**Surface:** WP Block (Gutenberg) authoring surface only — GrapesJS untouched.

## Problem

The Block Invoice Template editor should let users style blocks: alignment, sizing,
colours, background colours, spacing. Today:

1. **The settings sidebar does not exist.** The editor shell (`src/block-editor/index.js`)
   renders `BlockEditorProvider → BlockTools → Inserter → Canvas → Popover.Slot` but
   **no `BlockInspector`** and no `InspectorControls.Slot` host. Every control built on
   `InspectorControls` — the shared Appearance panel, all per-cell `woi/column` controls
   (width/valign/align/bg/border/padding), all `woi/table` cell controls, and the
   `woi/heading` level selector — fills into a slot that is never rendered, so **none of
   it has ever been visible**. The block toolbar shows because `BlockTools` hosts it.
   This is the root cause of "the Block Editor is missing the Appearance panel".
2. **Styling controls are sidebar-only**, so even once visible they are undiscoverable —
   no toolbar affordance on block selection.
3. **Coverage gaps.** The shared Appearance system (`src/block-editor/appearance.js`)
   only attaches to text-bearing blocks (`woi/text`, `woi/heading`, all token blocks).
   The section row (`woi/columns`), `woi/spacer`, and `woi/divider` have no styling.
4. **No sizing.** No padding/margin, no block width, no spacer height / divider
   thickness, no logo/image dimensions anywhere.

## Goals

- Render a settings sidebar so existing + new `InspectorControls` are visible (Slice 0).
- Surface alignment in the block toolbar for discoverability (Slice 1).
- Add spacing (padding/margin) and width to the shared Appearance system (Slice 1).
- Extend styling coverage to the section row, spacer, divider (Slice 2).
- Add logo/image dimension controls (Slice 3).

## Non-goals (YAGNI)

- No per-side padding/margin (single value each, applied all sides). Revisit only if asked.
- No change to GrapesJS, the render path, or the active-source resolver.
- No WordPress native `supports.color/typography/spacing` — they emit palette **classes**
  and CSS custom properties that resolve only against the theme stylesheet mPDF never
  loads. Everything here stays **inline-style**, the established `appearance.js` decision.

## Architecture

`appearance.js` remains the single source of truth for presentational attributes,
inline-style output, and the shared Inspector panel. Invariants that make this safe:

- **Inline styles only** (never palette classes) — mPDF renders inline styles; it does
  not load the theme stylesheet.
- **Every attribute defaults empty/0.** `appearanceStyle()` adds a CSS property only when
  its attribute is set; `appearanceProps()` returns `{ style }` only when the style object
  is non-empty. A block with no styling therefore serialises byte-identical to before —
  **no block-validation deprecation needed**, the pattern proven across v1.5.10–1.5.26.
- **kses already permits everything.** `VisualTemplateStore::allowed_html()` gives every
  relevant tag (`div`, `p`, `h1–h6`, `span`, `td`, `hr`, `table`) the `style` attribute
  via `$common`, and `img` additionally allows `width`/`height`. `safecss_filter_attr`
  passes the CSS properties used here (text-align, font-weight, font-size, color,
  background-color, padding, margin, width, height, border, vertical-align). **No
  allowlist change is required** (unlike the table colgroup/col work in v1.5.20).

---

## Slice 0 — Render a settings sidebar (prerequisite)

**Why first:** it unlocks all already-shipped sidebar controls and is required for any
new styling control to be visible.

**Change (`src/block-editor/index.js`):** inside the existing `SlotFillProvider` +
`BlockEditorProvider`, render `<BlockInspector />` (from `@wordpress/block-editor`) in a
new sidebar region beside the canvas — e.g.:

```jsx
<div className="woi-block-workspace">
  <div className="woi-block-canvas"> … BlockTools / Inserter / Canvas … </div>
  <div className="woi-block-sidebar"><BlockInspector /></div>
</div>
<Popover.Slot />
```

`BlockInspector` renders the selected block's `InspectorControls` fills (and renders an
empty/"no block selected" state otherwise). It must sit inside the provider + slot-fill
context (it does) and relies on the `wp-block-editor` chrome stylesheet already enqueued
(v1.5.17). Color popovers anchor via the existing `Popover.Slot`.

**Layout/CSS:** add a `.woi-block-sidebar` region (fixed-ish width, ~280px, scrollable)
laid out next to `.woi-block-canvas`. Must coexist with the full/stack/overlay layout
modes (`src/block-editor/layout.js` `LAYOUT_CSS`) and the fixed full-screen shell — the
sidebar lives inside `.woi-block-main`, so it inherits the layout container. In `stack`
mode it sits beside or below the canvas (pick beside for parity with WP). Confirm the
canvas iframe still auto-sizes (the v1.5.23 iframe-height fix) when the canvas column
narrows.

**Acceptance:** select any block → settings sidebar shows its panels (column block shows
width/valign/align/border/bg/padding; heading shows level + Appearance; text/token shows
Appearance). No block selected → graceful empty state.

---

## Slice 1 — Toolbar alignment + spacing + width (shared system)

**`appearance.js`:**

- Extend `APPEARANCE_ATTRS` with `padding: { type:'number', default:0 }`,
  `margin: { type:'number', default:0 }`, `width: { type:'string', default:'' }`
  (width stored with its unit, e.g. `'60mm'` or `'50%'`).
- `appearanceStyle(a)` maps them: `if (a.padding) s.padding = a.padding+'px'`,
  `if (a.margin) s.margin = a.margin+'px'`, `if (a.width) s.width = a.width`.
- New exported `AppearanceToolbar({ attributes, setAttributes })` = `BlockControls` +
  core `AlignmentControl` bound to the existing `align` attr, so alignment is one click
  on the block toolbar. Toolbar and panel write the same `align` attr → always in sync.
- `AppearancePanel` gains a **Spacing** section (padding `RangeControl` 0–48, margin
  `RangeControl` 0–48) and a **Width** control (numeric + unit select `%`/`mm`, or a
  single text input; 0/empty = auto).

**Block wiring:** in `woi/text`, `woi/heading`, and the token factory, render
`<AppearanceToolbar … />` alongside the existing `<AppearancePanel … />`. One added line
each; `appearanceStyle`/`appearanceProps` already flow the new attrs into edit preview
and `save()`.

**Tests:** extend the existing jest coverage of `appearanceStyle` (pure, no `@wordpress/*`
import) — asserts new props appear only when set, and unset → `{}` (byte-identical save).

**Acceptance:** a Text block can be aligned from the toolbar; padding/margin/width render
in the live canvas and in the PDF; an untouched block's serialised markup is unchanged.

---

## Slice 2 — Structural blocks (section row, spacer, divider)

- **`woi/columns` (section row):** wire the shared Appearance system onto the row. `save()`
  currently emits `<table class="woi-row">`; bake styles via
  `useBlockProps.save({ className:'woi-row', ...appearanceProps(attributes) })` so
  background, border, padding, and align apply to the row table. Add `...APPEARANCE_ATTRS`
  to its attributes + `<AppearancePanel>`/`<AppearanceToolbar>` in `edit()`. Unset →
  identical `<table class="woi-row">` (back-compat). (Per-cell `woi/column` controls are
  unchanged — this adds *row-level* styling.)
- **`woi/spacer`:** add `height: { type:'number', default:0 }` (mm) with a sidebar
  `RangeControl`. `save()` emits `<div class="woi-spacer" style="height:Nmm">` when set,
  else the bare `<div class="woi-spacer">` (CSS default `12mm` preserved → existing
  spacers unchanged).
- **`woi/divider`:** add `thickness: { type:'number', default:0 }` (px) and
  `color: { type:'string', default:'' }`. `save()` emits
  `<hr style="border:0;border-top:Npx solid #color">` when either is set, else bare `<hr>`
  (kses already allows `style` on `hr`). Sidebar `RangeControl` + `ColorPalette`.

**Acceptance:** section row shows a background/border in the PDF; spacer height and divider
thickness/colour change the rendered PDF; all unset blocks serialise unchanged.

---

## Slice 3 — Logo / image sizing

- **`woi/logo`** (and any image-bearing token): add `imgWidth`/`imgHeight`
  (`{ type:'string', default:'' }`, mm). The `{{logo}}` token renders into a wrapper
  `<div>` whose contents are a server-injected `<img>`. `save()` sets the wrapper width/
  height inline when set and adds a marker class (e.g. `woi-img-sized`); a new rule in
  `templates/_visual/visual-document.css` — `.woi-img-sized img { width:100%; height:auto }`
  — makes the server `<img>` fill the sized wrapper. mPDF honours `width` on both wrapper
  and img. Sidebar controls (numeric width/height in mm). Unset → current behaviour
  (no class, no style) → byte-identical save.
- Note: today there is no logo-image constraint CSS (only `td.thumbnail img{width:13mm}`
  for line-items), so the new `.woi-img-sized img` rule is additive and scoped.

**Acceptance:** the logo renders at the chosen size in the PDF; unset logo unchanged.

---

## Cross-cutting

- **Coexistence:** GrapesJS, the render path, the active-source resolver, and all existing
  saved designs are untouched. All additive attributes are back-compatible.
- **Versioning:** each slice = its own patch bump of **both** the `Version:` header and
  `public string $version` in `woocommerce-orders-invoice-pdf.php` (cache-bust key), built
  in its own git worktree, fast-forward pushed to master. Read the true current version
  from `origin/master` immediately before bumping (concurrent instances).
- **Build:** `npm run build` (webpack `output.clean:false` — do not remove). Pure helpers
  import zero `@wordpress/*` so jest can resolve them.
- **Live acceptance** per slice via the debug-Chrome harness: drive wp-admin, select
  blocks, set styles, Render PDF, rasterize with PyMuPDF to verify the PDF reflects them.

## Slice order rationale

Slice 0 is the gating fix (controls invisible without it). Slice 1 builds the shared
foundation (toolbar + spacing/width) every later slice reuses. Slices 2–3 extend coverage,
with image sizing isolated last as the trickiest (server-injected `<img>`).
