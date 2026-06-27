# WooCommerce Orders Invoice PDF

Turn every WooCommerce order into a properly documented transaction — automatically.

Generates PDF invoices, packing slips, proforma invoices, credit notes, receipts, and order summaries and attaches or delivers them without anyone having to remember to do it.

---

## Who this is for

Small and medium-sized businesses that sell through WooCommerce and need their order documentation to look professional, stay consistent, and stop consuming manual effort every time an order comes in.

If your current workflow involves opening Word, filling in a customer's name, saving as PDF, attaching it to an email, and repeating that for every order — this plugin eliminates the loop entirely.

---

## Document types

### Invoice
A sequentially numbered tax invoice issued per order, automatically attached to the order confirmation email. Customers receive it without anyone on your team taking action. The number sequence never resets, never duplicates, and can be prefixed (e.g. `INV-2025-00047`) so your accountant stops asking where the gaps are.

### Packing Slip
A warehouse-ready pick list generated from the same order data. Print it directly from the order screen — no copying items into a separate document, no transcription errors, no "I forgot to pack the third item" because the slip was written from memory.

### Proforma Invoice
For customers who need to raise a purchase order before paying. Issue a proforma from any unpaid order, send it to the buyer's accounts department, and convert it to a real invoice once payment lands — without re-entering any data.

### Credit Note
Automatically scoped to the refunded amount when a WooCommerce refund is processed. Keeps a paper trail for every reduction, which matters at VAT return time when your accountant asks why invoice #47 doesn't match the payment received.

### Receipt
A payment-confirmation document separate from the invoice — useful for B2C businesses where customers want proof of payment rather than a tax document, or in jurisdictions where invoices and receipts serve different legal purposes.

### Order Summary (Bulk)
A consolidated PDF covering multiple orders — useful for end-of-day warehouse handoffs, supplier reconciliations, or sending a weekly batch to a bookkeeper instead of forwarding thirty individual emails.

---

## How it fits into daily operations

**Order comes in → invoice attached to confirmation email automatically.**
No one on your team needs to do anything. The customer has their invoice before they've finished reading the order confirmation.

**Warehouse receives new orders → packing slips are already in the print queue.**
Staff print directly from WooCommerce. The slip matches the order exactly, in a layout that works on paper.

**Customer requests a copy of their invoice → it's one click from the order screen.**
No searching through sent email, no regenerating from a spreadsheet, no "I'll send it later today."

**A refund is issued → a credit note is generated and stored against the order.**
The financial paper trail closes itself. Tax filing has the right numbers.

**Accountant asks for all invoices from last quarter → export or print the batch.**
The Order Summary document type handles multi-order output. No assembling PDFs manually.

---

## Features

- **Automatic email attachment** — any document type can attach to any WooCommerce transactional email (new order, processing, completed, etc.), configurable per document type
- **Sequential numbering** — independent number sequences per document type, with configurable prefix, suffix, and zero-padding; numbers are locked at generation time and never change
- **4 template sets** — Simple, Simple Premium, Modern, Business; all fully customisable via template override in your theme
- **Template editor** — drag-and-drop reordering of table columns (product name, SKU, quantity, price, etc.) and totals rows (subtotal, tax, discount, shipping) without touching PHP
- **Per-order document management** — generate, regenerate, or delete any document from the order edit screen
- **REST API** — full `GET / POST / DELETE` on `wc/v3/orders/{id}/documents` for headless or ERP integrations
- **HPOS compatible** — works with WooCommerce High-Performance Order Storage
- **Historical settings** — invoices rendered against the settings that were active when the order was placed, not today's settings
- **Proforma-to-invoice workflow** — proformas and invoices are independent documents on the same order; issuing one does not affect the other
- **No external dependencies** — WooCommerce is the only required plugin; no SaaS, no API keys, no data leaving your server

---

## Requirements

| | Minimum |
|---|---|
| PHP | 7.4 |
| WordPress | 6.0 |
| WooCommerce | 3.3 |

## Installation

1. Upload the plugin folder to `wp-content/plugins/`
2. Run `composer install --no-dev` inside the plugin directory
3. Activate via **Plugins → Installed Plugins**
4. Configure at **WooCommerce → PDF Documents**

---

## Template customisation

Copy any template file from `templates/{TemplateName}/` into your theme at:

```
wp-content/themes/{your-theme}/woocommerce-orders-invoice-pdf/{TemplateName}/{document-type}.php
```

Plugin updates will not overwrite theme overrides.

---

## REST API

Enable under **WooCommerce → PDF Documents → Debug Settings → Enable REST API**.

**Route:** `wc/v3/orders/{order_id}/documents`

| Method | Action |
|---|---|
| `GET` | Stream a document's PDF (requires `type`; returns the raw PDF bytes) |
| `POST` | Create or regenerate a document |
| `DELETE` | Delete a document |

A listing of an order's existing documents (as JSON, not PDF bytes) is also exposed via the `documents` field on the standard `GET wc/v3/orders/{order_id}` response.

**GET parameters:**

