# WooCommerce Orders Invoice PDF — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone WooCommerce plugin (slug `woocommerce-orders-invoice-pdf`) that merges the base PDF plugin, the Pro add-on, and the Template Editor into one deliverable with six document types, a full CRUD REST API, and a drag-and-drop template editor.

**Architecture:** Fork Path 4 (base plugin) as the foundation, apply a global namespace + identifier rename, then port Pro document classes (Path 1) and the column/totals editor (Path 2) into the same namespace. Two new classes — `DocumentRenderer` and `TemplateLoader` — centralise the Dompdf pipeline and template path resolution (DRY + SRP). All document types register via the `woi_pdf_document_classes` filter (OCP). WooCommerce is the only runtime dependency.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WooCommerce 3.3+, Dompdf ^2.0, Strauss (vendor namespace prefixing), PHPUnit ^9.5, Brain Monkey ^2.6 (WP function mocking), jQuery UI Sortable (editor drag-and-drop), Composer

---

## Source Reference Paths

| Alias | Absolute path |
|---|---|
| `[BASE]` | `C:\Users\asifm\source\repos\woocommerce-pdf-invoices-packing-slips` |
| `[PRO]` | `C:\Users\asifm\source\repos\woocommerce-pdf-ips-pro_v2.20.0\woocommerce-pdf-ips-pro` |
| `[TPL]` | `C:\Users\asifm\source\repos\woocommerce-pdf-ips-templates_v2.27.0\woocommerce-pdf-ips-templates` |
| `[TARGET]` | `C:\Users\asifm\source\repos\woocommerce-orders-invoice-pdf` |

---

## Global Rename Substitutions

Apply these **in order** to every PHP file copied from any source path. Later rules depend on earlier ones having already run.

```
WPO\WC\PDF_Invoices_Pro\   →  WOI\PDF\
WPO\WC\PDF_Invoices\       →  WOI\PDF\
WPO\WC\PDF_Invoices_Templates\  →  WOI\PDF\Editor\
WPO\IPS\                   →  WOI\PDF\
WPO_WCPDF_Pro()            →  WOI_PDF()
WPO_WCPDF()                →  WOI_PDF()
WPO_WCPDF_VERSION          →  WOI_PDF_VERSION
WPO_WCPDF_                 →  WOI_PDF_
WPO_WCPDF                  →  WOI_PDF
wpo_wcpdf_                 →  woi_pdf_
wpo_wcpdf                  →  woi_pdf
wpo-wcpdf                  →  woi-pdf
wcpdf_get_document(        →  woi_pdf_get_document(
wcpdf_filter_order_ids(    →  woi_pdf_filter_order_ids(
```

The PowerShell helper to run all substitutions on a single file:

```powershell
function Rename-WoiPdf($path) {
    $pairs = @(
        @('WPO\\WC\\PDF_Invoices_Pro\\',      'WOI\\PDF\\'),
        @('WPO\\WC\\PDF_Invoices\\',          'WOI\\PDF\\'),
        @('WPO\\WC\\PDF_Invoices_Templates\\','WOI\\PDF\\Editor\\'),
        @('WPO\\IPS\\',                        'WOI\\PDF\\'),
        @('WPO_WCPDF_Pro\(\)',                 'WOI_PDF()'),
        @('WPO_WCPDF\(\)',                     'WOI_PDF()'),
        @('WPO_WCPDF_VERSION',                 'WOI_PDF_VERSION'),
        @('WPO_WCPDF_',                        'WOI_PDF_'),
        @('WPO_WCPDF',                         'WOI_PDF'),
        @('wpo_wcpdf_',                        'woi_pdf_'),
        @('wpo_wcpdf',                         'woi_pdf'),
        @('wpo-wcpdf',                         'woi-pdf'),
        @('wcpdf_get_document\(',              'woi_pdf_get_document('),
        @('wcpdf_filter_order_ids\(',          'woi_pdf_filter_order_ids(')
    )
    $content = Get-Content $path -Raw
    foreach ($p in $pairs) { $content = $content -replace $p[0], $p[1] }
    Set-Content $path $content -NoNewline
}
# Usage: Rename-WoiPdf "[TARGET]\includes\Main.php"
# Batch: Get-ChildItem "[TARGET]\includes" -Recurse -Filter *.php | ForEach-Object { Rename-WoiPdf $_.FullName }
```

Save this function to `[TARGET]\tools\rename.ps1` for reuse across tasks.

---

## File Map

### New files (written from scratch)
```
[TARGET]/woocommerce-orders-invoice-pdf.php
[TARGET]/woi-pdf-functions.php
[TARGET]/composer.json
[TARGET]/phpunit.xml.dist
[TARGET]/tests/bootstrap.php
[TARGET]/tools/rename.ps1
[TARGET]/includes/Documents/DocumentInterface.php
[TARGET]/includes/Documents/NumberedDocumentInterface.php
[TARGET]/includes/Documents/EmailAttachableInterface.php
[TARGET]/includes/Documents/BulkDocumentInterface.php
[TARGET]/includes/DocumentRenderer.php
[TARGET]/includes/TemplateLoader.php
[TARGET]/includes/Editor/PriceStorage.php
[TARGET]/tests/Unit/Documents/DocumentInterfaceContractTest.php
[TARGET]/tests/Unit/Documents/DocumentNumberTest.php
[TARGET]/tests/Unit/Documents/SequentialNumberStoreTest.php
[TARGET]/tests/Unit/DocumentRendererTest.php
[TARGET]/tests/Unit/TemplateLoaderTest.php
[TARGET]/tests/Unit/RestTest.php
```

### Ported + renamed from [BASE]
```
[TARGET]/includes/Main.php
[TARGET]/includes/Admin.php
[TARGET]/includes/Assets.php
[TARGET]/includes/Documents.php
[TARGET]/includes/Endpoint.php
[TARGET]/includes/Frontend.php
[TARGET]/includes/Install.php
[TARGET]/includes/Settings.php
[TARGET]/includes/Semaphore.php
[TARGET]/includes/FontSynchronizer.php
[TARGET]/includes/Documents/OrderDocument.php      (+ implements DocumentInterface)
[TARGET]/includes/Documents/OrderDocumentMethods.php
[TARGET]/includes/Documents/DocumentNumber.php
[TARGET]/includes/Documents/SequentialNumberStore.php
[TARGET]/includes/Documents/BulkDocument.php       (+ implements BulkDocumentInterface)
[TARGET]/includes/Documents/Invoice.php            (+ implements NumberedDocumentInterface, EmailAttachableInterface)
[TARGET]/includes/Documents/PackingSlip.php        (+ implements EmailAttachableInterface)
[TARGET]/templates/Simple/
[TARGET]/assets/fonts/
[TARGET]/assets/images/
[TARGET]/assets/css/admin.css
[TARGET]/assets/js/admin.js
```

### Ported + renamed from [PRO]
```
[TARGET]/includes/Rest.php
[TARGET]/includes/Documents/Proforma.php           (extends OrderDocument, not Pro_Document)
[TARGET]/includes/Documents/CreditNote.php
[TARGET]/includes/Documents/Receipt.php
[TARGET]/includes/Documents/Summary.php            (extends BulkDocument)
[TARGET]/templates/Simple/proforma.php
[TARGET]/templates/Simple/credit-note.php
[TARGET]/templates/Simple/receipt.php
```

### Ported + renamed from [TPL]
```
[TARGET]/includes/Editor/EditorMain.php
[TARGET]/includes/Editor/EditorSettings.php
[TARGET]/assets/css/editor.css
[TARGET]/assets/js/editor.js
[TARGET]/templates/Simple Premium/
[TARGET]/templates/Modern/
[TARGET]/templates/Business/
```

---

## Task 1: Project Scaffold + Composer + PHPUnit

**Files:**
- Create: `[TARGET]/composer.json`
- Create: `[TARGET]/phpunit.xml.dist`
- Create: `[TARGET]/tests/bootstrap.php`
- Create: `[TARGET]/tools/rename.ps1`

- [ ] **Step 1.1 — Write `composer.json`**

