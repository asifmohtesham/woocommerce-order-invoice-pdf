# Design: Naming/filename token builder + live preview (+ persistence fix)

**Date:** 2026-06-24
**Surface:** Block Invoice Template editor — "Numbering & filename" panel
(`src/block-editor/NamingPanel.js`), with a supporting read-only REST endpoint.
**Builds on:** v1.5.86, which surfaced the numbering series + filename override
into the Block editor (`docs/superpowers/specs/2026-06-24-document-naming-series-block-editor-design.md`).

## Problem

Three issues surfaced from live use of the v1.5.86 panel:

1. **Bug — the PDF Filename Override does not survive a page reload.** A value
   typed into the override field is gone after refresh, while Number Prefix /
   Padding (which were set via the classic settings tab) persist.
2. **UX — tokens must be typed by hand.** Users must remember and type
   `{document_number}`, `[invoice_year]`, etc. into plain text fields.
3. **UX — no feedback.** There is no way to see what a given prefix/filename
   actually produces without saving and downloading a PDF.

## Sequencing

Two independent landings:

- **Phase 1 (urgent, small):** the persistence fix, landed on its own.
- **Phase 2:** the token-chip builder + live preview, landed as a feature.

Each phase is committed and pushed separately. Phase 1 does not depend on
Phase 2 and ships first.

---

## Phase 1 — Apply the filename override to the Block-editor download

### Root cause (confirmed by live reproduction)

The override **does** persist and round-trip correctly — that was a misframing.
The real defect: the Block editor's **Download PDF** ignores the configured
filename entirely. Live evidence: with the override set to
`{document_type}_{document_number}_{date}`, the downloaded file is still
`invoice-237.pdf`.

The chain:
- `src/block-editor/index.js` `onDownloadPdf` hardcodes
  `filename: 'invoice' + (orderId ? '-' + orderId : '') + '.pdf'`.
- The server handler `Settings::ajax_preview` (the `woi_pdf_preview` AJAX
  action) builds the real `$document`, renders the PDF, and returns
  `{ preview_data, output_format }` — but **no filename**.
- So the client has nothing authoritative to name the file with and falls back
  to its hardcoded string.

The classic admin download path is unaffected — it calls
`$document->get_filename()` directly, which already resolves
override → global → default + tokens (landed v1.5.86). Only the Block-editor
download bypassed it.

### Fix

1. **Server (`includes/Settings.php`, `ajax_preview`):** before the success
   response, resolve the filename from the already-built document and include
   it:
   `$filename = $document->get_filename( 'download', array( 'output' => $output_format ) );`
   added to the `wp_send_json_success` payload as `'filename' => $filename`.
   This reuses the authoritative resolver — the download name cannot drift from
   what `get_filename()` produces elsewhere.
2. **Client (`src/block-editor/pdfPreview.js`):** `fetchPdfBytes` returns
   `{ bytes, filename }` (filename from `res.data.filename`); `downloadPdf` uses
   the server filename, falling back to a default only if absent.
3. **Client (`src/block-editor/index.js`):** `onDownloadPdf` stops constructing
   a hardcoded filename and lets the server value drive the download name.

### Acceptance criterion (unambiguous)

With a per-type override set (e.g. `{document_type}_{document_number}_{date}`),
clicking **Download PDF** in the Block editor produces a file whose name is the
resolved override (e.g. `invoice_2026-04-000004_2026-04-22.pdf`), not
`invoice-237.pdf`. With the override blank, the name follows the global
template. Verified live.

### Tests

- **Jest** (`src/block-editor/pdfPreview.test.js`): with `fetch` mocked to
  return `{ success: true, data: { preview_data, output_format: 'pdf',
  filename: 'custom.pdf' } }`, `downloadPdf` names the anchor `custom.pdf`; with
  `filename` absent it falls back to the default. (The server one-liner reuses
  `get_filename()`, already covered by `FilenameBuilderTest`; verified live.)

---

## Phase 2 — Token-chip builder + live preview

### Component A — `TokenField` (chips + draggable)

New `src/block-editor/TokenField.js`: a reusable component wrapping
`@wordpress/components` `TextControl`. Below the input it renders a row of
**token chips**. Each chip is:

- **Click-to-insert** — inserts its token at the input's current caret
  (`selectionStart`/`selectionEnd`), then restores focus and caret after the
  inserted token.
- **Draggable** — `draggable` chip; `dragstart` sets `dataTransfer` text to the
  token; the input's `onDrop` inserts the token at the caret position
  (best-effort drop position, falling back to caret/end).

The field remains free-text: chips *insert* tokens, they do not convert the
field into a block/segmented control. Manual typing and editing still work.
`TokenField` takes: `label`, `value`, `onChange`, `tokens` (array of
`{token, label}`), and `help`. It owns no persistence — it is a controlled
input like the `TextControl` it replaces.

### Component B — Token definitions (two distinct sets)

