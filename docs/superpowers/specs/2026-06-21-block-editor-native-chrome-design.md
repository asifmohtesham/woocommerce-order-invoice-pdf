# Block Editor — Native WordPress Chrome (`InterfaceSkeleton`)

**Date:** 2026-06-21
**Status:** Design approved (brainstorming); pending implementation plans (one per sub-slice).
**Surface:** WP Block (Gutenberg) authoring surface only — GrapesJS untouched.
**Relation:** follows block-styling Slice 0 (`08b1dfc`, v1.5.27, BlockInspector sidebar).
See `2026-06-21-block-styling-design.md` for the styling slices (separate track).

## Problem

The custom Block Invoice Template editor (`src/block-editor/index.js`) uses a
hand-rolled shell (`.woi-block-shell` + a custom toolbar row + full/stack/overlay
layout modes). It does not resemble the native WordPress block editor: no native
header bar (inserter/list-view/undo-redo/sidebar toggle), no Document|Block tabbed
sidebar, and the inspector lacks native panel chrome. The user wants it to look and
feel like the native WP editor (reference: the native Pages editor).

## Goals

- Adopt WordPress's `InterfaceSkeleton` (header / content / sidebar regions).
- Native header: inserter toggle, list view toggle, undo/redo, Save + status +
  Render PDF, OrderPicker, PDF-source select, sidebar (gear) toggle.
- Native **Document | Block** tabbed sidebar; Block tab hosts `BlockInspector`.
- Replace the bespoke full/stack/overlay layout modes with the native sidebar
  toggle + a simple full-screen body class.
- Appearance Inspector panel **expanded by default**.

## Non-goals (YAGNI)

- No `core/editor` (post-editor) store, no autosave, no `@wordpress/edit-post`.
- No options menu / preferences modal / keyboard-shortcut help.
- No change to GrapesJS, the render path, the active-source resolver, the
  `woi/preview` store, the order picker AJAX, or the PDF preview pipeline.

## Key technical constraints (verified)

- **`@wordpress/interface` is a BUNDLED package**, not externalized. The
  dependency-extraction plugin's `BUNDLED_PACKAGES` list
  (`node_modules/@wordpress/dependency-extraction-webpack-plugin/lib/util.js`)
  includes `@wordpress/interface`, `@wordpress/icons`, and
  `@wordpress/undo-manager`. So `InterfaceSkeleton` cannot come from a
  `wp.interface` global — we must **`npm install @wordpress/interface`** (and
  `@wordpress/icons` for header icons); they bundle into `assets/js/block-editor/`.
  Their own `@wordpress/*` imports (components, element, …) still externalize, so
  the bundle grows by the interface/icons code only, not the whole tree.
- The interface component's `.interface-interface-skeleton` styling must be
  enqueued — add `wp_enqueue_style( 'wp-interface' )` in `BlockEditorPage::enqueue`
  alongside the existing `wp-block-editor`/`wp-edit-blocks`/`wp-components` styles.
- The controlled `BlockEditorProvider` (local React `blocks` state via
  `onInput`/`onChange`) keeps **no undo history**. Undo/redo is implemented with a
  local history reducer, not a WP store — `onChange` (persistent) pushes history,
  `onInput` (transient, e.g. typing) replaces present without a history entry.
- `InterfaceSkeleton` must remain inside the existing `SlotFillProvider` +
  `BlockEditorProvider`; `Popover.Slot` is still required for block popovers.

## Architecture

`src/block-editor/index.js`'s `Editor` component is rewritten to render
`InterfaceSkeleton` with `header`, `content`, `sidebar`, and (for the inserter /
list view) `secondarySidebar` props. Sidebar open/close and secondary-sidebar
content (inserter vs list-view vs none) are local React state — we do NOT adopt the
`ComplementaryArea`/`PluginSidebar` registration system (overkill; a boolean +
the native gear Button is enough). Existing pieces reused unchanged: `Canvas`
(content), `OrderPicker`, `PreviewPanel`, `previewStore`, `saveBlocks`/
`setActiveSource`. Retired: the `.woi-block-shell` markup, `layout.js`'s
`LAYOUTS`/`LAYOUT_CSS`/layout-mode switcher, and the `woiBlockEditorLayout`
localStorage. The `.woi-block-sidebar`/`.woi-block-workspace` CSS from Slice 0 is
superseded by InterfaceSkeleton's own layout.

---

## Sub-slice 0.5a — InterfaceSkeleton layout + tabbed sidebar

**Deliverable:** the editor renders inside `InterfaceSkeleton` with a native
content/sidebar layout, a Document|Block tabbed sidebar, a working sidebar toggle,
and the Appearance panel expanded by default. (Header keeps the existing controls
for now — full native header affordances land in 0.5b.)

- **Install deps:** `npm install --save @wordpress/interface @wordpress/icons`.
- **Enqueue style:** add `wp_enqueue_style( 'wp-interface' )` in
  `includes/Visual/BlockEditorPage.php::enqueue`.