```json
{
    "name": "wpo/woocommerce-orders-invoice-pdf",
    "description": "WooCommerce PDF invoices, packing slips and pro documents — standalone merged plugin",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=7.4",
        "dompdf/dompdf": "^2.0",
        "composer/installers": "^1.0 || ^2.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.5",
        "brain/monkey": "^2.6"
    },
    "autoload": {
        "psr-4": {
            "WOI\\PDF\\": "includes/"
        },
        "files": [
            "woi-pdf-functions.php"
        ]
    },
    "autoload-dev": {
        "psr-4": {
            "WOI\\PDF\\Tests\\": "tests/"
        }
    },
    "extra": {
        "strauss": {
            "target_directory": "vendor/strauss",
            "namespace_prefix": "WOI\\PDF\\Vendor\\",
            "classmap_prefix": "WOI_PDF_",
            "constant_prefix": "WOI_PDF_",
            "packages": [
                "dompdf/dompdf"
            ],
            "exclude_from_copy": {
                "file_patterns": ["/.*\\.txt/"]
            }
        }
    },
    "scripts": {
        "strauss": "vendor/bin/strauss",
        "test": "vendor/bin/phpunit"
    }
}
```

- [ ] **Step 1.2 — Write `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.5/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    verbose="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

- [ ] **Step 1.3 — Write `tests/bootstrap.php`**

```php
<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Brain Monkey needs no WP install — stubs are provided per test via Monkey\setUp()
```

- [ ] **Step 1.4 — Write `tools/rename.ps1`** (the PowerShell helper from the Global Rename section above — copy it verbatim)

- [ ] **Step 1.5 — Install dependencies**

```powershell
cd "[TARGET]"
composer install
composer exec strauss
```

Expected: `vendor/` created, `vendor/strauss/` created with `WOI\PDF\Vendor\Dompdf` prefix.

- [ ] **Step 1.6 — Verify PHPUnit runs**

```powershell
./vendor/bin/phpunit --version
```

Expected output: `PHPUnit 9.x.x`

- [ ] **Step 1.7 — Commit**

```powershell
git add composer.json phpunit.xml.dist tests/bootstrap.php tools/rename.ps1 vendor/
git commit -m "feat: project scaffold with Composer, PHPUnit, and Strauss"
```

---

## Task 2: Document Interfaces

**Files:**
- Create: `[TARGET]/includes/Documents/DocumentInterface.php`
- Create: `[TARGET]/includes/Documents/NumberedDocumentInterface.php`
- Create: `[TARGET]/includes/Documents/EmailAttachableInterface.php`
- Create: `[TARGET]/includes/Documents/BulkDocumentInterface.php`
- Create: `[TARGET]/tests/Unit/Documents/DocumentInterfaceContractTest.php`

- [ ] **Step 2.1 — Write the failing test**

`tests/Unit/Documents/DocumentInterfaceContractTest.php`:
```php
<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Documents\DocumentInterface;
use WOI\PDF\Documents\NumberedDocumentInterface;
use WOI\PDF\Documents\EmailAttachableInterface;
use WOI\PDF\Documents\BulkDocumentInterface;

class DocumentInterfaceContractTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_document_interface_declares_required_methods(): void {
        $methods = get_class_methods( DocumentInterface::class );
        $this->assertContains( 'get_type', $methods );
        $this->assertContains( 'get_title', $methods );
        $this->assertContains( 'is_enabled', $methods );
        $this->assertContains( 'exists', $methods );
        $this->assertContains( 'init', $methods );
        $this->assertContains( 'get_html', $methods );
        $this->assertContains( 'get_settings_fields', $methods );
        $this->assertContains( 'get_settings_option_name', $methods );
    }

    public function test_numbered_document_interface_extends_document_interface(): void {
        $parents = class_implements( NumberedDocumentInterface::class );
        $this->assertArrayHasKey( DocumentInterface::class, $parents );
    }

    public function test_numbered_document_interface_declares_required_methods(): void {
        $methods = get_class_methods( NumberedDocumentInterface::class );
        $this->assertContains( 'get_number', $methods );
        $this->assertContains( 'set_number', $methods );
        $this->assertContains( 'get_date', $methods );
        $this->assertContains( 'has_number', $methods );
    }

    public function test_email_attachable_interface_declares_required_methods(): void {
        $methods = get_class_methods( EmailAttachableInterface::class );
        $this->assertContains( 'get_attach_to_email_ids', $methods );
    }

    public function test_bulk_document_interface_extends_document_interface(): void {
        $parents = class_implements( BulkDocumentInterface::class );
        $this->assertArrayHasKey( DocumentInterface::class, $parents );
    }

    public function test_bulk_document_interface_declares_required_methods(): void {
        $methods = get_class_methods( BulkDocumentInterface::class );
        $this->assertContains( 'set_order_ids', $methods );
    }
}
```

- [ ] **Step 2.2 — Run test to verify it fails**

```powershell
./vendor/bin/phpunit tests/Unit/Documents/DocumentInterfaceContractTest.php --testdox
```

Expected: FAIL — class not found errors.

- [ ] **Step 2.3 — Create `includes/Documents/DocumentInterface.php`**

```php
<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

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
```

- [ ] **Step 2.4 — Create `includes/Documents/NumberedDocumentInterface.php`**

```php
<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

interface NumberedDocumentInterface extends DocumentInterface {
    public function get_number(): ?DocumentNumber;
    public function set_number( int $number ): void;
    public function get_date(): ?\WC_DateTime;
    public function has_number(): bool;
}
```

- [ ] **Step 2.5 — Create `includes/Documents/EmailAttachableInterface.php`**

```php
<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

interface EmailAttachableInterface {
    public function get_attach_to_email_ids(): array;
}
```

- [ ] **Step 2.6 — Create `includes/Documents/BulkDocumentInterface.php`**

```php
<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

interface BulkDocumentInterface extends DocumentInterface {
    public function set_order_ids( array $order_ids ): void;
}
```

- [ ] **Step 2.7 — Run test to verify it passes**

```powershell
./vendor/bin/phpunit tests/Unit/Documents/DocumentInterfaceContractTest.php --testdox
```

Expected: All 5 tests PASS.

- [ ] **Step 2.8 — Commit**

```powershell
git add includes/Documents/DocumentInterface.php includes/Documents/NumberedDocumentInterface.php includes/Documents/EmailAttachableInterface.php includes/Documents/BulkDocumentInterface.php tests/Unit/Documents/DocumentInterfaceContractTest.php
git commit -m "feat: document interface hierarchy (DocumentInterface, Numbered, EmailAttachable, Bulk)"
```

---

## Task 3: DocumentNumber + SequentialNumberStore

**Files:**
- Port: `[BASE]/includes/Documents/DocumentNumber.php` → `[TARGET]/includes/Documents/DocumentNumber.php`
- Port: `[BASE]/includes/Documents/SequentialNumberStore.php` → `[TARGET]/includes/Documents/SequentialNumberStore.php`
- Create: `[TARGET]/tests/Unit/Documents/DocumentNumberTest.php`

- [ ] **Step 3.1 — Write the failing test**

`tests/Unit/Documents/DocumentNumberTest.php`:
```php
<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Documents\DocumentNumber;

class DocumentNumberTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Stub the WP filter function so DocumentNumber construction doesn't die.
        Functions\when( 'apply_filters' )->returnArg( 1 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_number_formats_with_padding(): void {
        $number = new DocumentNumber( 5, [ 'padding' => 4, 'prefix' => '', 'suffix' => '' ] );
        $this->assertSame( '0005', $number->formatted_number );
    }

    public function test_number_formats_with_prefix_and_suffix(): void {
        $number = new DocumentNumber( 7, [ 'padding' => 1, 'prefix' => 'INV-', 'suffix' => '-2025' ] );
        $this->assertSame( 'INV-7-2025', $number->formatted_number );
    }

    public function test_number_is_null_when_empty(): void {
        $number = new DocumentNumber( null, [] );
        $this->assertNull( $number->number );
    }
}
```

- [ ] **Step 3.2 — Run test to verify it fails**

```powershell
./vendor/bin/phpunit tests/Unit/Documents/DocumentNumberTest.php --testdox
```

Expected: FAIL — `DocumentNumber` class not found.

- [ ] **Step 3.3 — Port and rename `DocumentNumber`**

```powershell
Copy-Item "[BASE]\includes\Documents\DocumentNumber.php" "[TARGET]\includes\Documents\DocumentNumber.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\DocumentNumber.php"
```

- [ ] **Step 3.4 — Port and rename `SequentialNumberStore`**

```powershell
Copy-Item "[BASE]\includes\Documents\SequentialNumberStore.php" "[TARGET]\includes\Documents\SequentialNumberStore.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\SequentialNumberStore.php"
```

`SequentialNumberStore` calls `woi_pdf_prepare_identifier_query()` (renamed from `wpo_wcpdf_prepare_identifier_query`). That global function lives in `woi-pdf-functions.php` which is created in Task 23. For now, add a stub at the top of `SequentialNumberStore.php` so tests don't fail:

```php
// Temporary stub — replaced by woi-pdf-functions.php in Task 23.
if ( ! function_exists( 'woi_pdf_prepare_identifier_query' ) ) {
    function woi_pdf_prepare_identifier_query( string $query, ...$args ): string {
        global $wpdb;
        return $wpdb ? $wpdb->prepare( $query, ...$args ) : $query;
    }
}
```

- [ ] **Step 3.5 — Run test to verify it passes**

```powershell
./vendor/bin/phpunit tests/Unit/Documents/DocumentNumberTest.php --testdox
```

Expected: 3 tests PASS.

- [ ] **Step 3.6 — Commit**

```powershell
git add includes/Documents/DocumentNumber.php includes/Documents/SequentialNumberStore.php tests/Unit/Documents/DocumentNumberTest.php
git commit -m "feat: port DocumentNumber and SequentialNumberStore with woi_pdf_ prefix"
```

---

## Task 4: OrderDocument + OrderDocumentMethods

**Files:**
- Port: `[BASE]/includes/Documents/OrderDocument.php` → `[TARGET]/includes/Documents/OrderDocument.php`
- Port: `[BASE]/includes/Documents/OrderDocumentMethods.php` → `[TARGET]/includes/Documents/OrderDocumentMethods.php`

Both files use the `WPO\IPS\Documents` namespace and reference `WPO_WCPDF()`. Apply the rename substitutions then add interface declarations.

- [ ] **Step 4.1 — Port and rename `OrderDocument`**

```powershell
Copy-Item "[BASE]\includes\Documents\OrderDocument.php" "[TARGET]\includes\Documents\OrderDocument.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\OrderDocument.php"
```

- [ ] **Step 4.2 — Add interface declaration to `OrderDocument`**

Open `[TARGET]/includes/Documents/OrderDocument.php`. Change the class declaration from:

```php
abstract class OrderDocument {
```

to:

```php
abstract class OrderDocument implements DocumentInterface {
```

Add the `use` statement after the `namespace` line:

```php
use WOI\PDF\Documents\DocumentInterface;
```

Then add the two required `DocumentInterface` methods that aren't already in the base class (add at the end of the class, before the closing `}`):

```php
public function get_settings_fields(): array {
    return apply_filters( 'woi_pdf_document_settings_fields', array(), $this );
}

public function get_settings_option_name(): string {
    return 'woi_pdf_settings_' . $this->type;
}
```

- [ ] **Step 4.3 — Port and rename `OrderDocumentMethods`**

```powershell
Copy-Item "[BASE]\includes\Documents\OrderDocumentMethods.php" "[TARGET]\includes\Documents\OrderDocumentMethods.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\OrderDocumentMethods.php"
```

- [ ] **Step 4.4 — Verify PHP syntax**

```powershell
php -l "[TARGET]\includes\Documents\OrderDocument.php"
php -l "[TARGET]\includes\Documents\OrderDocumentMethods.php"
```

Expected: `No syntax errors detected` for both files.

- [ ] **Step 4.5 — Commit**

```powershell
git add includes/Documents/OrderDocument.php includes/Documents/OrderDocumentMethods.php
git commit -m "feat: port OrderDocument + OrderDocumentMethods, implement DocumentInterface"
```

---

## Task 5: TemplateLoader + DocumentRenderer

**Files:**
- Create: `[TARGET]/includes/TemplateLoader.php`
- Create: `[TARGET]/includes/DocumentRenderer.php`
- Create: `[TARGET]/tests/Unit/TemplateLoaderTest.php`
- Create: `[TARGET]/tests/Unit/DocumentRendererTest.php`

These are new classes that did not exist in any source plugin. They centralise concerns that were previously scattered.

- [ ] **Step 5.1 — Write failing test for `TemplateLoader`**

`tests/Unit/TemplateLoaderTest.php`:
```php
<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\TemplateLoader;

class TemplateLoaderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_locate_returns_plugin_template_when_no_theme_override(): void {
        $plugin_path = dirname( __DIR__, 2 ); // points to [TARGET]
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'apply_filters' )->returnArg( 1 );
        Functions\when( 'trailingslashit' )->alias( fn( $s ) => rtrim( $s, '/\\' ) . '/' );

        $loader = new TemplateLoader( $plugin_path );
        $result = $loader->locate( 'invoice', 'invoice.php', 'Simple' );

        $this->assertStringContainsString( 'Simple', $result );
        $this->assertStringContainsString( 'invoice.php', $result );
    }

    public function test_locate_returns_empty_string_for_unknown_template(): void {
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'apply_filters' )->returnArg( 1 );
        Functions\when( 'trailingslashit' )->alias( fn( $s ) => rtrim( $s, '/\\' ) . '/' );

        $loader = new TemplateLoader( '/nonexistent' );
        $result = $loader->locate( 'invoice', 'invoice.php', 'Simple' );

        $this->assertSame( '', $result );
    }
}
```

- [ ] **Step 5.2 — Write failing test for `DocumentRenderer`**

`tests/Unit/DocumentRendererTest.php`:
```php
<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\DocumentRenderer;

class DocumentRendererTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_renderer_instantiates(): void {
        $renderer = new DocumentRenderer();
        $this->assertInstanceOf( DocumentRenderer::class, $renderer );
    }

    public function test_get_output_modes_returns_array(): void {
        $renderer = new DocumentRenderer();
        $modes = $renderer->get_output_modes();
        $this->assertIsArray( $modes );
        $this->assertContains( 'download', $modes );
        $this->assertContains( 'inline', $modes );
        $this->assertContains( 'base64', $modes );
    }
}
```

- [ ] **Step 5.3 — Run tests to verify they fail**

```powershell
./vendor/bin/phpunit tests/Unit/TemplateLoaderTest.php tests/Unit/DocumentRendererTest.php --testdox
```

Expected: FAIL — class not found.

- [ ] **Step 5.4 — Create `includes/TemplateLoader.php`**

```php
<?php
namespace WOI\PDF;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\TemplateLoader' ) ) :

class TemplateLoader {

    private string $plugin_path;

    public function __construct( string $plugin_path ) {
        $this->plugin_path = $plugin_path;
    }

    /**
     * Locate the template file for a given document type, template name, and template folder.
     * Checks the active theme first, then falls back to the plugin's templates/ directory.
     *
     * @param string $document_type  e.g. 'invoice'
     * @param string $template_name  e.g. 'invoice.php'
     * @param string $template_folder e.g. 'Simple'
     * @return string Absolute file path, or empty string if not found.
     */
    public function locate( string $document_type, string $template_name, string $template_folder ): string {
        $template_folder = apply_filters( 'woi_pdf_template_folder', $template_folder, $document_type );
        $template_name   = apply_filters( 'woi_pdf_template_name', $template_name, $document_type );

        // 1. Theme override: {theme}/woocommerce-orders-invoice-pdf/{folder}/{file}
        $theme_path = locate_template( array(
            trailingslashit( 'woocommerce-orders-invoice-pdf/' . $template_folder ) . $template_name,
        ) );

        if ( $theme_path ) {
            return apply_filters( 'woi_pdf_template_path', $theme_path, $document_type, $template_name );
        }

        // 2. Plugin templates/ directory
        $plugin_template = trailingslashit( $this->plugin_path . '/templates/' . $template_folder ) . $template_name;

        if ( file_exists( $plugin_template ) ) {
            return apply_filters( 'woi_pdf_template_path', $plugin_template, $document_type, $template_name );
        }

        return '';
    }
}

endif;
```

- [ ] **Step 5.5 — Create `includes/DocumentRenderer.php`**

```php
<?php
namespace WOI\PDF;

use WOI\PDF\Vendor\Dompdf\Dompdf;
use WOI\PDF\Vendor\Dompdf\Options;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\DocumentRenderer' ) ) :

class DocumentRenderer {

    /** @var string[] Valid output modes */
    private array $output_modes = array( 'download', 'inline', 'base64', 'save' );

    public function get_output_modes(): array {
        return $this->output_modes;
    }

