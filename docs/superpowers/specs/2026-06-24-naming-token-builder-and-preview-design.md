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

## Phase 1 — Filename-override persistence fix

### Root-cause method

Every code path round-trips `filename_template` correctly:
- `buildNamingPayload` (src/block-editor/namingModel.js) always includes
  `filename_template`.
- The POST transport (`src/block-editor/store.js`) is the same proven helper
  every other save uses.
- `Rest::merge_naming_settings` / `read_naming_settings` write and read it from
  the top level of `woi_pdf_documents_settings_{type}`.
- The `/document-naming` route is not wrapped in any cache.

The asymmetry (prefix/padding survive, the override does not) is explained by
prefix/padding having been set via the **classic settings tab**, whereas the
override exists only in the panel. The leading hypothesis is therefore a
**stale REST GET response** served by the site's object cache — the admin bar's
"Clear REST cache" button confirms such caching exists, so a reload's GET can
return a pre-save snapshot. A second candidate is a deployed-bundle/source
mismatch on the live site (deploy is a manual `git pull`).

The fix is **investigation-led**: reproduce live with the debug-Chrome harness,
confirm the actual cause, then apply the minimal correct fix (e.g. exclude the
`/document-naming` GET from REST caching, or add cache-busting/`no-store`
semantics, or correct the bundle deploy — whichever the repro proves).

### Acceptance criterion (unambiguous)

After entering a filename override in the panel, waiting for the debounced save
to complete, and reloading the page, the override field shows the saved value.
Verified live for **invoice** (a series type) **and packing-slip** (a no-series
type, which shows only the override field).

### Tests

If the root cause is in code, add a regression test at the appropriate layer
(PHPUnit for a REST/caching fix; Jest for a client fix). If the root cause is
environmental (object cache / deploy), document it in the report and the
version-coordination memory; the live acceptance check is the gate.

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
