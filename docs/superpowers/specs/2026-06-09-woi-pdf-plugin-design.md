# WooCommerce Orders Invoice PDF — MVP Plugin Design

**Date:** 2026-06-09
**Status:** Approved
**Scope:** MVP — standalone merged plugin (Path 3) combining base plugin (Path 4), Pro plugin (Path 1), and Template Editor (Path 2)

---

## 1. Goals & Constraints

Build a single, self-contained WordPress plugin that:

- Requires **WooCommerce only** — no runtime dependency on any source plugin
- Targets **PHP 7.4+**, **WordPress 6.0+** (forward-compatible with WP 7.x), **WooCommerce 3.3+**
- Declares **HPOS compatibility** (`custom_order_tables`)
- Can be installed **alongside** the original source plugins without PHP class conflicts
- Adheres to **SOLID** principles and **DRY** pipelines throughout
- Uses **PascalCase** for all class/interface names; **snake_case** with `woi_pdf_` prefix for hooks, filters, and global functions

**Out of scope for MVP:** multilingual support (WPML / Polylang / TranslatePress), cloud storage (FTP / Dropbox / Google Drive), bulk export, EDI/UBL document types, automatic update/licence system.

---

## 2. Plugin Identity

| Property | Value |
|---|---|
| Folder / slug | `woocommerce-orders-invoice-pdf` |
| Main plugin file | `woocommerce-orders-invoice-pdf.php` |
| Global singleton class | `WOI_PDF` (accessed via `WOI_PDF()` function) |
| PHP namespace (all classes) | `WOI\PDF` |
| Option key prefix | `woi_pdf_` |
| Hook / filter prefix | `woi_pdf_` |
| Text domain | `woocommerce-orders-invoice-pdf` |
| REST route base | `wc/v3/orders/{order_id}/documents` |
| Constants | `WOI_PDF_VERSION`, `WOI_PDF_PLUGIN_FILE`, `WOI_PDF_PLUGIN_PATH`, `WOI_PDF_PLUGIN_URL` |

---

## 3. Compatibility Targets

- **PHP:** 7.4 minimum; 8.0–8.3 fully supported (no deprecated functions, typed properties used where base code allows)
- **WordPress:** 6.0 minimum; no deprecated WP APIs; uses `wp_doing_ajax()`, `rest_sanitize_request_arg()`, `WC_Data_Exception` patterns compatible with WP 7.x roadmap
- **WooCommerce:** 3.3 minimum; HPOS declared via `\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOI_PDF_PLUGIN_FILE )`
- **Conflict-free:** all classes live under `WOI\PDF` namespace; no global class names clash with source plugins

---

## 4. File Structure

