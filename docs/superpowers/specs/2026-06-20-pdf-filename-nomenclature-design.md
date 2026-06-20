# PDF Filename Nomenclature — Design

**Date:** 2026-06-20
**Status:** Approved (design)
**Branch:** `worktree-pdf-filename-nomenclature`
**Base version:** 1.5.4

## Goal

Give downloaded/attached PDFs a standardized, intuitive filename that always
includes the WooCommerce **order number**, so users can locate and identify a
document at a glance. Make the format admin-configurable via a single global
template, while preserving the existing `woi_pdf_filename` developer filter and
the final `sanitize_file_name()` pass.

## Problem (current state)

Each document type builds its filename independently, duplicating ~20 lines of
logic across 7 `get_filename()` methods:

| Document | Current pattern | Order # guaranteed? |
|---|---|---|
| Invoice | `invoice-{invoice_number *or* order_number}.pdf` | No — uses invoice number when `display_number = invoice_number` |
| Packing slip | `packing-slip-{doc_number *or* order_number}.pdf` | No |
| Credit note | `credit-note-{doc_number *or* order_number}.pdf` | No |
| Receipt / Proforma | same shape | No |
| Generic order doc | `{type}-{order_number}.pdf` | Yes |
| Summary / bulk (multi-order) | `{name}-{YYYY-MM-DD}.pdf` | n/a (many orders) |

When `display_number` is configured, the order number is **replaced** by the
document's own number — the gap this work closes.

## Decisions (from brainstorming)

- **Delivery:** Configurable template setting (not a hardcoded format).
- **Default template:** `{document_type}-{order_number}-{date}` →
  `Invoice-1042-2026-06-20.pdf`.
- **Placeholders supported:** `{document_type}`, `{order_number}`,
  `{document_number}`, `{date}`.
- **Scope:** Single global template applied to all document types.
- **`{date}` source:** Current (generation) date, with a configurable PHP date
  format (default `Y-m-d`).
- **Bulk / multi-order:** Keep the admin's template token order; `{order_number}`
  expands to `{count}-orders` in place →
  `Invoices-12-orders-2026-06-20.pdf` (option **A** — consistent with the
  single template).

## Architecture

### 1. New settings (General tab → `woi_pdf_settings_general`)

Two new fields, rendered with the existing `text_element` callback in
`includes/Settings/SettingsGeneral.php`:

- **`filename_template`** (text) — default `{document_type}-{order_number}-{date}`.
  - The file extension (`.pdf` or the requested output format) is appended
    automatically and is **not** part of the template.
  - Field description lists the four placeholders and shows a live example
    (`Invoice-1042-2026-06-20.pdf`).
- **`filename_date_format`** (text) — default `Y-m-d`. PHP `date()` format for
  the `{date}` token; uses the current date.

Both read via `get_option( 'woi_pdf_settings_general', array() )` with the
defaults applied at read time so existing installs (no saved value) get the new
default template.

### 2. New centralized builder (DRY)

Add a single helper that renders the template and replaces the duplicated logic.

```
woi_pdf_build_filename( array $args ): string
```

Defined in `woi-pdf-functions.php` (alongside
`woi_pdf_get_document_output_format_extension`).

**`$args` keys:**

| Key | Meaning |
|---|---|
| `type` | Machine type (e.g. `invoice`) — for the filter, not the name |
| `document_type` | Localized display name, already singular/plural-resolved by the caller via `_n( …, $order_count )` |
| `order_ids` | Array of order IDs in this document |
| `order_number` | Pre-resolved order-number string from the caller (may be empty) |
| `order_id` | Primary order ID, for the empty-order-number fallback |
| `document_number` | The document's own number, or empty |
| `output_format` | `pdf` (default) or other; drives the extension |

The caller resolves both `document_type` (pluralized) and `order_number` using
its own rules (e.g. normal docs use `get_order_number()`, respecting
sequential-order-number plugins; refunds use the refund `order_id`). The builder
does **not** re-derive them — it only applies the empty-value fallback, the
multi-order collapse, and token substitution. This keeps each document's quirks
at the call site (where they already live today) and the builder generic.

**Token resolution:**

