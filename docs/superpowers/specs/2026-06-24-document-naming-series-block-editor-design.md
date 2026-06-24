# Document Naming Series & PDF Filename Format for the Block Invoice Template — Design

**Date:** 2026-06-24
**Status:** Approved (design)
**Branch:** `feat/document-naming-series`
**Base version:** 1.5.80
**Builds on:** `docs/superpowers/specs/2026-06-20-pdf-filename-nomenclature-design.md`
(the global filename builder this work extends)

## Goal

Bring the plugin's two existing "naming" capabilities — the **document
numbering series** (prefix/suffix/padding/yearly-reset/next-number) and the
**PDF filename template** — into the **Block Invoice Template editor**, and make
the filename template configurable **per document type** (global default with an
optional per-type override). Add one new filename token,
`{document_number_sequence}`, for the raw sequence counter.

Nothing about the numbering *engine* changes. This is primarily a
**surfacing + per-type-override** feature: the Block editor and the classic
WooCommerce settings tabs both read and write the **same** per-type options and
the **same** sequential-number store, so the two surfaces stay in sync with no
reconciliation logic.

## Current state (what already exists)

- **Numbering engine** — `woi_pdf_format_document_number()`
  (`woi-pdf-functions.php:1908-2014`) applies prefix/suffix/padding and date
  placeholders (`[invoice_year]`, `[order_date="…"]`, etc.). The sequential
  counter lives in a per-type DB auto-increment table via
  `SequentialNumberStore` (`OrderDocument::get_sequential_number_store()`,
  `OrderDocument.php:2073-2102`). Yearly reset is handled by
  `reset_number_yearly` + table rotation.
- **Per-type numbering settings** already exist in the **classic** settings tabs
  for `invoice`, `proforma`, `credit-note`, `receipt` — fields
  `number_format` (prefix/suffix/padding), `next_invoice_number`
  (`next_number_edit` callback), `reset_number_yearly`
  (e.g. `Invoice.php:399-476`). **Packing slip and summary have none.**
- **Filename builder** — `woi_pdf_build_filename()`
  (`woi-pdf-functions.php:325-388`) renders a **single global** template
  (`woi_pdf_settings_general['filename_template']`, default
  `{document_type}-{order_number}-{date}`) with tokens `{document_type}`,
  `{order_number}`, `{document_number}`, `{date}`; collapses empty-token
  separators; appends the extension; applies the `woi_pdf_filename` filter; then
  `sanitize_file_name()`.
- **`{document_number}` is already the *formatted* series number** — callers
  pass `(string) $this->get_number()`, and `DocumentNumber::__toString()` →
  `get_formatted()` returns the prefix/suffix/padded value (`DocumentNumber.php:102-114`).
  The raw counter (`DocumentNumber::get_plain()`, `:121-123`) is **not**
  currently exposed to filenames — this gap is what the new token fills.
- **Block editor** — `BlockEditorPage.php` renders the React app with
  `docType: 'invoice'` hardcoded (`:88`); settings persist via REST endpoints in
  `includes/Rest.php` and `src/block-editor/store.js`. There is **no** numbering
  or filename UI in the Block editor today.

## Decisions (from brainstorming)

1. **Naming series:** surface the existing engine; do **not** build a new one.
2. **Block-editor scope:** a doc-type **switcher** scopes the new
   numbering/filename panel. It does **not** change which *template* is edited —
   block-template authoring stays invoice-only. The switcher only selects which
   document type's numbering + filename settings are shown.
3. **Storage:** shared. The Block editor reads/writes the **same** per-type
   options (`woi_pdf_documents_settings_invoice`,
   `woi_pdf_documents_settings_proforma`,
   `woi_pdf_documents_settings_credit-note`,
   `woi_pdf_documents_settings_receipt`,
   `woi_pdf_documents_settings_packing-slip`) and the **same** sequential-number
   store the classic tabs use. No separate Block-editor option; no sync logic.
   (Option name confirmed via `Settings::get_document_settings()` →
   `woi_pdf_documents_settings_{type}`.)
4. **Filename:** global default **+ optional per-type override**.
5. **New token:** `{document_number_sequence}` = the raw counter (e.g. `123`).
   `{document_number}` is unchanged (formatted series number).