```
woocommerce-orders-invoice-pdf/
├── woocommerce-orders-invoice-pdf.php   ← plugin header + WOI_PDF singleton bootstrap
├── woi-pdf-functions.php                ← global helpers: woi_pdf_get_document(), woi_pdf_get_document_types()
├── composer.json                        ← Dompdf + Strauss vendor prefix config
├── vendor/                              ← Dompdf + PSR autoloaders (ported from Path 4)
├── assets/
│   ├── css/
│   │   ├── admin.css                    ← order list / write-panel styles
│   │   ├── editor.css                   ← drag-and-drop editor styles (Path 2)
│   │   └── order-styles.css             ← PDF view button styles
│   ├── js/
│   │   ├── admin.js                     ← order admin interactions
│   │   └── editor.js                    ← jQuery UI sortable editor (Path 2)
│   ├── fonts/                           ← OpenSans, RobotoSlab, Segoe (Path 4)
│   └── images/
├── includes/
│   ├── Main.php
│   ├── Admin.php
│   ├── Assets.php
│   ├── Documents.php
│   ├── Endpoint.php
│   ├── Frontend.php
│   ├── Install.php
│   ├── Settings.php
│   ├── Semaphore.php
│   ├── FontSynchronizer.php
│   ├── Rest.php
│   ├── DocumentRenderer.php             ← NEW: single Dompdf pipeline
│   ├── TemplateLoader.php               ← NEW: template path resolution
│   ├── Documents/
│   │   ├── DocumentInterface.php        ← base contract for all documents
│   │   ├── NumberedDocumentInterface.php
│   │   ├── EmailAttachableInterface.php
│   │   ├── BulkDocumentInterface.php
│   │   ├── OrderDocument.php            ← abstract base (implements DocumentInterface)
│   │   ├── OrderDocumentMethods.php     ← order data methods (items, totals, addresses)
│   │   ├── DocumentNumber.php           ← number formatting (padding, prefix, suffix)
│   │   ├── SequentialNumberStore.php    ← DB-backed sequential number store
│   │   ├── BulkDocument.php             ← multi-order base (implements BulkDocumentInterface)
│   │   ├── Invoice.php
│   │   ├── PackingSlip.php
│   │   ├── Proforma.php                 ← from Path 1
│   │   ├── CreditNote.php               ← from Path 1
│   │   ├── Receipt.php                  ← from Path 1
│   │   └── Summary.php                  ← from Path 1
│   └── Editor/
│       ├── EditorMain.php               ← custom block injection at template action hooks
│       ├── EditorSettings.php           ← column/totals drag-and-drop editor + AJAX
│       └── PriceStorage.php             ← NEW: saves item price + tax rate meta at checkout
├── templates/
│   ├── Simple/                          ← from Path 4 (invoice, packing-slip)
│   │   ├── html-document-wrapper.php
│   │   ├── invoice.php
│   │   ├── packing-slip.php
│   │   └── template-functions.php
│   ├── Simple Premium/                  ← from Path 2 (all 6 doc types)
│   ├── Modern/                          ← from Path 2 (all 6 doc types)
│   └── Business/                        ← from Path 2 (all 6 doc types)
└── languages/
```

---

## 5. Interface Hierarchy (ISP + LSP)

All document types implement `DocumentInterface`. Additional capabilities are declared via separate, narrower interfaces — no class carries dead methods.

```php
namespace WOI\PDF\Documents;

interface DocumentInterface {
    public function get_type(): string;
    public function get_title(): string;
    public function is_enabled(): bool;
    public function exists(): bool;
    public function init( $order ): void;
    public function get_html(): string;
    public function get_settings_fields(): array;
    public function get_settings_option_name(): string;
}

interface NumberedDocumentInterface extends DocumentInterface {
    public function get_number(): ?DocumentNumber;
    public function set_number( int $number ): void;
    public function get_date(): ?\WC_DateTime;
    public function has_number(): bool;
}

interface EmailAttachableInterface {
    public function get_attach_to_email_ids(): array;
}

interface BulkDocumentInterface extends DocumentInterface {
    public function set_order_ids( array $order_ids ): void;
}
```

**Interface implementation per document type:**

| Document | `NumberedDocumentInterface` | `EmailAttachableInterface` | `BulkDocumentInterface` |
|---|---|---|---|
| Invoice | Yes | Yes | — |
| PackingSlip | — | Yes | — |
| Proforma | Yes | Yes | — |
| CreditNote | Yes | Yes | — |
| Receipt | Yes | Yes | — |
| Summary | — | — | Yes |

---

## 6. SOLID Patterns

### Single Responsibility