- **Rewrite `Editor` (`src/block-editor/index.js`):** render
  `<InterfaceSkeleton header content sidebar />` inside the existing
  `SlotFillProvider` + `BlockEditorProvider`; keep `<Popover.Slot/>`.
  - `content` = the `<Canvas previewCss=… />` (+ the appender `Inserter` kept
    inline for now).
  - `sidebar` = a `TabPanel` (`@wordpress/components`) with **Document** and
    **Block** tabs. Block → `<BlockInspector/>`. Document → the PDF source select +
    active-source toggle + a short order-context note (move the source `<select>`
    out of the old toolbar into here).
  - `header` = the existing toolbar contents (Save, status, OrderPicker, sidebar
    gear toggle `Button`). Drop the full/stack/overlay layout switcher.
  - Sidebar visibility = a local `isSidebarOpen` boolean; the gear `Button` toggles
    it; when false, pass `sidebar={undefined}`.
- **Retire layout modes:** delete `LAYOUTS`/`LAYOUT_CSS`/switcher usage and the
  `woiBlockEditorLayout` localStorage; keep a single full-screen affordance
  (body class) if trivial, else defer to 0.5b. Remove the Slice-0
  `.woi-block-workspace`/`.woi-block-sidebar` rules superseded here.
- **Appearance expanded:** `src/block-editor/appearance.js` `AppearancePanel`
  `initialOpen={ false }` → `initialOpen={ true }`.
- **PreviewPanel:** render below the InterfaceSkeleton (drop the `hidden`/overlay
  prop usage tied to layout modes).

**Acceptance (live):** editor shows a native skeleton (content + right sidebar);
the sidebar has Document|Block tabs; selecting a block shows its panels under Block
with Appearance already expanded; the gear toggles the sidebar; PDF source select
works from the Document tab; Render PDF + Save still work; no console errors;
canvas iframe still auto-sizes.

---

## Sub-slice 0.5b — Native header affordances + undo/redo

**Deliverable:** a native-looking header with inserter toggle, list-view toggle,
undo/redo, and full-screen, matching the native editor's top bar.

- **History reducer (pure, jest-tested):** new `src/block-editor/history.js` —
  `historyReducer(state, action)` over `{ past, present, future }`:
  - `RESET(blocks)` → `{past:[], present:blocks, future:[]}`
  - `CHANGE(blocks)` (persistent) → push `present` to `past`, set `present`, clear
    `future`
  - `INPUT(blocks)` (transient) → set `present` only (no history entry)
  - `UNDO` → move last `past` to `present`, push old present to `future`
  - `REDO` → inverse
  - selectors `canUndo(state)`/`canRedo(state)`.
  `Editor` uses `useReducer(historyReducer, …)`; `BlockEditorProvider` `value` =
  `present`, `onChange` → `CHANGE`, `onInput` → `INPUT`. Undo/redo buttons dispatch
  `UNDO`/`REDO` and are disabled per `canUndo`/`canRedo`.
- **Header tools (icons from `@wordpress/icons`):**
  - **Inserter toggle** — a header `Button`/`Inserter` toggle that opens the block
    library in `InterfaceSkeleton`'s `secondarySidebar` (or a popover). The inline
    appender from 0.5a can then be removed.
  - **List View toggle** — `ListView` (`@wordpress/block-editor`) in the
    `secondarySidebar`, toggled from the header.
  - **Undo / Redo** buttons (wired to the reducer above).
  - **Full screen** toggle (body class) if not already done in 0.5a.
- Only one `secondarySidebar` panel open at a time (inserter XOR list view) — local
  enum state.

**Acceptance (live):** header shows inserter/list-view/undo/redo/full-screen; the
inserter opens a block-library panel; list view shows the block tree; undo/redo
move through edit history and disable at the ends; everything renders with native
styling; no console errors.

---

## Cross-cutting

- **Coexistence:** GrapesJS, render path, active-source resolver, `woi/preview`
  store, order AJAX, and PDF preview are untouched. Saved block markup is unchanged
  (this slice is chrome only — no block `save()` changes, no kses/allowlist change).
- **Bundle:** `@wordpress/interface` + `@wordpress/icons` now bundle into
  `assets/js/block-editor/index.js` (size increase expected and acceptable).
  Webpack `output.clean:false` stays. `package.json`/`package-lock.json` gain the
  two deps — commit them.
- **Versioning:** each sub-slice = its own patch bump of BOTH the `Version:` header
  and `public string $version` in `woocommerce-orders-invoice-pdf.php`; built in a
  git worktree; FF push to master; read the true current version from
  `origin/master` immediately before bumping (concurrent instances; current is
  `1.5.27`).
- **Testing:** 0.5b's `historyReducer` is pure (zero `@wordpress/*` imports) →
  jest unit tests. 0.5a is layout/slot wiring → build + live acceptance (jest
  cannot render externalized WP components). Run the full `npm run test:unit` suite
  before each commit.
- **Live acceptance** per sub-slice via the debug-Chrome harness.

## Sub-slice order rationale

0.5a establishes the skeleton + sidebar (the structural change) and is independently
shippable/verifiable. 0.5b layers the header affordances + undo/redo on top, with
the only unit-testable logic (the history reducer) isolated there.
