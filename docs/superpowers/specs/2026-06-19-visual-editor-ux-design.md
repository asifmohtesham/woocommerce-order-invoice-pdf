# Visual editor UX improvements — slice 3 (design)

**Date:** 2026-06-19
**Status:** Approved for planning
**Scope:** Five UX improvements to the invoice GrapesJS visual editor. No server-render-engine, token-merge, or storage changes.

## Goal

Make the editor (slices 1–2, v1.4.11) cleaner and more capable to author with: remove page clutter, hide an irrelevant control, enable real table editing, and replace the three open-in-new-tab previews with one in-place dual-mode (live HTML + embedded real PDF) preview pane.

Builds on: `assets/visual-editor/app.js` (GrapesJS app), `includes/Visual/VisualEditorPage.php` (admin page + `woiVisual` localisation + order control bar), `templates/_visual/visual-document-wrapper.php`, REST `visual-preview-data` (slice 2, token map for an order), and the admin-ajax `woi_pdf_preview` (base64 PDF) + `woi_pdf_preview_order_search` actions.

## The five items + decisions (locked during brainstorming)

| # | Item | Decision |
|---|---|---|
| 1 | Admin notices clutter the editor page | Suppress third-party notices on this screen only (`remove_all_actions` for `admin_notices`/`all_admin_notices`/`user_admin_notices`) |
| 2 | No facility to edit table cells | Full editing: type text, drop tokens/blocks in, add/remove rows & columns, select/style cells — via a **vendored GrapesJS table plugin** (plugin-first, native config fallback) |
| 3 | "Device: Desktop" overflows the toolbar | **Hide the device switcher entirely** (`devices: []` + CSS) — a print template needs no responsive devices |
| 4 + 5 | Find/preview should be AJAX + in-place live PDF preview with variable substitution | A **toggleable right-side preview pane** with two tabs: **Live HTML** (auto-updates on edit + order select) and **PDF** (embeds the real mPDF render in-place). Auto-refresh on order select. Consolidates the three new-tab previews |

## Architecture & where the work lands

```
#1 Notices  → VisualEditorPage.php (suppress notices on this screen) [+ unit test]
#3 Devices  → app.js (grapesjs.init devices:[]) + editor CSS (hide devices panel)
#2 Tables   → vendored grapesjs table plugin asset + app.js (enable plugin, table block,
              editable/droppable cells, row/col controls)
#4+#5 Pane  → VisualEditorPage.php (preview-pane container markup) + app.js (toggle,
              Live HTML iframe with debounced token-merge, PDF tab embed) + editor CSS
              (pane layout). Reuses visual-preview-data + woi_pdf_preview. NO new endpoints.
```

No change to the render engine, `TemplateTokens`, `VisualTemplateStore`, or the REST save route.

## #1 — Suppress admin notices on the editor page

`VisualEditorPage` hooks `admin_head` (gated to its own screen via `get_current_screen()->id`) and, before notices print, calls:

```php
remove_all_actions( 'admin_notices' );
remove_all_actions( 'all_admin_notices' );
remove_all_actions( 'user_admin_notices' );
```

The screen id is the page hook returned by `add_submenu_page` (e.g. `woocommerce_page_woi-pdf-visual`). A small helper `is_visual_editor_screen(): bool` (compares `get_current_screen()->id`) gates it and is unit-testable with a stubbed `get_current_screen`. The plugin posts no notices on this page, so nothing of ours is lost.

## #2 — Editable tables

**Approach A (primary): vendor a maintained GrapesJS table plugin** compatible with the pinned GrapesJS **0.21.13**. The plan must pin the exact plugin + version and SRI-verify the vendored dist (same vendoring pattern as `grapesjs/grapes.min.js`). The plugin registers `table`/`row`/`cell` component types that are editable (double-click text), droppable (drag token/layout blocks into a cell), selectable+styleable per cell, and ship row/column add/remove controls. Enable it in `grapesjs.init({ plugins: [tablePlugin], pluginsOpts: {...} })`. The Layout "table" block is created through the plugin so its cells are immediately editable.

**Approach B (fallback, only if no plugin is cleanly compatible with 0.21.13):** native config — custom `table`/`tbody`/`tr`/`td` component types with `editable: true` + `droppable: true`, plus custom toolbar commands for insert/delete row & column. More code; same end-user capability.

Applies to **layout/custom tables only**. `{{line_items}}` / `{{totals}}` stay single dynamic tokens (server-rendered), not cell-editable. The slice-2 "2-column row" block is replaced by the plugin's editable table.

## #3 — Hide the device switcher