| Class | The one thing it owns |
|---|---|
| `OrderDocument` | Document lifecycle — number assignment, `save()`, `delete()`, `exists()` |
| `OrderDocumentMethods` | Order data access — line items, totals, addresses, taxes, fees |
| `DocumentRenderer` | Dompdf pipeline — HTML → PDF binary, streaming, temp-file saving |
| `TemplateLoader` | Template path resolution for a given document type and template name |
| `Rest` | HTTP REST concerns — route registration, request handling, response shaping |
| `Settings` | Settings framework — tab registration, field rendering, option storage |
| `EditorMain` | Custom block injection at template action hooks |
| `EditorSettings` | Column/totals editor admin UI, AJAX handlers, editor option storage |
| `PriceStorage` | Saving regular item price and tax rate percentage in order meta at checkout |
| `SequentialNumberStore` | DB-backed sequential number generation and storage |
| `DocumentNumber` | Number value formatting — padding, prefix, suffix |
| `Install` | Activation, temp-dir creation, option defaults, version-gated DB migrations |
| `Semaphore` | Advisory lock preventing concurrent PDF generation for the same order |

### Open/Closed

New document types register via filter — no core class is modified:

```php
add_filter( 'woi_pdf_document_classes', function( array $documents ): array {
    $documents[ Proforma::class ] = new Proforma();
    return $documents;
} );
```

`Settings` builds document tabs by calling `$document->get_settings_fields()` on each registered document. Adding a new document automatically surfaces its settings tab.

`Rest` iterates the document registry — a new document type is automatically included in all REST responses without touching `Rest`.

### Dependency Inversion

`Rest`, `Main`, and `Admin` depend on `DocumentInterface` (abstraction), not on `Invoice` or `CreditNote` directly. They receive instances from `Documents::get_documents()`.

```php
// Rest.php — depends on registry + interface, not concrete classes
$documents = WOI_PDF()->documents->get_documents( 'enabled' );
foreach ( $documents as $document ) { // $document: DocumentInterface
    // works for every registered type
}
```

`DocumentRenderer` is injected into `Main` and `Rest` — not instantiated inside them — making it testable and swappable.

### DRY Shared Pipelines

- `DocumentRenderer` is the **only** place Dompdf is called. No document class invokes Dompdf directly.
- `TemplateLoader::locate( string $type, string $template_name ): string` is the **only** place template paths are resolved.
- `SequentialNumberStore` + `DocumentNumber` are the **only** places number formatting logic lives.
- `Settings::register_document_tab( DocumentInterface $document )` renders any document's settings tab from `$document->get_settings_fields()` — no per-document tab code in `Settings`.

---

## 7. Naming Conventions

| Context | Convention | Example |
|---|---|---|
| Class names | PascalCase | `DocumentRenderer`, `OrderDocument`, `EditorSettings` |
| Interface names | PascalCase + `Interface` suffix | `DocumentInterface`, `NumberedDocumentInterface` |
| Method names | snake_case | `get_document_type()`, `is_enabled()`, `set_number()` |
| Boolean methods | `is_` / `has_` / `can_` prefix | `is_enabled()`, `has_number()`, `can_generate()` |
| Hook / filter names | `woi_pdf_{noun}_{verb}` | `woi_pdf_document_classes`, `woi_pdf_before_html` |
| Global functions | `woi_pdf_{verb}_{noun}` snake_case | `woi_pdf_get_document()`, `woi_pdf_get_document_types()` |
| Constants | SCREAMING_SNAKE_CASE | `WOI_PDF_VERSION`, `WOI_PDF_PLUGIN_FILE` |
| Option keys | `woi_pdf_settings_{type}` | `woi_pdf_settings_invoice`, `woi_pdf_editor_settings` |

---

## 8. Settings Architecture

One WordPress option per scope. Each document class declares its own fields (`get_settings_fields()`) — `Settings` renders them without knowing the document type (OCP).

| Option key | Contents |
|---|---|
| `woi_pdf_settings_general` | Shop name, address, logo, template path, header/footer height |
| `woi_pdf_settings_invoice` | Enable, attach-to-emails, number format, display options |
| `woi_pdf_settings_packing-slip` | Enable, attach-to-emails, display options |
| `woi_pdf_settings_proforma` | Enable, attach-to-emails, number sequence, display options |
| `woi_pdf_settings_credit-note` | Enable, attach-to-emails, number sequence, auto-generate on refund |
| `woi_pdf_settings_receipt` | Enable, attach-to-emails, number sequence |
| `woi_pdf_settings_summary` | Enable, display options |
| `woi_pdf_settings_debug` | REST API toggle, debug mode, temp dir path, log level |
| `woi_pdf_editor_settings` | Column order, totals order, custom blocks, custom CSS per template |