| Parameter | Type | Description |
|---|---|---|
| `type` | string | `invoice`, `packing-slip`, `proforma`, `credit-note`, `receipt`, `summary` — required |
| `generate` | boolean | Generate the document on the fly if it does not already exist (otherwise a missing document returns an error) |

**POST parameters:**

| Parameter | Type | Description |
|---|---|---|
| `type` | string | `invoice`, `packing-slip`, `proforma`, `credit-note`, `receipt`, `summary` |
| `regenerate` | boolean | Regenerate an existing document |
| `number` | integer | Override the document number |
| `date` | string | Override the document date (ISO 8601) |
| `note` | string | Append a note to the document |

Permission: `edit_shop_order` per-order capability. Filterable via `woi_pdf_api_permission_check`.

---

## Hooks reference

### Filters

| Filter | Description |
|---|---|
| `woi_pdf_document_classes` | Register or remove document types |
| `woi_pdf_template_folder` | Override the active template folder |
| `woi_pdf_template_path` | Override the resolved template file path |
| `woi_pdf_paper_size` | Dompdf paper size (default `A4`) |
| `woi_pdf_paper_orientation` | Dompdf orientation (default `portrait`) |
| `woi_pdf_get_html` | Filter HTML before PDF rendering |
| `woi_pdf_format_document_number` | Override formatted document number |
| `woi_pdf_document_attach_to_email_ids` | Override which emails a document attaches to |
| `woi_pdf_api_permission_check` | Override REST API access control |

### Actions

| Action | Description |
|---|---|
| `woi_pdf_init` | Plugin fully initialised |
| `woi_pdf_init_documents` | Document registry populated |
| `woi_pdf_before_dompdf_render` | Before PDF render (`$dompdf` passed) |
| `woi_pdf_after_dompdf_render` | After PDF render (`$dompdf` passed) |

---

## Second language (bilingual documents)

Documents can be rendered in two languages side by side. The feature is off by default and has no impact on documents that do not enable it (no extra spans, no `@font-face` declarations, no font assets loaded).

### Enabling it

Open **WooCommerce → PDF Documents → Customiser**, select the document type, and tick **Enable second language**. Choose the target language (Arabic is the bundled preset) and confirm the RTL direction flag. Settings are stored per document type and per template selection.

### What the engine renders

Three rendering patterns are produced automatically once the engine is on:

- **Stacked column headers** — every item-table column header gets a secondary-language line beneath the primary label (e.g. "Description" over "الوصف"). Handled via `BilingualEngine::add_header_secondaries()` and totals rows via `add_totals_secondaries()`.
- **Inline label pairs** — metadata fields (invoice number, invoice date, order number, etc.) are rendered as `Primary label \ Secondary label` on a single line (e.g. `Invoice No \ الفاتورة رقم`) using the `render_label` chokepoint in `BilingualLabelTrait`.
- **Mirror blocks** — the shop block and the buyer/billing-address block are each rendered twice, side by side: the primary (LTR) version on the left and the secondary (RTL) version on the right. Implemented via `bilingual_shop_block` and `bilingual_address_block` in `BilingualLabelTrait`.

### Label translations

Every field label has an editable translation in the Customiser. The translations are seeded from the bundled Arabic dictionary (`includes/Bilingual/dictionary/ar.php`) when the Customiser section is first opened. Leaving a field blank falls back to the dictionary value at render time — only non-blank overrides are applied. `BilingualEngine::primary_labels()` provides the canonical English label for each key; `BilingualEngine::dictionary()` provides the bundled secondary-language values. Both are filterable.

### Shop Arabic name and address

Two fields — **Shop name (Arabic)** and **Shop address (Arabic)** — appear in **WooCommerce → PDF Documents → General Settings**. These drive the secondary side of the shop mirror block. Buyer country and state are localised via `WC_Countries::get_countries()` and `get_states()` with the locale switched to the secondary language where WooCommerce has a translation; other buyer content (name, company, street) is shown as entered.

### Font

The Noto Naskh Arabic font (Regular + Bold, TTF) is bundled with the plugin and registered with Dompdf on activation. The `@font-face` CSS is emitted only when the engine is enabled for the document being rendered, so non-bilingual PDF generation is unaffected.

### Standard UAE Tax Invoice template

A preset template named **Standard UAE Tax Invoice** ships with the plugin. It is a scaffold based on the Business template with the bilingual engine pre-enabled (via the `woi_pdf_document_settings` filter, which merges the bilingual defaults non-destructively so any explicit user customisation is preserved). Selecting this template gives bilingual output without any manual Customiser configuration.

The preset is a starting point. UAE-specific item columns, a VAT summary row, an amount-in-words line, and signature blocks are planned follow-ups and are **not yet included**. The current template produces a bilingual layout that matches the Business template structure, not the full UAE tax invoice reference format.

---

## Development

```bash
composer install
./vendor/bin/phpunit
```

119 tests, 220 assertions. PHPUnit 9 + Brain Monkey — no WordPress installation required to run the test suite.

---

## License

GPLv2 or later. See [LICENSE](LICENSE).