6. **Next-number editing is included** in the Block editor, writing through the
   sequential-number store (the same path as the classic `next_number_edit`
   callback).

## Type → capability matrix

| Document type | Numbering series? | Filename override? | In Block-editor switcher? |
|---|---|---|---|
| invoice | yes (existing) | yes (new) | yes |
| proforma | yes (existing) | yes (new) | yes |
| credit-note | yes (existing) | yes (new) | yes |
| receipt | yes (existing) | yes (new) | yes |
| packing-slip | **no** | yes (new) | yes (filename only) |
| summary / bulk | no | no | no |

When the switcher selects `packing-slip`, the numbering fields are hidden and
only the filename-override field shows.

## Architecture

### 1. Filename builder — per-type override + new token

`woi_pdf_build_filename( array $args )` (`woi-pdf-functions.php`):

- **Template resolution chain** (first non-empty wins):
  1. per-type override — `get_option( "woi_pdf_documents_settings_{$type}" )['filename_template']`
  2. global — `woi_pdf_settings_general['filename_template']`
  3. hard default — `{document_type}_{order_number}_{date}` (underscores — matches
     the shipped default in `woi_pdf_get_filename_settings()`)
- **Date-format resolution chain** mirrors the template chain
  (`filename_date_format`, default `Y-m-d`).
- A small helper extends `woi_pdf_get_filename_settings()` to accept an optional
  `$type` and apply the per-type→global→default chain. The builder calls it with
  `$args['type']`.
- **New token `{document_number_sequence}`** — resolved from a new
  `document_number_sequence` arg. Empty → the token and one adjacent separator
  are removed (same cleanup the other empty tokens already get).
- The `woi_pdf_filename` filter and `sanitize_file_name()` remain the final two
  steps (unchanged contract).

**Callers** (`get_filename()` in `Invoice.php`, `Proforma.php`,
`CreditNote.php`, `Receipt.php`, `PackingSlip.php`, and the generic
`OrderDocument.php`) add one arg:

```php
'document_number_sequence' => ( $num = $this->get_number() ) ? (string) $num->get_plain() : '',
```

No other call-site change; `type` is already passed, so per-type resolution is
automatic.

### 2. Per-type settings — classic tabs

Add one field to each type's `init_settings()`:

- **`filename_template`** (text, `text_element` callback) — "Filename override".
  Description: "Leave blank to use the global filename template (Settings →
  General). Tokens: `{document_type}`, `{order_number}`, `{document_number}`,
  `{document_number_sequence}`, `{date}`." Added to exactly the five types with
  a settings tab + per-type option: **invoice, proforma, credit-note, receipt,
  packing-slip**. The generic `OrderDocument` has no settings-tab option, so it
  keeps inheriting the global template (no override field).
- Numbering fields already exist for the four numbered types — **no change**.

These persist into the existing per-type option, so the Block editor (which
reads the same option) sees them immediately.

### 3. Block-editor UI — `src/block-editor/`

Add a **"Numbering & filename"** section to the settings panel
(`EditorSettings` / its React counterpart in `src/block-editor/`):

- **Doc-type selector** (`SelectControl`): invoice, proforma, credit-note,
  receipt, packing-slip. Changing it loads that type's values via REST.
- **Numbering fields** (hidden for packing-slip): prefix, suffix, padding,
  reset-yearly (checkbox), **next number**.
- **Filename override** (text) with token hint text and a static example.
- Values load through a new store action and save back through REST. The panel
  reflects exactly what the classic tab holds (shared option/store).

`BlockEditorPage.php` localizes the list of configurable types + their current
values into `woiBlocks` so the panel renders without an extra round-trip on
first paint (mirrors how `docOptions`/`contactItems` are already localized,
`:85-103`).

### 4. REST — `includes/Rest.php`

New endpoint pair, fronting the existing per-type option **and** the sequential
store (so it is the single write path the Block editor uses):

- `GET /document-naming?type={type}` → returns
  `{ prefix, suffix, padding, reset_number_yearly, next_number, filename_template,
  has_series }` for the type. `next_number` is read from the type's
  `SequentialNumberStore`; `has_series` is false for packing-slip.