---

## 9. REST API

**Base:** `wc/v3/orders/{order_id}/documents`
**Permission:** `edit_shop_orders` capability on every route.

### GET — list documents for an order

```
GET /wc/v3/orders/123/documents

200 OK
{
  "invoice":      { "exists": true,  "number": "2025-0042", "date": "2025-06-01" },
  "packing-slip": { "exists": false },
  "proforma":     { "exists": true,  "number": "PRO-0011",  "date": "2025-05-30" },
  "credit-note":  [
    { "exists": true, "number": "CN-0003", "date": "2025-06-02", "refund_id": 456 }
  ],
  "receipt":      { "exists": false },
  "summary":      { "exists": false }
}
```

### POST — create or regenerate a document

```
POST /wc/v3/orders/123/documents
{ "type": "invoice", "action": "create" }

201 Created
{ "type": "invoice", "number": "2025-0043", "date": "2025-06-09", "exists": true }
```

### DELETE — delete a document

```
DELETE /wc/v3/orders/123/documents
{ "type": "invoice" }

200 OK
{ "deleted": true, "type": "invoice", "previous": { "number": "2025-0043" } }
```

`Rest` has three handler methods (`handle_get`, `handle_create`, `handle_delete`) and one shared `permissions_check`. No per-document-type branching inside `Rest` — all delegation goes through `DocumentInterface`.

---

## 10. Core Data Flows

### PDF generation

```
Request (AJAX / ?woi_pdf= URL / REST POST)
→ permission check
→ woi_pdf_get_document( $type, $order_id )          ← global helper, returns DocumentInterface
    → Document class init( $order )
    → load settings + assign/retrieve number
→ $document->get_html()
    → TemplateLoader::locate( $type, $template )     ← resolves path once
    → template PHP rendered via output buffer
    → EditorMain hooks inject custom blocks
    → template-functions.php reads EditorSettings for column/totals order
→ DocumentRenderer::render( string $html ): string   ← sole Dompdf caller
→ output: stream / save temp file / base64 in REST response
```

### Email attachment

```
WooCommerce dispatches email
→ Main::attach_document_to_email() on woocommerce_email_attachments filter
→ foreach enabled EmailAttachableInterface document:
    → check get_attach_to_email_ids() against current email ID
    → if match: DocumentRenderer::save_temp( $document ) → file path
→ return file paths merged into $attachments array
```

---

## 11. Identifier Mapping (source → new)

| Source identifier | New identifier |
|---|---|
| Namespace `WPO\IPS\*` | `WOI\PDF\*` |
| Namespace `WPO\WC\PDF_Invoices_Pro\*` | `WOI\PDF\*` (merged flat) |
| Namespace `WPO\WC\PDF_Invoices_Templates\*` | `WOI\PDF\Editor\*` |
| Global class `WPO_WCPDF` | `WOI_PDF` |
| Global function `WPO_WCPDF()` | `WOI_PDF()` |
| Helper functions `wpo_wcpdf_*` | `woi_pdf_*` |
| Option keys `wpo_wcpdf_*` | `woi_pdf_*` |
| Hook/filter prefix `wpo_wcpdf_*` | `woi_pdf_*` |
| Option `wpo_wcpdf_editor_settings` | `woi_pdf_editor_settings` |

---

## 12. Out of Scope (MVP)

- Multilingual (WPML / Polylang / TranslatePress)
- Cloud storage (FTP / Dropbox / Google Drive)
- Bulk export / ZIP download
- EDI / UBL / Peppol document output
- Automatic licence-based update system
- PDF archiving / server-side storage
