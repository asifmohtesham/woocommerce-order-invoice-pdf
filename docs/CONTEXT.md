# WooCommerce Orders Invoice PDF — Project Context

> Reference document for resuming work in future sessions.
> Last updated: 2026-06-09

---

## What this is

A standalone WordPress/WooCommerce plugin that generates PDF documents for orders. It is a merged MVP combining:

- **BASE plugin** — WooCommerce PDF Invoices & Packing Slips (community edition)
- **PRO/TPL plugin** — Premium template plugin (drag-and-drop editor, additional document types)

The merge produces a single self-contained deliverable with no dependency on either source plugin.

**GitHub:** https://github.com/asifmohtesham/woocommerce-order-invoice-pdf  
**Author:** Muhammad Asif Mohtesham  
**License:** GPLv2 or later

---

## Identifiers — the rename contract

Every identifier was renamed from source-plugin prefixes to these canonical forms:

| Context | Value |
|---|---|
| PHP namespace | `WOI\PDF` |
| PHP namespace (editor) | `WOI\PDF\Editor` |
| PHP namespace (vendor) | `WOI\PDF\Vendor\` (Strauss-prefixed) |
| Global singleton class | `WOI_PDF` (global namespace, not `WOI\PDF`) |
| Global accessor function | `WOI_PDF()` |
| Option/meta prefix | `woi_pdf_` |
| Hook prefix | `woi_pdf_` |
| Script/style handle prefix | `woi-pdf-` |
| Text domain | `woocommerce-orders-invoice-pdf` |
| Plugin slug | `woocommerce-orders-invoice-pdf` |
| Constants | `WOI_PDF_VERSION`, `WOI_PDF_PLUGIN_FILE`, `WOI_PDF_PLUGIN_PATH`, `WOI_PDF_PLUGIN_URL` |

**Text domain is NOT in the rename script** (`tools/rename.ps1`). It must be fixed manually after any port from a source plugin.

---

## File structure

```
woocommerce-orders-invoice-pdf.php   ← plugin entry point, global WOI_PDF class
woi-pdf-functions.php                ← global helper functions (woi_pdf_get_document etc.)
composer.json
phpunit.xml.dist
tests/
  bootstrap.php
  Unit/
    DocumentRendererTest.php
    RestTest.php
    TemplateLoaderTest.php
    Documents/
      DocumentInterfaceContractTest.php
      DocumentNumberTest.php
includes/
  Admin.php
  Assets.php
  DocumentRenderer.php
  Documents.php
  Endpoint.php
  FontSynchronizer.php
  Frontend.php
  Install.php
  Main.php
  Rest.php
  Semaphore.php
  Settings.php
  TemplateLoader.php
  Compatibility/
    FileSystem.php
  Documents/
    BulkDocument.php
    BulkDocumentInterface.php
    CreditNote.php
    DocumentInterface.php
    DocumentNumber.php
    EmailAttachableInterface.php
    Invoice.php
    NumberedDocumentInterface.php
    OrderDocument.php          ← base class (~1200 lines)
    OrderDocumentMethods.php   ← intermediate layer
    PackingSlip.php
    Proforma.php
    Receipt.php
    SequentialNumberStore.php
    Summary.php
  Editor/
    EditorMain.php             ← ported from TPL plugin
    EditorSettings.php         ← ported from TPL plugin
    PriceStorage.php           ← extracted from EditorMain (SRP)
  Settings/
    SettingsCallbacks.php
    SettingsDebug.php
    SettingsDocuments.php
    SettingsEDI.php
    SettingsGeneral.php
    SettingsUpgrade.php
templates/
  Simple/
  Simple Premium/
  Modern/
  Business/
tools/
  rename.ps1
docs/
  superpowers/
    specs/   ← design doc
    plans/   ← implementation plan (2026-06-09-woi-pdf-plugin.md)
```

---

## Architecture

### Entry point

`woocommerce-orders-invoice-pdf.php` defines `class WOI_PDF` in the **global namespace** (intentional — WordPress convention for plugin globals). The `WOI_PDF()` function returns the singleton.

`init()` runs on `plugins_loaded` priority 0 and wires all subsystems as properties on the singleton.

### Namespace

All includes use `WOI\PDF` namespace except:
- `WOI\PDF\Editor` for EditorMain, EditorSettings, PriceStorage
- `WOI\PDF\Compatibility` for FileSystem
- Vendored dompdf lives at `WOI\PDF\Vendor\Dompdf\` (Strauss-managed)

### Autoloading

- PSR-4: `WOI\PDF\` → `includes/`
- PSR-4 dev: `WOI\PDF\Tests\` → `tests/`
- `woi-pdf-functions.php` is **NOT in `autoload.files`** — it has `exit` guard for `ABSPATH`. It is loaded by `woocommerce-orders-invoice-pdf.php` at plugin boot, and by `tests/bootstrap.php` in tests.

### Vendor isolation

Strauss (`brianhenryie/strauss ^0.19`) copies and prefixes dompdf into `vendor/strauss/` under `WOI\PDF\Vendor\`. This prevents conflicts with other plugins that use dompdf.

### Document class hierarchy

```
OrderDocument (base, ~1200 lines)
  └── OrderDocumentMethods
        ├── Invoice      (implements NumberedDocumentInterface, EmailAttachableInterface)
        ├── PackingSlip  (implements EmailAttachableInterface)
        ├── CreditNote   (implements NumberedDocumentInterface, EmailAttachableInterface)
        ├── Proforma     (implements NumberedDocumentInterface, EmailAttachableInterface)
        ├── Receipt      (implements NumberedDocumentInterface, EmailAttachableInterface)
        └── Summary      (implements BulkDocumentInterface)
