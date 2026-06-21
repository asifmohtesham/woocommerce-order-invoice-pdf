# Block Invoice Template — Full Customiser Parity

**Date:** 2026-06-22
**Status:** Approved (design)
**Author:** brainstorming session

## Problem

The plugin has two authoring surfaces that share one data model:

- **Customiser** (classic, internal slug `editor`, class `WOI\PDF\Editor\EditorSettings`,
  stored in the single option `woi_pdf_editor_settings`) — the **data layer** for the
  line-item table and totals. It owns a rich registry of ~22 column types and ~11
  total-row types, each with type-specific options (price tax/discount mode, VAT split,
  product show-SKU/meta/GTIN, tax-rate name, weight unit, attribute/custom-field keys,
  etc.), plus per-block `width`, `label`, `style`, `style_target`. It also owns
  `sort_items`, `product_bundle_display`, custom blocks (`fields_invoice_custom`), and a
  global `custom_styles` CSS textarea.
- **Block Invoice Template** (newer, Gutenberg-blocks surface under
  `includes/Visual/BlockEditorPage.php` + `src/block-editor/`) — the **layout/chrome
  layer**. It positions tokens (`{{line_items}}`, `{{totals}}`, letterhead, parties…).

At render time **both** the real invoice download and the Customiser "preview sample"
call the same `OrderDocument::get_html()` → same mPDF maker, and the `{{line_items}}` /
`{{totals}}` tokens are filled by `woi_pdf_templates_get_table_headers/body()` /
totals helpers, which read the **Customiser** config. That is why a downloaded invoice
and the Customiser preview are pixel-identical (the only difference is the preview's
`SAMPLE` watermark, added via mPDF `SetWatermarkText` on the preview path only).

**The gap:** the Block Editor's sidebar `ColumnEditor` (`src/block-editor/ColumnEditor.js`)
only exposes 5 properties (type, Title, Width %, Align, a generic Field key). The rich
per-type options, the **Total rows** editor, **Custom blocks**, **Sort items**,
**Product bundle**, and **global Custom CSS** are editable **only** in the classic
Customiser. The rich options *survive* a Block-side save (the server preserves unknown
scalar keys) but cannot be **edited** from the Block Editor.

## Goal

Make the Block Invoice Template a second, equally-complete editor of
`woi_pdf_editor_settings`: every field and property configurable in the Customiser is
also configurable from the Block Editor sidebar. Because both surfaces write the same
option, edits stay in sync and the render path needs **no changes**.

## Approach: schema-driven

The PHP already defines the full option schema for every column/total type
(`EditorSettings::get_columns_field_options()` and `get_totals_field_options()`, both of
which auto-inject `width`/`label`/`style`/`style_target`). We expose that schema over
REST and render it with one generic React component that mirrors PHP's widget switch
(`display_table_field_options()`). This minimizes code, auto-stays in sync with the PHP
registry (and any `woi_pdf_templates_customizer_column_blocks` /
`woi_pdf_templates_customizer_total_blocks` filter additions), and needs no per-type
React code.

Rejected alternatives: hand-ported bespoke React controls (duplicates the schema in JS,
drifts, ignores filter-added types, far more code); embedding the classic jQuery-UI
editor inside the React block page (clashing paradigms, styling conflicts, poor state
integration).

## Architecture

- **Single source of truth:** `woi_pdf_editor_settings` (unchanged). No new option, post
  type, or meta.
- **Render path:** unchanged. `TemplateTokens::render_line_items()` /
  `render_totals()` already read this option through the shared
  `woi_pdf_templates_get_table_*` helpers.
- **New REST surface** (namespace `woi-pdf/v1`, cap `manage_woocommerce`):
  - `GET /editor-config` — returns full schemas + saved values.
  - `POST /editor-config` — schema-aware sanitize + save all sections.
  - `/visual-columns` (existing) stays as a thin back-compat alias delegating to the
    new save/read logic.