- `{document_type}` → the caller's `document_type` (already pluralized).
- `{order_number}` →
  - **Single order:** the caller's `order_number`. Empty → fall back to
    `order_id` → then `uniqid()` (preserves today's safety net).
  - **Multiple orders:** the literal `"{count}-orders"` (e.g. `12-orders`).
- `{document_number}` → the document number when present; empty → the token and
  one adjacent separator are removed.
- `{date}` → `date( filename_date_format )` (current date).

**Post-processing (in order):**
1. Substitute all tokens.
2. Collapse runs of `-` and trim leading/trailing `-` left by empty tokens.
3. Append the extension via `woi_pdf_get_document_output_format_extension()`.
4. Apply the `woi_pdf_filename` filter (same signature/args as today).
5. `sanitize_file_name()` (strips characters such as parentheses — the builder
   never emits parens, so no surprises).

### 3. Refactor each `get_filename()`

Replace the bespoke body of each with a call to `woi_pdf_build_filename()`,
passing that document's `_n()` labels and number. Methods updated:

- `includes/Documents/Invoice.php`
- `includes/Documents/PackingSlip.php`
- `includes/Documents/CreditNote.php`
- `includes/Documents/Receipt.php`
- `includes/Documents/Proforma.php`
- `includes/Documents/OrderDocument.php` (generic)
- `includes/Documents/Summary.php` (no single order → behaves as multi/bulk)

`includes/Documents/BulkDocument.php` delegates to its wrapped document's
`get_filename()` and needs no change beyond inheriting the new behavior.

Each method keeps applying `woi_pdf_filename` and `sanitize_file_name()` — those
move *into* the builder so the contract is centralized; the call sites no longer
duplicate them.

### Data flow

```
OrderDocument::output()/save()
  -> $document->get_filename( $context, $args )
       -> woi_pdf_build_filename( $args + labels + number )
            -> read template + date format from woi_pdf_settings_general
            -> resolve tokens (single vs multi)
            -> cleanup separators
            -> append extension
            -> apply 'woi_pdf_filename' filter
            -> sanitize_file_name()
  -> woi_pdf_pdf_headers( $filename, $mode, $pdf )
```

## Edge cases & error handling

- **Empty `{order_number}`** for a single order → `order_id` → `uniqid()`.
- **Empty `{document_number}`** → token + one adjacent separator removed; no
  doubled `--`.
- **Empty template** (admin clears the field) → fall back to the default
  template rather than producing a name with no order context.
- **Custom date format** with invalid characters → still passed to `date()`;
  `sanitize_file_name()` cleans the result. No crash.
- **Refunds** (`shop_order_refund`) → keep current behavior: use the refund's
  `order_id` as the order number source (handled in the generic doc path).
- **Bulk** → `{order_number}` becomes `{count}-orders`; `{document_number}`
  resolves empty and is removed.

## Backward compatibility

- Default template **changes existing single-order filenames**: it adds the date
  and forces the order number even where `display_number` previously won. This
  is the intended behavior and is called out in the changelog.
- Installs that customized filenames via the `woi_pdf_filename` filter are
  unaffected — the filter still runs last and overrides the template output.

## Versioning

- Bump `Version` header + `WOI_PDF_VERSION` from **1.5.4** to **1.5.5**
  (`woocommerce-orders-invoice-pdf.php`). `WOI_PDF_VERSION` doubles as the
  asset cache-bust key, but this change touches no JS/CSS, so no asset rebuild
  is required.
- Add a changelog entry describing the new configurable filename template and
  the default-filename change.

## Testing

New PHPUnit unit tests under `tests/Unit/` targeting the builder directly
(no WordPress bootstrap needed beyond the existing `tests/bootstrap.php`):

- Single-order default template → `Invoice-1042-2026-06-20.pdf`.
- `{document_number}` present and absent (separator cleanup).
- Multi-order → `{order_number}` becomes `{count}-orders`.
- Custom `filename_date_format` (e.g. `d-m-Y`, `Ymd`).
- Empty template falls back to default.
- Empty order number falls back to order_id / uniqid.
- A template containing characters that `sanitize_file_name()` strips →
  verify the final, sanitized result.

Plus a smoke check that each refactored `get_filename()` returns a string ending
in the correct extension for single and bulk contexts.

> Note: PHPUnit must be invoked with
> `-d auto_prepend_file=tests/bootstrap.php` or it dies silently (see project
> memory).

## Out of scope (YAGNI)

- Per-document-type templates (single global template chosen).
- A `{customer}` / company token.
- Order-date (vs current-date) token source.
- A live JS preview of the template in the settings UI (static example text in
  the field description is enough).