Extend `src/block-editor/namingModel.js`. The prefix/suffix and filename fields
use **different** token syntaxes resolved by **different** engines, so they get
**different** chip sets:

- **Prefix/suffix tokens** (resolved by `woi_pdf_format_document_number`, square
  brackets): `[order_year]`, `[order_month]`, `[order_day]`, `[order_number]`,
  and the document-slug variants `[{slug}_year]`, `[{slug}_month]`,
  `[{slug}_day]` where `slug` is the selected type with hyphens as underscores
  (`invoice`, `credit_note`, `proforma`, `receipt`). Exposed via a
  `prefixTokens(type)` helper that computes the slug-based entries per type.
- **Filename tokens** (resolved by `woi_pdf_build_filename`, curly braces): the
  existing `FILENAME_TOKENS` — `{document_type}`, `{order_number}`,
  `{document_number}`, `{document_number_sequence}`, `{date}`.

The `[{slug}_date="<php-format>"]` placeholder is intentionally **not** offered
as a chip (it needs a free-form date format argument); it remains usable by
typing. Documented, not surfaced.

### Component C — Live preview (server-resolved)

New **read-only** REST route `POST woi-pdf/v1/naming-preview` registered in the
always-on `register_visual_template_route()` (same place as `/document-naming`,
so it is never gated off). Permission: `current_user_can('manage_woocommerce')`.

Request body: `{ type, order_id, prefix, suffix, padding, next_number,
filename_template }`. Handler:

1. Resolves the order (`wc_get_order`); if missing/zero, falls back to a recent
   order via the existing order-picker helper. If none, returns a flag so the
   panel can show "Select an order to preview."
2. Builds the document instance for `type` + order.
3. **Number preview:** `woi_pdf_format_document_number((int) next_number,
   prefix, suffix, (int) padding, $document, $order)` — uses the *unsaved*
   prefix/suffix/padding so the preview reflects what the user is editing.
4. **Filename preview:** assembles the token args for the order
   (`document_type`, `order_number`, `document_number` = the formatted number
   from step 3, `document_number_sequence` = `next_number`, `date`) and calls
   `woi_pdf_build_filename($filename_template_or_global, $args, ...)`, returning
   the resolved filename (with extension).

Returns `{ number_preview, filename_preview, order_id, has_order }`. Resolving
on the server reuses the exact production formatters — the preview cannot drift
from the real PDF (no JS reimplementation).

### Component D — `NamingPanel` wiring

- Replace the prefix / suffix / filename `TextControl`s with `TokenField`, fed
  `prefixTokens(type)` and `FILENAME_TOKENS` respectively.
- Read `orderId` from the preview `STORE` (`select(STORE).getOrderId()`).
- On any field change, debounce ~250 ms and call a new
  `getNamingPreview(payload)` in `store.js`; render two preview lines (Number,
  Filename) below the fields. While in flight, keep the last good preview. If
  `has_order` is false, render the "select an order" hint instead.
- The existing debounced **save** (500 ms) is unchanged and independent of the
  preview call.

## Data flow

```
user edits field
  -> TokenField onChange -> NamingPanel onField
       -> setValues (optimistic)
       -> persist()        (500ms debounce) -> POST /document-naming   [save]
       -> previewDebounced (250ms debounce) -> POST /naming-preview    [read]
            -> {number_preview, filename_preview} -> preview lines
```

## Testing

- **Jest** (`src/block-editor/*.test.js`):
  - `TokenField`: clicking a chip inserts its token at the caret; dropping a
    chip inserts at the caret; value/onChange contract holds.
  - `namingModel.prefixTokens(type)`: returns the correct slug-based names for
    each series type (`invoice` -> `[invoice_year]`, `credit-note` ->
    `[credit_note_year]`, etc.).
- **PHPUnit** (`tests/Unit/`):
  - `naming-preview` resolves number + filename for a sample order using the
    *incoming* (unsaved) prefix/suffix/padding.
  - Unknown type -> 400; missing order -> recent-order fallback / `has_order`
    false.

## Scope guard (YAGNI)

- No reordering of tokens already inserted in a field.
- No conversion of the text fields into block/segmented inputs.
- No new tokens beyond what the two engines already resolve.
- The preview endpoint is strictly read-only (no option writes, no number-store
  increment).

## Files

**Phase 1:** root-cause-dependent — likely `includes/Rest.php` (route cache
exclusion) or `src/block-editor/store.js`, plus a regression test.

**Phase 2:**
- `src/block-editor/TokenField.js` (new) + `TokenField.test.js` (new)
- `src/block-editor/namingModel.js` (add `prefixTokens`) + test additions
- `src/block-editor/store.js` (add `getNamingPreview`)
- `src/block-editor/NamingPanel.js` (wire TokenField + preview)
- `includes/Rest.php` (register + handle `/naming-preview`)
- `tests/Unit/DocumentNamingPreviewRestTest.php` (new)
- Rebuilt bundle `assets/js/block-editor/index.js` + version bump (Phase 2 landing)