- **New client renderer:** generic `OptionField` + enhanced `ColumnEditor` + new
  `TotalsEditor`, `CustomBlocksEditor`, `SortBundlePanel`, `CustomCssPanel`, surfaced as
  collapsible `PanelBody` sections in the existing Document sidebar tab.

## Server changes

### `GET /editor-config` (new, `includes/Rest.php`)
Returns:
```jsonc
{
  "columns":      { "schema": { "<type>": { "title": "...", "options": { "<key>": {type,description,options,placeholder,min,max,step,rows,cols} } }, ... },
                    "values": [ { "type": "price", "price_type": "total", "tax": "excl", "width": "15", ... }, ... ] },
  "totals":       { "schema": { ... from get_totals_field_options() ... }, "values": [ ... ] },
  "custom":       { "positions": { "<hook>": "Label", ... }, "types": {text,custom_field,user_meta}, "values": [ ... ] },
  "sort":         { "options": { default, product, sku, category }, "value": "default" },
  "bundle":       { "options": { all, parent, bundled }, "value": "all" },   // present only if Product Bundles active
  "custom_styles": "...global css..."
}
```
Schema comes verbatim from `EditorSettings::get_columns_field_options()` /
`get_totals_field_options()` / `get_sorting_options()` / `get_product_bundle_options()`
and the custom-block position list. Add a public accessor on `EditorSettings` for the
custom-block position map if one is not already reachable.

### `POST /editor-config` (new)
- Accepts `{ columns, totals, custom, sort, bundle, custom_styles, order_id }`.
- **Schema-aware sanitization** (replaces today's hardcoded `$handled` whitelist in
  `handle_save_columns`): for each saved block, look up its type in the schema and
  sanitize each option by its declared widget:
  - `checkbox` → `1` when truthy else unset/`0` (match classic, which stores `1`).
  - `number` → numeric, clamped to `min`/`max` when present.
  - `select` → must be one of the declared option keys, else drop.
  - `text` → `sanitize_text_field`; the `style` key → `woi_pdf_templates_sanitize_column_style()` (existing CSS whitelist).
  - `textarea` → `sanitize_textarea_field`; `custom_styles` global → same CSS-safe path the classic editor uses (it echoes verbatim today, so preserve current behavior / `wp_kses` parity — do not tighten beyond classic).
  - Unknown scalar keys still preserved (defensive), as today.
- Writes `fields_invoice_columns`, `fields_invoice_totals`, `fields_invoice_custom`,
  `sort_items[invoice]`, `product_bundle_display[invoice]`, `custom_styles` into the
  option, sets `settings_saved = '1'`, then `update_option`. The existing
  `update_option_woi_pdf_editor_settings` → `add_or_update_editor_totals_columns()` hook
  renumbers ordinal keys (1..N) — reuse it; do not re-implement renumbering.
- Returns `{ saved: true, columns, totals, custom_styles, ... }` plus freshly-rendered
  `{{line_items}}` and `{{totals}}` tokens for `order_id` so the canvas live-updates in
  one round-trip (extend the existing partial-token render to include totals).