    /**
     * Render HTML to a PDF binary string via Dompdf.
     *
     * @param string $html     Full HTML document string.
     * @param array  $options  Optional Dompdf options (paper size, orientation, etc.).
     * @return string Raw PDF binary.
     */
    public function render( string $html, array $options = array() ): string {
        $dompdf_options = new Options();
        $dompdf_options->set( 'defaultFont', 'open-sans' );
        $dompdf_options->set( 'isRemoteEnabled', apply_filters( 'woi_pdf_dompdf_remote_enabled', true ) );
        $dompdf_options->set( 'tempDir', apply_filters( 'woi_pdf_tmp_path', get_temp_dir() ) );

        foreach ( $options as $key => $value ) {
            $dompdf_options->set( $key, $value );
        }

        $dompdf = new Dompdf( $dompdf_options );
        $dompdf->setPaper(
            apply_filters( 'woi_pdf_paper_size', 'A4' ),
            apply_filters( 'woi_pdf_paper_orientation', 'portrait' )
        );

        $dompdf->loadHtml( apply_filters( 'woi_pdf_get_html', $html ) );

        do_action( 'woi_pdf_before_dompdf_render', $dompdf );
        $dompdf->render();
        do_action( 'woi_pdf_after_dompdf_render', $dompdf );

        return $dompdf->output();
    }