- `POST /document-naming` (body: `{ type, …fields }`) →
  - writes `number_format`/`reset_number_yearly`/`filename_template` into
    `get_option( "woi_pdf_{$type}" )` (merge, don't clobber other keys);
  - writes `next_number` through `get_sequential_number_store()->set_next( … )`
    — the **same** mechanism as the classic `next_number_edit` save, including
    the "lower than current can create duplicates" caveat. Validate as a
    positive integer.
- `type` is validated against the allow-list (the five types above); unknown
  types are rejected. Capability check matches the other write endpoints
  (`manage_woocommerce` / existing nonce, as used elsewhere in `Rest.php`).

### Data flow

```
Classic tab  ──┐                       ┌── reads ──> get_option("woi_pdf_documents_settings_{type}")
               ├── same per-type option ┤
Block editor ──┘  (+ sequential store)  └── next_number ──> SequentialNumberStore

PDF download:
OrderDocument::output()/save()
  -> get_filename()
       -> woi_pdf_build_filename({ type, …, document_number_sequence })
            -> resolve template: per-type override ?? global ?? default
            -> resolve date format: per-type ?? global ?? 'Y-m-d'
            -> substitute tokens (incl. {document_number_sequence})
            -> collapse empty-token separators
            -> append extension
            -> apply 'woi_pdf_filename' filter
            -> sanitize_file_name()
```

## Edge cases & error handling

- **Per-type override blank** → falls through to global, then hard default.
- **`{document_number_sequence}` empty** (no series, e.g. packing-slip, or
  number not yet assigned) → token + one adjacent separator removed; no `--`.
- **Packing-slip filename uses `{document_number}`/`_sequence`** → both resolve
  empty and are stripped; remaining tokens render normally.
- **Next-number set below current** → allowed (matches classic behavior) but the
  endpoint returns the same warning text; UI surfaces it. Reject non-integer /
  negative input.
- **Unknown/forbidden `type`** in REST → 400/403; never writes.
- **Existing installs** → no per-type override saved means identical behavior to
  today (global template). Fully backward compatible.
- **`woi_pdf_filename` filter** still runs last and overrides everything —
  unchanged for existing customizations.

## Backward compatibility

- No behavior change unless an admin sets a per-type override. Global template
  remains the default path.
- The new `{document_number_sequence}` token is additive; existing templates are
  untouched.
- Shared storage means no migration: classic-tab numbering values are read
  as-is by the new Block-editor panel.

## Versioning

- Touches PHP **and** JS (block-editor bundle), so bump **both** strings in
  `woocommerce-orders-invoice-pdf.php` (header `Version` + `public string
  $version`) and rebuild the bundle (`npm run build`) — done **last**, at
  landing, on top of the rebased source (per CLAUDE.md).
- Changelog: new per-type filename override, the `{document_number_sequence}`
  token, and the Block-editor numbering/filename panel.

## Testing

**PHPUnit** (`tests/Unit/`, run with `-d auto_prepend_file=tests/bootstrap.php`):

- Extend `FilenameBuilderTest`:
  - per-type override beats global; global used when override blank; default
    when both blank;
  - per-type `filename_date_format` override;
  - `{document_number_sequence}` present → raw counter; absent → separator
    cleanup; alongside formatted `{document_number}` in one template;
  - sanitize pass still applied; `woi_pdf_filename` filter still wins.
- REST: `type` allow-list enforcement; option merge doesn't clobber sibling
  keys; `next_number` writes via the store; `has_series=false` for packing-slip.

**Jest** (`npm run test:unit`):

- Naming/filename panel: switching doc type loads the right values; saving posts
  the expected payload; numbering fields hidden for packing-slip; filename
  example/token hint renders.

**Manual / local render** (no deploy): `php tools/render-visual-sample.php` is
body-only, so verify filenames via the PHPUnit builder tests and a quick admin
smoke test on the live harness if needed.

## Out of scope (YAGNI)

- Multi-type **template** authoring in the Block editor (only the
  numbering/filename panel is multi-type; template editing stays invoice-only).
- New numbering placeholders beyond the existing engine's set.
- `{customer}` / company filename token.
- A live JS preview of the filename in the classic tabs (Block-editor panel
  shows a static example; that is enough).
- Summary/bulk numbering or per-type summary filename.