```

### HPOS

Declared via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)` on `before_woocommerce_init`.

---

## REST API

Route: `wc/v3/orders/{order_id}/documents`

Enabled only when `woi_pdf_settings_debug['enable_rest_api']` is set.

| Method | Action |
|---|---|
| GET | Return all existing documents for an order |
| POST | Create or regenerate a document |
| DELETE | Delete a document |

**Permission check** (in `Rest::permissions_check()`):
```php
$order_id = absint( $request['order_id'] );
$default  = $order_id
    ? current_user_can( 'edit_shop_order', $order_id )
    : current_user_can( 'edit_shop_orders' );
return apply_filters( 'woi_pdf_api_permission_check', $default, $request );
```

Uses per-order `edit_shop_order` meta-cap (with order ID), not the blanket `edit_shop_orders`. Filter: `woi_pdf_api_permission_check`.

---

## Key hooks

### Filters
- `woi_pdf_document_classes` — register/remove document types
- `woi_pdf_template_folder` — override active template folder
- `woi_pdf_template_path` — override resolved template file path
- `woi_pdf_paper_size` — dompdf paper size (default `A4`)
- `woi_pdf_paper_orientation` — dompdf orientation (default `portrait`)
- `woi_pdf_get_html` — filter HTML before PDF render
- `woi_pdf_format_document_number` — override formatted document number
- `woi_pdf_document_attach_to_email_ids` — override email attachment targets
- `woi_pdf_api_permission_check` — override REST API access control

### Actions
- `woi_pdf_init` — plugin fully initialised
- `woi_pdf_init_documents` — document registry populated
- `woi_pdf_before_dompdf_render` — before PDF render (`$dompdf` passed)
- `woi_pdf_after_dompdf_render` — after PDF render (`$dompdf` passed)

---

## Testing

```bash
composer install
php vendor/bin/phpunit --no-coverage
```

16 tests, 30 assertions. PHPUnit 9 + Brain Monkey 2. No WordPress install required.

Test files live in `tests/Unit/`. The `phpunit.xml.dist` has `<directory>tests/Unit</directory>`.

**Bootstrap sequence:**
1. `define('ABSPATH', ...)` — must come first
2. `require vendor/autoload.php`
3. `require vendor/strauss/autoload.php`
4. `require woi-pdf-functions.php` — explicit require after ABSPATH defined

---

## Bugs fixed (post-MVP)

### 1. `set_number` PHP fatal (commit `93687e0`)

`NumberedDocumentInterface::set_number(int $number): void` conflicted with `OrderDocument::set_number($value, $order = null)` (no type, no return type). PHP 7.4+ rejects narrowing a parent's untyped parameter to `int` in a child.

**Fix:** Interface signature changed to `set_number($number, $order = null): void`. Return type `: void` added to `OrderDocument::set_number`. The four no-op overrides in subclasses (Invoice, CreditNote, Proforma, Receipt) that just called `parent::set_number(...)` were removed.

### 2. Silent PHPUnit exit (commit `93687e0`)

`woi-pdf-functions.php` was in `composer.json` `autoload.files`. Composer loads `$files` eagerly when `vendor/autoload.php` is required. PHPUnit requires `vendor/autoload.php` before loading the bootstrap (which defines `ABSPATH`), so `woi-pdf-functions.php` hit its `if (!defined('ABSPATH')) exit;` guard. PHP exited with code 0 and zero output — no error, no test output.

**Fix:** Removed from `autoload.files` in `composer.json`. `tests/bootstrap.php` now requires it explicitly after `define('ABSPATH', ...)`.

### 3. REST API blanket capability (commit `2135073`)

Original code used `current_user_can('edit_shop_orders')` (blanket) regardless of which order was being accessed. A user with restricted per-order access could bypass per-order checks.

**Fix:** Changed to `current_user_can('edit_shop_order', $order_id)` (singular, per-order meta-cap) with fallback to blanket check when `order_id` is zero/absent.

---

## tools/rename.ps1

Handles bulk identifier rename when porting files from source plugins. Uses `[string]::Replace()` (literal, case-sensitive, UTF-8 BOM-aware).

Covers: double-backslash namespace variants, single-backslash variants, bare namespace declarations, function name prefixes, option name prefixes, hook prefixes, handle prefixes.

**Does NOT handle text domain.** After running rename.ps1 on a ported file, manually fix all occurrences of the old text domain to `woocommerce-orders-invoice-pdf`.

---

## Development notes

- `OrderDocument.php` is ~1200 lines — do not split; it is a faithful port of the base plugin's core class
- `Settings.php` is similarly large — same rationale
- `WOI_PDF` class is in global namespace by design; WordPress singleton convention
- The `WOI_PDF()` function is also global namespace by design
- All other classes are in `WOI\PDF` or `WOI\PDF\Editor`
- Template overrides go in `wp-content/themes/{theme}/woocommerce-orders-invoice-pdf/{TemplateName}/{document-type}.php`
- WooCommerce admin URL: **WooCommerce → PDF Documents**