    /**
     * Stream PDF to the browser.
     *
     * @param string $pdf      Raw PDF binary from render().
     * @param string $filename Suggested filename for download.
     * @param string $mode     'download' or 'inline'.
     */
    public function stream( string $pdf, string $filename, string $mode = 'download' ): void {
        $disposition = ( 'inline' === $mode ) ? 'inline' : 'attachment';
        header( 'Content-Type: application/pdf' );
        header( sprintf( 'Content-Disposition: %s; filename="%s"', $disposition, sanitize_file_name( $filename ) ) );
        header( 'Content-Length: ' . strlen( $pdf ) );
        header( 'Cache-Control: private, max-age=0, must-revalidate' );
        header( 'Pragma: public' );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Save PDF to a temp file and return the path.
     *
     * @param string $pdf      Raw PDF binary from render().
     * @param string $filename Desired filename (without path).
     * @return string Absolute path to saved file.
     */
    public function save_temp( string $pdf, string $filename ): string {
        $tmp_dir = apply_filters( 'woi_pdf_tmp_path', get_temp_dir() . 'woi-pdf/' );

        if ( ! file_exists( $tmp_dir ) ) {
            wp_mkdir_p( $tmp_dir );
        }

        $path = trailingslashit( $tmp_dir ) . sanitize_file_name( $filename );
        file_put_contents( $path, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions

        return $path;
    }

    /**
     * Return PDF as a base64-encoded string (for REST API responses).
     *
     * @param string $pdf Raw PDF binary from render().
     * @return string Base64-encoded PDF.
     */
    public function to_base64( string $pdf ): string {
        return base64_encode( $pdf ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
    }
}

endif;
```

- [ ] **Step 5.6 — Run tests to verify they pass**

```powershell
./vendor/bin/phpunit tests/Unit/TemplateLoaderTest.php tests/Unit/DocumentRendererTest.php --testdox
```

Expected: All 4 tests PASS.

- [ ] **Step 5.7 — Commit**

```powershell
git add includes/TemplateLoader.php includes/DocumentRenderer.php tests/Unit/TemplateLoaderTest.php tests/Unit/DocumentRendererTest.php
git commit -m "feat: TemplateLoader and DocumentRenderer — centralised template resolution and Dompdf pipeline"
```

---

## Task 6: Base Document Types — Invoice + PackingSlip + Simple Template

**Files:**
- Port: `[BASE]/includes/Documents/BulkDocument.php` → `[TARGET]/includes/Documents/BulkDocument.php`
- Port: `[BASE]/includes/Documents/Invoice.php` → `[TARGET]/includes/Documents/Invoice.php`
- Port: `[BASE]/includes/Documents/PackingSlip.php` → `[TARGET]/includes/Documents/PackingSlip.php`
- Copy: `[BASE]/templates/Simple/` → `[TARGET]/templates/Simple/`

- [ ] **Step 6.1 — Port and rename `BulkDocument`**

```powershell
Copy-Item "[BASE]\includes\Documents\BulkDocument.php" "[TARGET]\includes\Documents\BulkDocument.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\BulkDocument.php"
```

Add `implements BulkDocumentInterface` to the class declaration and add the `use` import:

Open `[TARGET]/includes/Documents/BulkDocument.php` and change:
```php
// After namespace line, add:
use WOI\PDF\Documents\BulkDocumentInterface;

// Change class declaration:
abstract class BulkDocument extends OrderDocument implements BulkDocumentInterface {
```

- [ ] **Step 6.2 — Port and rename `Invoice`**

```powershell
Copy-Item "[BASE]\includes\Documents\Invoice.php" "[TARGET]\includes\Documents\Invoice.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\Invoice.php"
```

Add interface declarations to `Invoice`. Open the file and change:
```php
// After namespace line, add:
use WOI\PDF\Documents\NumberedDocumentInterface;
use WOI\PDF\Documents\EmailAttachableInterface;

// Change class declaration:
class Invoice extends OrderDocumentMethods implements NumberedDocumentInterface, EmailAttachableInterface {
```

Add the `get_attach_to_email_ids()` method (from `EmailAttachableInterface`) — check if `Invoice` already has an equivalent method (look for `attach_to_email_ids` in the source). If it does, rename it; if not, add:

```php
public function get_attach_to_email_ids(): array {
    $attach_to = isset( $this->settings['attach_to_email_ids'] ) ? (array) $this->settings['attach_to_email_ids'] : array();
    return apply_filters( 'woi_pdf_document_attach_to_email_ids', $attach_to, $this );
}
```

- [ ] **Step 6.3 — Port and rename `PackingSlip`**

```powershell
Copy-Item "[BASE]\includes\Documents\PackingSlip.php" "[TARGET]\includes\Documents\PackingSlip.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\PackingSlip.php"
```

Add `EmailAttachableInterface` to `PackingSlip` the same way as Invoice (Step 6.2), but without `NumberedDocumentInterface`.

- [ ] **Step 6.4 — Copy Simple template**

```powershell
Copy-Item "[BASE]\templates\Simple" "[TARGET]\templates\Simple" -Recurse
```

Apply renames to all template PHP files:
```powershell
. "[TARGET]\tools\rename.ps1"
Get-ChildItem "[TARGET]\templates\Simple" -Filter *.php | ForEach-Object { Rename-WoiPdf $_.FullName }
```

- [ ] **Step 6.5 — Verify PHP syntax on all ported files**

```powershell
@(
    "[TARGET]\includes\Documents\BulkDocument.php",
    "[TARGET]\includes\Documents\Invoice.php",
    "[TARGET]\includes\Documents\PackingSlip.php"
) | ForEach-Object { php -l $_ }
```

Expected: `No syntax errors detected` for each file.

- [ ] **Step 6.6 — Commit**

```powershell
git add includes/Documents/BulkDocument.php includes/Documents/Invoice.php includes/Documents/PackingSlip.php templates/Simple/
git commit -m "feat: port BulkDocument, Invoice, PackingSlip + Simple template"
```

---

## Task 7: Pro Document Types — Proforma, CreditNote, Receipt, Summary

**Files:**
- Port: `[PRO]/includes/documents/class-wcpdf-proforma.php` → `[TARGET]/includes/Documents/Proforma.php`
- Port: `[PRO]/includes/documents/class-wcpdf-credit-note.php` → `[TARGET]/includes/Documents/CreditNote.php`
- Port: `[PRO]/includes/documents/class-wcpdf-receipt.php` → `[TARGET]/includes/Documents/Receipt.php`
- Port: `[PRO]/includes/documents/class-wcpdf-summary.php` → `[TARGET]/includes/Documents/Summary.php`
- Copy: `[PRO]/templates/Simple/proforma.php` → `[TARGET]/templates/Simple/proforma.php`
- Copy: `[PRO]/templates/Simple/credit-note.php` → `[TARGET]/templates/Simple/credit-note.php`
- Copy: `[PRO]/templates/Simple/receipt.php` → `[TARGET]/templates/Simple/receipt.php`

**Important:** Pro document classes extend `Pro_Document` (the abstract in `[PRO]/includes/documents/abstract-wcpdf-pro-document.php`). In the merged plugin there is no `Pro_Document` — they must extend `OrderDocumentMethods` directly. After porting each file, check for `extends Pro_Document` and replace with `extends OrderDocumentMethods`.

- [ ] **Step 7.1 — Port and rename `Proforma`**

```powershell
Copy-Item "[PRO]\includes\documents\class-wcpdf-proforma.php" "[TARGET]\includes\Documents\Proforma.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\Proforma.php"
```

Open `[TARGET]/includes/Documents/Proforma.php`:
- Change `extends Pro_Document` → `extends OrderDocumentMethods`
- Add `use WOI\PDF\Documents\NumberedDocumentInterface;` and `use WOI\PDF\Documents\EmailAttachableInterface;`
- Change class declaration: `class Proforma extends OrderDocumentMethods implements NumberedDocumentInterface, EmailAttachableInterface {`
- Remove any reference to `WOI_PDF()->pro` (Pro singleton no longer exists; use `WOI_PDF()` directly)
- Replace icon reference: `WOI_PDF()->plugin_url() . '/assets/images/proforma.svg'`
- Add `get_attach_to_email_ids()` if not already present (same implementation as Invoice Step 6.2)

- [ ] **Step 7.2 — Port and rename `CreditNote`**

```powershell
Copy-Item "[PRO]\includes\documents\class-wcpdf-credit-note.php" "[TARGET]\includes\Documents\CreditNote.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\CreditNote.php"
```

Apply same base-class and interface changes as Step 7.1. Additionally `CreditNote` may reference `WOI_PDF()->emails` (Pro email class) — remove or stub those references for MVP.

- [ ] **Step 7.3 — Port and rename `Receipt`**

```powershell
Copy-Item "[PRO]\includes\documents\class-wcpdf-receipt.php" "[TARGET]\includes\Documents\Receipt.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\Receipt.php"
```

Apply same changes as Steps 7.1–7.2.

- [ ] **Step 7.4 — Port and rename `Summary`**

```powershell
Copy-Item "[PRO]\includes\documents\class-wcpdf-summary.php" "[TARGET]\includes\Documents\Summary.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents\Summary.php"
```

`Summary` is a bulk document — change `extends Pro_Document` → `extends BulkDocument`. It does not implement `NumberedDocumentInterface` or `EmailAttachableInterface`.

- [ ] **Step 7.5 — Copy Pro templates for Simple folder**

```powershell
Copy-Item "[PRO]\templates\Simple\proforma.php"    "[TARGET]\templates\Simple\proforma.php"
Copy-Item "[PRO]\templates\Simple\credit-note.php" "[TARGET]\templates\Simple\credit-note.php"
Copy-Item "[PRO]\templates\Simple\receipt.php"     "[TARGET]\templates\Simple\receipt.php"
. "[TARGET]\tools\rename.ps1"
Get-ChildItem "[TARGET]\templates\Simple" -Filter *.php | ForEach-Object { Rename-WoiPdf $_.FullName }
```

- [ ] **Step 7.6 — Verify PHP syntax on all Pro document files**

```powershell
@(
    "[TARGET]\includes\Documents\Proforma.php",
    "[TARGET]\includes\Documents\CreditNote.php",
    "[TARGET]\includes\Documents\Receipt.php",
    "[TARGET]\includes\Documents\Summary.php"
) | ForEach-Object { php -l $_ }
```

Expected: `No syntax errors detected` for each.

- [ ] **Step 7.7 — Commit**

```powershell
git add includes/Documents/Proforma.php includes/Documents/CreditNote.php includes/Documents/Receipt.php includes/Documents/Summary.php templates/Simple/
git commit -m "feat: port Pro document types (Proforma, CreditNote, Receipt, Summary)"
```

---

## Task 8: Documents Registry + Install + Semaphore + FontSynchronizer

**Files:**
- Port: `[BASE]/includes/Documents.php` → `[TARGET]/includes/Documents.php`
- Port: `[BASE]/includes/Install.php` → `[TARGET]/includes/Install.php`
- Port: `[BASE]/includes/Semaphore.php` → `[TARGET]/includes/Semaphore.php`
- Port: `[BASE]/includes/FontSynchronizer.php` → `[TARGET]/includes/FontSynchronizer.php`
- Copy: `[BASE]/assets/fonts/` → `[TARGET]/assets/fonts/`

- [ ] **Step 8.1 — Port and rename `Documents` registry**

```powershell
Copy-Item "[BASE]\includes\Documents.php" "[TARGET]\includes\Documents.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Documents.php"
```

Open `[TARGET]/includes/Documents.php`. The `init()` method currently registers only Invoice and PackingSlip. Add the four Pro documents:

```php
public function init() {
    $this->documents[ Invoice::class ]     = new Invoice();
    $this->documents[ PackingSlip::class ] = new PackingSlip();
    $this->documents[ Proforma::class ]    = new Proforma();
    $this->documents[ CreditNote::class ]  = new CreditNote();
    $this->documents[ Receipt::class ]     = new Receipt();
    $this->documents[ Summary::class ]     = new Summary();

    $this->documents = apply_filters( 'woi_pdf_document_classes', $this->documents );

    do_action( 'woi_pdf_init_documents' );
}
```

Add the required `use` imports at the top of the file:
```php
use WOI\PDF\Documents\Invoice;
use WOI\PDF\Documents\PackingSlip;
use WOI\PDF\Documents\Proforma;
use WOI\PDF\Documents\CreditNote;
use WOI\PDF\Documents\Receipt;
use WOI\PDF\Documents\Summary;
```

- [ ] **Step 8.2 — Port and rename `Install`, `Semaphore`, `FontSynchronizer`**

```powershell
@(
    @{ Src="[BASE]\includes\Install.php";          Dst="[TARGET]\includes\Install.php" },
    @{ Src="[BASE]\includes\Semaphore.php";        Dst="[TARGET]\includes\Semaphore.php" },
    @{ Src="[BASE]\includes\FontSynchronizer.php"; Dst="[TARGET]\includes\FontSynchronizer.php" }
) | ForEach-Object {
    Copy-Item $_.Src $_.Dst
    . "[TARGET]\tools\rename.ps1"
    Rename-WoiPdf $_.Dst
}
```

- [ ] **Step 8.3 — Copy fonts**

```powershell
Copy-Item "[BASE]\assets\fonts" "[TARGET]\assets\fonts" -Recurse
```

- [ ] **Step 8.4 — Verify PHP syntax**

```powershell
@(
    "[TARGET]\includes\Documents.php",
    "[TARGET]\includes\Install.php",
    "[TARGET]\includes\Semaphore.php",
    "[TARGET]\includes\FontSynchronizer.php"
) | ForEach-Object { php -l $_ }
```

- [ ] **Step 8.5 — Commit**

```powershell
git add includes/Documents.php includes/Install.php includes/Semaphore.php includes/FontSynchronizer.php assets/fonts/
git commit -m "feat: Documents registry (all 6 types), Install, Semaphore, FontSynchronizer"
```

---

## Task 9: Settings Framework

**Files:**
- Port: `[BASE]/includes/Settings.php` → `[TARGET]/includes/Settings.php`

- [ ] **Step 9.1 — Port and rename `Settings`**

```powershell
Copy-Item "[BASE]\includes\Settings.php" "[TARGET]\includes\Settings.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Settings.php"
```

- [ ] **Step 9.2 — Add `register_document_tab()` method for OCP compliance**

Open `[TARGET]/includes/Settings.php` and add the following method. This is new code — it did not exist in the source. Find the end of the class body and insert before the closing `}`:

```php
/**
 * Register a settings tab for a document using its own declared fields.
 * Called from the plugin entry point for each registered document type.
 *
 * @param \WOI\PDF\Documents\DocumentInterface $document
 */
public function register_document_tab( \WOI\PDF\Documents\DocumentInterface $document ): void {
    $tab_key = 'woi_pdf_' . $document->get_type();

    add_filter( 'woi_pdf_settings_tabs', function( array $tabs ) use ( $document, $tab_key ): array {
        $tabs[ $tab_key ] = $document->get_title();
        return $tabs;
    } );

    add_action( 'admin_init', function() use ( $document ): void {
        $option = $document->get_settings_option_name();
        register_setting( $option, $option );

        foreach ( $document->get_settings_fields() as $field ) {
            if ( isset( $field['section'], $field['id'], $field['title'] ) ) {
                add_settings_field(
                    $field['id'],
                    $field['title'],
                    $field['callback'] ?? '__return_null',
                    $option,
                    $field['section'],
                    $field['args'] ?? array()
                );
            }
        }
    } );
}
```

- [ ] **Step 9.3 — Verify PHP syntax**

```powershell
php -l "[TARGET]\includes\Settings.php"
```

- [ ] **Step 9.4 — Commit**

```powershell
git add includes/Settings.php
git commit -m "feat: port Settings framework + add register_document_tab() for OCP document tabs"
```

---

## Task 10: Endpoint + Main

**Files:**
- Port: `[BASE]/includes/Endpoint.php` → `[TARGET]/includes/Endpoint.php`
- Port: `[BASE]/includes/Main.php` → `[TARGET]/includes/Main.php`

- [ ] **Step 10.1 — Port and rename `Endpoint`**

```powershell
Copy-Item "[BASE]\includes\Endpoint.php" "[TARGET]\includes\Endpoint.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Endpoint.php"
```

The Endpoint class uses `$this->action_suffix = '_wpo_wcpdf'`. Rename this suffix to `'_woi_pdf'`:

Open `[TARGET]/includes/Endpoint.php` and change:
```php
public $action_suffix = '_woi_pdf';
```

Also update `get_identifier()` to return `'woi_pdf'` instead of `'wcpdf'`:
```php
public function get_identifier() {
    return apply_filters( 'woi_pdf_pretty_document_link_identifier', 'woi_pdf' );
}
```

- [ ] **Step 10.2 — Port and rename `Main`**

```powershell
Copy-Item "[BASE]\includes\Main.php" "[TARGET]\includes\Main.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Main.php"
```

Open `[TARGET]/includes/Main.php` and add a reference to `DocumentRenderer` in the `attach_document_to_email` method. Find where the source creates a PDF for attachment (look for `get_pdf()` or Dompdf calls) and ensure it goes through `WOI_PDF()->renderer`:

```php
// In attach_document_to_email(), replace any direct Dompdf calls with:
$pdf  = WOI_PDF()->renderer->render( $document->get_html() );
$path = WOI_PDF()->renderer->save_temp( $pdf, $document->get_filename() );
```

- [ ] **Step 10.3 — Verify PHP syntax**

```powershell
php -l "[TARGET]\includes\Endpoint.php"
php -l "[TARGET]\includes\Main.php"
```

- [ ] **Step 10.4 — Commit**

```powershell
git add includes/Endpoint.php includes/Main.php
git commit -m "feat: port Endpoint and Main; Main uses DocumentRenderer for email attachment"
```

---

## Task 11: REST API

**Files:**
- Port: `[PRO]/includes/wcpdf-pro-rest.php` → `[TARGET]/includes/Rest.php`
- Create: `[TARGET]/tests/Unit/RestTest.php`

- [ ] **Step 11.1 — Write failing test**

`tests/Unit/RestTest.php`:
```php
<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class RestTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_rest_instantiates(): void {
        Functions\when( 'register_rest_field' )->justReturn( null );
        Functions\when( 'register_rest_route' )->justReturn( null );
        // Settings check — simulate REST disabled so constructor returns early.
        Functions\when( 'get_option' )->justReturn( array() );
        $rest = new Rest();
        $this->assertInstanceOf( Rest::class, $rest );
    }

    public function test_rest_namespace_is_wc_v3(): void {
        $rest = new Rest();
        $reflection = new \ReflectionProperty( Rest::class, 'namespace' );
        $reflection->setAccessible( true );
        $this->assertSame( 'wc/v3', $reflection->getValue( $rest ) );
    }

    public function test_rest_base_is_orders(): void {
        $rest = new Rest();
        $reflection = new \ReflectionProperty( Rest::class, 'rest_base' );
        $reflection->setAccessible( true );
        $this->assertSame( 'orders', $reflection->getValue( $rest ) );
    }
}
```

- [ ] **Step 11.2 — Run test to verify it fails**

```powershell
./vendor/bin/phpunit tests/Unit/RestTest.php --testdox
```

Expected: FAIL — `Rest` class not found.

- [ ] **Step 11.3 — Port and rename `Rest`**

```powershell
Copy-Item "[PRO]\includes\wcpdf-pro-rest.php" "[TARGET]\includes\Rest.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Rest.php"
```

Open `[TARGET]/includes/Rest.php`:

1. The constructor checks `WOI_PDF()->settings->debug_settings['enable_rest_api']`. Ensure the option key is `woi_pdf_settings_debug` (the rename will have handled `wpo_wcpdf_settings_debug`).

2. Remove any reference to `WOI_PDF_Pro()->dependencies->is_rest_api_supported()` — replace with a direct WordPress version check:

```php
private function is_rest_api_supported(): bool {
    return function_exists( 'register_rest_route' ) && version_compare( get_bloginfo( 'version' ), '5.4', '>=' );
}
```

3. In `order_get_callback()`, replace `WOI_PDF_Pro()->functions->get_document_type_options()` with a call to the Documents registry:

```php
$document_types = array_keys( WOI_PDF()->documents->get_documents( 'all' ) );
```

4. The three handler methods (`handle_document_request`, `download_document`, `delete_document`) use `wcpdf_get_document()` — after rename this becomes `woi_pdf_get_document()`. The rename script will have handled this.

- [ ] **Step 11.4 — Run test to verify it passes**

```powershell
./vendor/bin/phpunit tests/Unit/RestTest.php --testdox
```

Expected: All 3 tests PASS.

- [ ] **Step 11.5 — Commit**

```powershell
git add includes/Rest.php tests/Unit/RestTest.php
git commit -m "feat: port REST API (full CRUD GET/POST/DELETE wc/v3/orders/{id}/documents)"
```

---

## Task 12: Admin + Frontend + Assets

**Files:**
- Port: `[BASE]/includes/Admin.php` → `[TARGET]/includes/Admin.php`
- Port: `[BASE]/includes/Frontend.php` → `[TARGET]/includes/Frontend.php`
- Port: `[BASE]/includes/Assets.php` → `[TARGET]/includes/Assets.php`
- Copy: `[BASE]/assets/css/order-styles.css` → `[TARGET]/assets/css/admin.css`
- Copy: `[BASE]/assets/js/admin-script.js` → `[TARGET]/assets/js/admin.js`
- Copy: `[BASE]/assets/images/` → `[TARGET]/assets/images/`

- [ ] **Step 12.1 — Port and rename Admin, Frontend, Assets**

```powershell
@(
    @{ Src="[BASE]\includes\Admin.php";    Dst="[TARGET]\includes\Admin.php" },
    @{ Src="[BASE]\includes\Frontend.php"; Dst="[TARGET]\includes\Frontend.php" },
    @{ Src="[BASE]\includes\Assets.php";   Dst="[TARGET]\includes\Assets.php" }
) | ForEach-Object {
    Copy-Item $_.Src $_.Dst
    . "[TARGET]\tools\rename.ps1"
    Rename-WoiPdf $_.Dst
}
```

- [ ] **Step 12.2 — Copy asset files**

```powershell
Copy-Item "[BASE]\assets\css\order-styles.css" "[TARGET]\assets\css\admin.css"
Copy-Item "[BASE]\assets\js\admin-script.js"   "[TARGET]\assets\js\admin.js"
Copy-Item "[BASE]\assets\images"               "[TARGET]\assets\images" -Recurse
```

- [ ] **Step 12.3 — Update asset handles in `Assets.php`**

Open `[TARGET]/includes/Assets.php`. Find all `wp_enqueue_script`/`wp_enqueue_style` calls and update the handle names to use `woi-pdf-` prefix (the rename script changes `wpo-wcpdf-` to `woi-pdf-`). Also update the file path references that point to the renamed CSS/JS files (e.g., `order-styles.css` → `admin.css`, `admin-script.js` → `admin.js`).

- [ ] **Step 12.4 — Verify PHP syntax**

```powershell
@(
    "[TARGET]\includes\Admin.php",
    "[TARGET]\includes\Frontend.php",
    "[TARGET]\includes\Assets.php"
) | ForEach-Object { php -l $_ }
```

- [ ] **Step 12.5 — Commit**

```powershell
git add includes/Admin.php includes/Frontend.php includes/Assets.php assets/css/admin.css assets/js/admin.js assets/images/
git commit -m "feat: port Admin, Frontend, Assets with renamed handles and paths"
```

---

## Task 13: Editor — PriceStorage

**Files:**
- Create: `[TARGET]/includes/Editor/PriceStorage.php`

`PriceStorage` is a new class extracted from `EditorMain` (which in Path 2 saves regular item prices and tax rates at checkout mixed in with template block logic — an SRP violation). This class owns only the checkout meta-saving concern.

- [ ] **Step 13.1 — Create `includes/Editor/PriceStorage.php`**

```php
<?php
namespace WOI\PDF\Editor;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Editor\\PriceStorage' ) ) :

class PriceStorage {

    protected static ?self $_instance = null;

    public static function instance(): self {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_regular_item_price' ), 10, 2 );
        add_filter( 'woocommerce_hidden_order_itemmeta',    array( $this, 'hide_regular_price_itemmeta' ) );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_tax_rate_percentage' ), 10, 2 );
        add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'save_tax_rate_on_recalculate' ), 10, 2 );
    }

    /**
     * Save the pre-discount (regular) unit price against each order line item.
     * This allows templates to show original vs sale price.
     *
     * @param int      $order_id
     * @param array    $posted_data
     */
    public function save_regular_item_price( int $order_id, array $posted_data ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            $regular_price = $product->get_regular_price();
            if ( '' !== $regular_price ) {
                wc_update_order_item_meta( $item_id, '_woi_pdf_regular_price', $regular_price );
            }
        }
    }

    /**
     * Hide the stored regular price meta from WooCommerce order item meta display.
     *
     * @param array $hidden
     * @return array
     */
    public function hide_regular_price_itemmeta( array $hidden ): array {
        $hidden[] = '_woi_pdf_regular_price';
        $hidden[] = '_woi_pdf_tax_rate_percentage';
        return $hidden;
    }

    /**
     * Save the effective tax rate percentage against each tax line item at checkout.
     *
     * @param int   $order_id
     * @param array $posted_data
     */
    public function save_tax_rate_percentage( int $order_id, array $posted_data ): void {
        $this->store_tax_rate_percentage( wc_get_order( $order_id ) );
    }

    /**
     * Re-save tax rate percentages when order totals are recalculated in admin.
     *
     * @param bool      $and_taxes
     * @param \WC_Order $order
     */
    public function save_tax_rate_on_recalculate( bool $and_taxes, \WC_Order $order ): void {
        if ( $and_taxes ) {
            $this->store_tax_rate_percentage( $order );
        }
    }

    private function store_tax_rate_percentage( ?\WC_Order $order ): void {
        if ( ! $order ) {
            return;
        }
        foreach ( $order->get_taxes() as $item_id => $tax_item ) {
            $rate_id    = $tax_item->get_rate_id();
            $rate       = \WC_Tax::_get_tax_rate( $rate_id );
            $percentage = isset( $rate['tax_rate'] ) ? (float) $rate['tax_rate'] : null;
            if ( null !== $percentage ) {
                wc_update_order_item_meta( $item_id, '_woi_pdf_tax_rate_percentage', $percentage );
            }
        }
    }
}

endif;
```

- [ ] **Step 13.2 — Verify PHP syntax**

```powershell
php -l "[TARGET]\includes\Editor\PriceStorage.php"
```

Expected: `No syntax errors detected`.

- [ ] **Step 13.3 — Commit**

```powershell
git add includes/Editor/PriceStorage.php
git commit -m "feat: Editor\PriceStorage — checkout item price and tax rate meta storage (extracted from EditorMain)"
```

---

## Task 14: Editor — EditorSettings + EditorMain

**Files:**
- Port: `[TPL]/includes/class-wcpdf-templates-settings.php` → `[TARGET]/includes/Editor/EditorSettings.php`
- Port: `[TPL]/includes/class-wcpdf-templates-main.php` → `[TARGET]/includes/Editor/EditorMain.php`
- Copy: `[TPL]/assets/css/` → `[TARGET]/assets/css/editor.css`
- Copy: `[TPL]/assets/js/` → `[TARGET]/assets/js/editor.js`

- [ ] **Step 14.1 — Port and rename `EditorSettings`**

```powershell
Copy-Item "[TPL]\includes\class-wcpdf-templates-settings.php" "[TARGET]\includes\Editor\EditorSettings.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Editor\EditorSettings.php"
```

Open `[TARGET]/includes/Editor/EditorSettings.php`:

1. The class was `Settings` in namespace `WPO\WC\PDF_Invoices_Templates`. After rename it is `\WOI\PDF\Editor\EditorSettings`. The class name inside the file will have been renamed to `WOI_PDF_Settings` — correct it to `EditorSettings`:

Search and replace inside the file only:
```
class WOI_PDF_Settings  →  class EditorSettings
```

2. Change the option key from `wpo_wcpdf_editor_settings` (renamed by script to `woi_pdf_editor_settings`) — this is already correct after the rename script.

3. Remove the `simple_template_notice()` method's reference to `default/Simple` path if it hardcodes the old plugin's template directory — update to `woi-pdf/Simple`.

- [ ] **Step 14.2 — Port and rename `EditorMain`**

```powershell
Copy-Item "[TPL]\includes\class-wcpdf-templates-main.php" "[TARGET]\includes\Editor\EditorMain.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\includes\Editor\EditorMain.php"
```

Open `[TARGET]/includes/Editor/EditorMain.php`:

1. Class name `WOI_PDF_Main` → rename to `EditorMain` inside the file.
2. Remove `save_regular_item_price()`, `hide_regular_price_itemmeta()`, `save_tax_rate_percentage_frontend()`, and `save_tax_rate_percentage_recalculate()` methods entirely — they now live in `PriceStorage` (Task 13). Also remove the corresponding `add_action` calls in `__construct()` for those methods.

- [ ] **Step 14.3 — Copy editor assets**

Identify the correct CSS and JS files in `[TPL]/assets/` by listing them:
```powershell
Get-ChildItem "[TPL]\assets" -Recurse
```

Copy the editor-specific CSS and JS:
```powershell
# Replace filenames below with the actual names found in [TPL]/assets/css and [TPL]/assets/js
Copy-Item "[TPL]\assets\css\<editor-stylesheet>.css" "[TARGET]\assets\css\editor.css"
Copy-Item "[TPL]\assets\js\<editor-script>.js"       "[TARGET]\assets\js\editor.js"
```

- [ ] **Step 14.4 — Verify PHP syntax**

```powershell
php -l "[TARGET]\includes\Editor\EditorSettings.php"
php -l "[TARGET]\includes\Editor\EditorMain.php"
```

- [ ] **Step 14.5 — Commit**

```powershell
git add includes/Editor/EditorSettings.php includes/Editor/EditorMain.php assets/css/editor.css assets/js/editor.js
git commit -m "feat: port EditorSettings and EditorMain; remove PriceStorage methods from EditorMain (extracted to Task 13)"
```

---

## Task 15: Premium Templates

**Files:**
- Copy: `[TPL]/templates/Simple Premium/` → `[TARGET]/templates/Simple Premium/`
- Copy: `[TPL]/templates/Modern/` → `[TARGET]/templates/Modern/`
- Copy: `[TPL]/templates/Business/` → `[TARGET]/templates/Business/`

- [ ] **Step 15.1 — Copy premium template directories**

```powershell
Copy-Item "[TPL]\templates\Simple Premium" "[TARGET]\templates\Simple Premium" -Recurse
Copy-Item "[TPL]\templates\Modern"         "[TARGET]\templates\Modern"         -Recurse
Copy-Item "[TPL]\templates\Business"       "[TARGET]\templates\Business"       -Recurse
```

- [ ] **Step 15.2 — Apply rename substitutions to all template PHP files**

```powershell
. "[TARGET]\tools\rename.ps1"
Get-ChildItem "[TARGET]\templates" -Recurse -Filter *.php | ForEach-Object { Rename-WoiPdf $_.FullName }
```

- [ ] **Step 15.3 — Verify PHP syntax for all template files**

```powershell
Get-ChildItem "[TARGET]\templates" -Recurse -Filter *.php | ForEach-Object {
    $result = php -l $_.FullName
    if ($result -notmatch 'No syntax errors') { Write-Error "Syntax error in: $($_.FullName)" }
}
```

Expected: No output (all pass).

- [ ] **Step 15.4 — Commit**

```powershell
git add templates/
git commit -m "feat: port premium templates (Simple Premium, Modern, Business) for all 6 document types"
```

---

## Task 16: Global Functions + Plugin Entry Point + Final Wiring

**Files:**
- Create: `[TARGET]/woi-pdf-functions.php`
- Create: `[TARGET]/woocommerce-orders-invoice-pdf.php`

These two files are the last to write — the entry point depends on every class being in place.

- [ ] **Step 16.1 — Create `woi-pdf-functions.php`**

Port `[BASE]/wpo-ips-functions.php` as the base:
```powershell
Copy-Item "[BASE]\wpo-ips-functions.php" "[TARGET]\woi-pdf-functions.php"
. "[TARGET]\tools\rename.ps1"
Rename-WoiPdf "[TARGET]\woi-pdf-functions.php"
```

Remove the temporary stub added to `SequentialNumberStore.php` in Task 3 (the `woi_pdf_prepare_identifier_query` function is now defined here). Open `[TARGET]/includes/Documents/SequentialNumberStore.php` and delete the temporary stub block that starts with `// Temporary stub`.

- [ ] **Step 16.2 — Create `woocommerce-orders-invoice-pdf.php`**

```php
<?php
/**
 * Plugin Name:          WooCommerce Orders Invoice PDF
 * Plugin URI:           https://example.com/woocommerce-orders-invoice-pdf
 * Description:          PDF invoices, packing slips, proforma, credit notes, receipts and summaries for WooCommerce — standalone merged plugin.
 * Version:              1.0.0
 * Author:               WP Overnight
 * Author URI:           https://www.wpovernight.com
 * License:              GPLv2 or later
 * License URI:          https://opensource.org/licenses/gpl-license.php
 * Text Domain:          woocommerce-orders-invoice-pdf
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * WC requires at least: 3.3
 * WC tested up to:      9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WOI_PDF' ) ) :

class WOI_PDF {

    public string $version     = '1.0.0';
    public string $version_php = '7.4';
    public string $version_woo = '3.3';
    public string $version_wp  = '6.0';

    public \WOI\PDF\Documents      $documents;
    public \WOI\PDF\Settings       $settings;
    public \WOI\PDF\Main           $main;
    public \WOI\PDF\Admin          $admin;
    public \WOI\PDF\Frontend       $frontend;
    public \WOI\PDF\Assets         $assets;
    public \WOI\PDF\Endpoint       $endpoint;
    public \WOI\PDF\Install        $install;
    public \WOI\PDF\FontSynchronizer $font_synchronizer;
    public \WOI\PDF\Rest           $rest;
    public \WOI\PDF\DocumentRenderer $renderer;
    public \WOI\PDF\TemplateLoader $template_loader;
    public \WOI\PDF\Editor\EditorSettings $editor_settings;
    public \WOI\PDF\Editor\EditorMain     $editor_main;
    public \WOI\PDF\Editor\PriceStorage   $price_storage;

    public string $plugin_basename;

    protected static ?self $_instance = null;

    public static function instance(): self {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        require_once __DIR__ . '/vendor/autoload.php';
        require_once __DIR__ . '/vendor/strauss/autoload.php';

        $this->define( 'WOI_PDF_VERSION',     $this->version );
        $this->define( 'WOI_PDF_PLUGIN_FILE', __FILE__ );
        $this->define( 'WOI_PDF_PLUGIN_PATH', __DIR__ );
        $this->define( 'WOI_PDF_PLUGIN_URL',  untrailingslashit( plugins_url( '/', __FILE__ ) ) );

        $this->plugin_basename = plugin_basename( __FILE__ );

        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
        add_action( 'plugins_loaded',          array( $this, 'init' ), 0 );
    }

    public function declare_hpos_compatibility(): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                WOI_PDF_PLUGIN_FILE
            );
        }
    }

    public function init(): void {
        $this->renderer        = new \WOI\PDF\DocumentRenderer();
        $this->template_loader = new \WOI\PDF\TemplateLoader( WOI_PDF_PLUGIN_PATH );
        $this->settings        = \WOI\PDF\Settings::instance();
        $this->documents       = \WOI\PDF\Documents::instance();
        $this->install         = \WOI\PDF\Install::instance();
        $this->font_synchronizer = \WOI\PDF\FontSynchronizer::instance();
        $this->main            = \WOI\PDF\Main::instance();
        $this->endpoint        = \WOI\PDF\Endpoint::instance();
        $this->assets          = \WOI\PDF\Assets::instance();
        $this->admin           = \WOI\PDF\Admin::instance();
        $this->frontend        = \WOI\PDF\Frontend::instance();
        $this->rest            = new \WOI\PDF\Rest();
        $this->editor_main     = \WOI\PDF\Editor\EditorMain::instance();
        $this->editor_settings = \WOI\PDF\Editor\EditorSettings::instance();
        $this->price_storage   = \WOI\PDF\Editor\PriceStorage::instance();

        // Register settings tabs for each document type (OCP — Settings never needs editing)
        add_action( 'woi_pdf_init_documents', function(): void {
            foreach ( $this->documents->get_documents( 'all' ) as $document ) {
                $this->settings->register_document_tab( $document );
            }
        } );

        do_action( 'woi_pdf_init' );
    }

    private function define( string $name, $value ): void {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }

    public function plugin_url(): string {
        return WOI_PDF_PLUGIN_URL;
    }

    public function plugin_path(): string {
        return WOI_PDF_PLUGIN_PATH;
    }
}

endif;

/**
 * Global singleton accessor — mirrors WP_WCPDF() pattern.
 *
 * @return WOI_PDF
 */
function WOI_PDF(): WOI_PDF {
    return WOI_PDF::instance();
}

WOI_PDF();
```

- [ ] **Step 16.3 — Verify PHP syntax of entry point and functions file**

```powershell
php -l "[TARGET]\woocommerce-orders-invoice-pdf.php"
php -l "[TARGET]\woi-pdf-functions.php"
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 16.4 — Run the full test suite**

```powershell
./vendor/bin/phpunit --testdox
```

Expected: All tests PASS. If any fail, the failure message will indicate which method or class name is mismatched — fix and re-run before committing.

- [ ] **Step 16.5 — Commit**

```powershell
git add woocommerce-orders-invoice-pdf.php woi-pdf-functions.php includes/Documents/SequentialNumberStore.php
git commit -m "feat: plugin entry point, global WOI_PDF() singleton, woi-pdf-functions — plugin complete"
```

---

## Spec Coverage Self-Review

| Spec requirement | Covered by task |
|---|---|
| Standalone plugin, WooCommerce-only dependency | Task 16 (entry point, no source plugin imports) |
| PHP 7.4+, WP 6.0+, WC 3.3+ | Task 16 (header + version constants) |
| HPOS compatibility declared | Task 16 (`declare_hpos_compatibility`) |
| Conflict-free `WOI\PDF` namespace | Tasks 2–16 (rename script applied throughout) |
| `DocumentInterface` + 3 sub-interfaces (ISP/LSP) | Task 2 |
| PascalCase class names, snake_case methods/hooks | Applied throughout all tasks |
| `DocumentNumber` + `SequentialNumberStore` (DRY) | Task 3 |
| `OrderDocument` implements `DocumentInterface` | Task 4 |
| `DocumentRenderer` — sole Dompdf caller (SRP/DRY) | Task 5 |
| `TemplateLoader` — sole path resolver (SRP/DRY) | Task 5 |
| Invoice + PackingSlip + Simple template | Task 6 |
| Proforma + CreditNote + Receipt + Summary + Pro templates | Task 7 |
| `Documents` registry, all 6 types, OCP filter | Task 8 |
| Settings framework + `register_document_tab()` (OCP) | Task 9 |
| `Endpoint` URL handler | Task 10 |
| `Main` — email attachment via `DocumentRenderer` | Task 10 |
| REST API full CRUD (`wc/v3/orders/{id}/documents`) | Task 11 |
| Admin, Frontend, Assets | Task 12 |
| `PriceStorage` — checkout item price + tax meta (SRP) | Task 13 |
| `EditorSettings` — column/totals drag-and-drop | Task 14 |
| `EditorMain` — custom block injection only (SRP) | Task 14 |
| Simple Premium, Modern, Business templates | Task 15 |
| Global `WOI_PDF()` function + `woi_pdf_*` hooks | Task 16 |
| `woi_pdf_settings_*` option keys | Tasks 9, 16 |
| PHPUnit tests for new classes | Tasks 2, 3, 5, 11 |
| No multilingual, no cloud storage, no bulk export | Confirmed absent |