### Align convenience
The classic config has no dedicated align field — alignment lives in `style` as
`text-align`. The Block editor keeps an **Align** select as a convenience, but instead of
overwriting `style` (today's behavior), it **parses `style` and replaces only the
`text-align` declaration**, preserving any other declarations the user set via the new
freeform Style control. Implemented client-side; the server just stores the resulting
`style` + `style_target`.

## Client changes (`src/block-editor/`)

### `OptionField.js` (new)
`<OptionField field={schemaEntry} value onChange />` mirrors
`display_table_field_options()`:
- `checkbox` → `CheckboxControl` (label = `description`)
- `select` → `SelectControl` (options from `field.options`)
- `text` → `TextControl` (placeholder, optional help for `style`)
- `number` → `TextControl type="number"` (min/max/step)
- `textarea` → `TextareaControl` (rows/cols)
- `documentation` → rendered help text only

### `ColumnEditor.js` (enhanced)
Per column, in order: **Title**, **Width %**, the **schema-driven per-type options**
(rendered via `OptionField` from the column type's `options`, excluding the common
`label`/`width` already shown), then **Style** (freeform) + **Style target**, plus the
**Align** convenience. Add/reorder/remove retained. The full column object is always
persisted so nothing is stripped. Columns whose schema has no `width`/`style` (e.g.
`custom_function`) render no such controls.

### New sidebar panels (Document tab, collapsible `PanelBody`)
- **`TotalsEditor.js`** — add/reorder/remove total rows; per-row schema-driven options.
- **`CustomBlocksEditor.js`** — per block: type (text / custom field / user meta),
  position (hook select), label, meta key / text. Advanced "requirements"
  (order-status conditions, etc.) follow the same schema approach; lowest priority and
  may be deferred to a follow-up if time-boxed.
- **`SortBundlePanel.js`** — "Sort items by" select; "Product bundle display" select
  (only when Product Bundles is active / `bundle` present in payload).
- **`CustomCssPanel.js`** — global `custom_styles` textarea.

All panels debounce-save to `/editor-config` and live-update the canvas from returned
tokens, matching the current `ColumnEditor` persist pattern.

### `store.js`
Add `getEditorConfig()` / `saveEditorConfig(payload, orderId)` wrappers; keep existing
`getColumns`/`saveColumns` delegating to the new endpoints for back-compat.

## Edge cases / constraints

- `custom_function` column and `custom_function`/`fees` totals legitimately lack some
  common options — the renderer honors per-type option absence (no empty controls).
- `vat` `split` expands into multiple rendered columns at render time
  (`woi_pdf_templates_get_table_headers` handles this) — the editor only stores the
  single `vat` column with `split=1`; no editor-side expansion needed.
- mPDF can't `display:none` a column, so the visual path drops the thumbnail column at
  source via the `thumbs` doc-option — unaffected by this change.
- Saving must not disturb other document types' keys in the option (only `invoice` keys
  are touched).
- Reuse the existing position-renumber hook; do not duplicate it.

## Testing

- **PHPUnit** (`tests/`): `/editor-config` round-trip — a `price` (total/excl/before),
  a `vat` (split), a full totals set, a custom block, `sort`+`bundle`, and
  `custom_styles` saved via REST persist into `woi_pdf_editor_settings` and read back
  identically through classic `EditorSettings::get_settings()`. Sanitization tests:
  invalid `select` value dropped, out-of-range `number` clamped, dangerous CSS
  (`expression()`, `url()`) stripped by the existing whitelist, checkbox normalized to
  `1`. Run with the ABSPATH bootstrap (`-d auto_prepend_file=tests/bootstrap.php`).
- **Render check**: local mPDF harness (`tools/render-visual-sample.php` +
  `tools/rasterize.py`) — configure an extra column (e.g. **Weight**, append unit) via
  the saved option and confirm it appears in the rasterized PDF.
- **Build/version**: `npm run build` (webpack) compiles cleanly; bump `WOI_PDF_VERSION`
  (currently 1.5.55) and the plugin header for cache-bust.
- **Live acceptance** (manual, via the debug-Chrome harness): open Block Editor, set a
  Price column to total/excl, add a Totals row and a Custom block, confirm the canvas
  and a downloaded PDF reflect them and that the classic Customiser shows the same.

## Implementation slices

1. **Server** — `GET`/`POST /editor-config`, schema-aware sanitization, `/visual-columns`
   alias, partial totals token, PHPUnit.
2. **Generic renderer** — `OptionField` + enhanced `ColumnEditor` (per-type options +
   Style/Style-target + Align convenience), `store.js` wrappers.
3. **Totals** — `TotalsEditor` panel.
4. **Remaining parity** — `CustomBlocksEditor`, `SortBundlePanel`, `CustomCssPanel`.
5. **Polish** — render check, version bump, live acceptance.

## Out of scope

- Changing the rendered PDF layout/CSS (render path is untouched).
- Porting the Customiser to document types other than `invoice` (Block template is
  invoice-only today).
- Retiring the classic Customiser tab (both surfaces coexist).