In `grapesjs.init`, set `deviceManager: { devices: [] }` (or omit devices). Add editor CSS `.gjs-pn-devices-c { display: none; }` to remove the panel and its overflow. Removes both the visual overflow (#3) and a control irrelevant to a fixed A4 print template.

## #4 + #5 — In-place preview pane

### Layout & toggle

A **"Preview"** toolbar toggle shows/hides a pane docked to the right of the canvas; when open, the canvas narrows (CSS fl/width split). The pane has two tabs:
- **Live HTML** (default)
- **PDF**

Default state: hidden. Markup container rendered by `VisualEditorPage::render_page()` (e.g. `#woi-preview-pane` with the two tab buttons, a `<iframe id="woi-preview-html">`, and a `<div id="woi-preview-pdf">` host + a "Render PDF" button). `app.js` wires behaviour.

### Live HTML tab

An `<iframe srcdoc>` showing the current design merged with the selected order's token values, wrapped in the visual-document-wrapper CSS so it approximates the output. Reuses `woiVisual` data + the slice-2 `GET visual-preview-data` endpoint for the order's token map (cached per `order_id`; default last order fetched on init).

- `currentOrderTokens` — cached token map for the selected order.
- `refreshLiveHtml()` — `iframe.srcdoc = wrapForPreview( mergeTokens( getHtml(), currentOrderTokens ) )`, where `mergeTokens` is the existing split/join + strip-leftovers, and `wrapForPreview` injects the wrapper `<style>` so tables/RTL look right.
- Triggers: `editor.on('update', debounce(refreshLiveHtml, 400))`; and immediately on order select. Browser-rendered → instant; **no mPDF Arabic shaping** (that's the PDF tab).
- If no order can be resolved / Woo unavailable, falls back to `woiVisual.sampleData`.

### PDF tab

A **"Render PDF"** action that:
1. `save()` the current design (the server render reads the stored template),
2. POSTs the existing `woi_pdf_preview` ajax (`action`, `security`=`previewNonce`, `document_type`, `order_id`=selected),
3. decodes the base64 PDF → Blob → sets a `<iframe src=blobUrl>` **in-place** inside the PDF tab (not a new tab). Revoke the blob URL on replace/unload.

Render is **on demand** (the button) and on order select **only when the PDF tab is active** (avoid a save+server round-trip on every keystroke). Approach: Blob `<iframe>` embed (browsers render PDF natively) rather than the settings page's pdf.js canvas — simpler, sufficient.

### Order → preview

Selecting an order via **Find** (or the default last order on init) fetches+caches its token map and **auto-refreshes** the Live HTML tab; if the PDF tab is active, it re-renders the PDF too.

### Consolidation

The three slice-1/2 new-tab buttons — "Preview sample data", "Preview real PDF", "Preview real order" — are **removed**; their roles move into the pane (Live HTML = selected order or sample fallback; PDF tab = real mPDF). The order search/Find bar stays (it now feeds the pane).

### Data flow

```
order select / init last order ─▶ GET visual-preview-data ─▶ cache currentOrderTokens
editor 'update' (debounced 400ms) ─┬▶ Live HTML: iframe.srcdoc = wrap(merge(getHtml(), tokens))
order select ─────────────────────┘
"Render PDF" (or order select w/ PDF tab active) ─▶ save() ─▶ POST woi_pdf_preview(order_id)
                                                   ─▶ base64 ─▶ Blob ─▶ iframe embed (PDF tab)
```

## Error handling & edge cases

- **No orders / Woo unavailable:** Live HTML falls back to `woiVisual.sampleData`; "Render PDF" alerts a clear error.
- **PDF render fails** (toggle off, save error, ajax error): alert in the PDF tab; do not leave a stale/blank embed without a message.
- **Live re-render cost:** debounced (400ms), browser-only, so cheap; never auto-renders the PDF on edit.
- **Table plugin incompatibility with 0.21.13:** the plan validates this first; falls back to native config (Approach B) if needed.
- **Notice suppression scoping:** gated strictly to the editor screen id; never global.
- **Stored design integrity:** "Render PDF" saves the current design (same as slice-1 Preview real PDF). Live verification must save/restore the user's stored design around any test, as in prior slices.

## Testing

- **PHP unit (PHPUnit + Brain Monkey; run `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`):**
  - Notice suppression: `is_visual_editor_screen()` returns true on the editor screen id and false otherwise (stub `get_current_screen`); the suppression callback calls `remove_all_actions` for the three hooks only when on-screen.
  - No new REST endpoints → no new endpoint tests.
- **JS:** `node --check` on `app.js`; the merge/debounce helpers are small pure functions covered by live verification.
- **Live verification (harness — see live-testing-harness memory; confirm deployed revision on the Status tab first):** notices gone on the editor page; device switcher hidden + no overflow; layout table editing (type, drop a token, add/remove a row, style a cell); Preview pane toggles; Live HTML updates on edit and on order select; PDF tab embeds the real mPDF PDF in-place (rasterize to confirm Arabic); existing save/render unaffected; stored design saved+restored around tests.

## Out of scope (later slices)

- Other document types (credit-note, etc.).
- Persisting the selected preview order or pane state across sessions.
- pdf.js-based PDF rendering (using a native Blob iframe embed instead).
- Undo/redo or template versioning.
