# Percentage-based Item Column Widths — Design

**Date:** 2026-06-13
**Status:** Approved
**Area:** Customiser → Item Columns

## Problem

Each Item Column in the Customiser already has a freeform **Style** text field, and
`woi_pdf_templates_sanitize_column_style()` already whitelists `width` with `%` units.
A user *can* type `width: 20%;` today, but it is buried in freeform CSS, error-prone,
and gated by the `style_target` (header / cells / both) setting — so a width can end up
applied to only the header or only the cells, misaligning the column.

Goal: make percentage column widths a **first-class, reliable feature** — a dedicated
**Width (%)** field per column — while leaving columns without a width to auto-distribute
the remaining space (standard table behavior). Applies to **all templates** (Simple
Premium, Modern, Business) and **all document types**.

## Approach (chosen: A — fold width into the existing style helper)

Add a dedicated Width (%) field to each column block and emit the width through the
existing `woi_pdf_templates_maybe_apply_column_styles()` helper that every template
already calls for both `<th>` and `<td>`. This requires **zero template-file edits** and
reaches custom templates automatically.

Rejected alternatives:
- **B — `<colgroup>` + `table-layout: fixed`:** most predictable, but requires editing
  table markup in 18 template files, misses custom templates, duplicates the VAT-split /
  `only_discounted` column-expansion logic, and changes wrapping behavior for everyone.
- **C — PHP-computed widths for all columns:** fully deterministic but more complex, and
  must recompute when columns are conditionally hidden.

## Design

### 1. Data model
Add a `width` option to **every** column block in
`EditorSettings::get_columns_field_options()`. Stored per column as
`['width' => '20']` inside the existing `fields_{document}_columns` option structure.
Bare number (the `%` is implied); empty = unset. No migration needed — an absent key
means "auto", which is the current behavior. `validate_options()` passes settings through
untouched, so the new key persists automatically.

### 2. Editor UI
Add a `number` case to `EditorSettings::display_table_field_options()` (currently
text / checkbox / select / textarea only). The Width field renders as a small numeric
input (`min=0 max=100 step=0.5`) labelled "Width (%)", placeholder "Auto". Because the
field system is generic and name-based, the new option automatically appears on existing
columns, on AJAX-added columns (`add_totals_columns_field` reuses the same options
array), and persists on save.

### 3. PDF rendering
Extend `woi_pdf_templates_maybe_apply_column_styles()` (woi-pdf-functions.php):
- If `width` is set and numeric, emit `width: X%` — applied to **both** the `<th>` and
  every `<td>` of that column, **independent of `style_target`** (which continues to
  govern only the freeform style). This keeps header and cells aligned.
- Merge with any sanitized freeform `style`. If the freeform style *also* contains a
  `width`, the **dedicated field wins** (its declaration is emitted and the freeform
  width is dropped/deduped).
- Columns with no width emit nothing → Dompdf auto-distributes the remaining space
  (the "auto-distribute remainder" behavior).
- **No template files change.**

### 4. VAT-split columns
In `woi_pdf_templates_get_table_body()`, the VAT-split branch builds a fresh
`$new_column` array that currently drops the parent column's keys. Add `width` to that
array so split **cells** stay aligned with their **header** (the header builder in
`woi_pdf_templates_get_table_headers()` already carries `$column_setting` through). When
one column expands into N split columns, each split column gets the same width value.

### 5. Validation & sanitization
- **Render-time:** reuse the existing `width` regex in
  `woi_pdf_templates_sanitize_column_style()`; the helper builds `width: {n}%`, clamps
  the value to 0–100, and ignores non-numeric input. Belt-and-suspenders so a bad stored
  value can never inject CSS.
- **Save-time:** `validate_options()` passes through, so no change needed there;
  sanitization lives at render.

### 6. Preview
The Customiser preview uses the same template render path (PDFMaker /
`woi_pdf_templates_maybe_apply_column_styles`), so the live preview reflects widths with
no extra work.

## Decisions baked in
- **Field type:** numeric `number` input (not a freeform text field).
- **Conflict rule:** when both the dedicated Width field and a `width` in the freeform
  Style box are set, the **dedicated field wins**.
- **Leftover width:** columns without an explicit width auto-distribute the remainder.

## Testing
TDD unit tests against `woi_pdf_templates_maybe_apply_column_styles()`:
- width set → emits `width:X%` on both `header` and `cells` targets
- width + `style_target=header` → width still applied to cells
- width + freeform `width` in style → dedicated field wins
- out-of-range / non-numeric width → ignored
- empty width → output unchanged from current behavior
- VAT-split body path carries `width` into split columns

## Out of scope
- `<colgroup>` / `table-layout: fixed` rendering.
- Auto-normalizing widths to sum exactly to 100% (remainder auto-distributes instead).
- Width controls for totals rows (this is Item Columns only).
