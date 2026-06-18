# GrapesJS Visual Invoice-Template Editor (Slice 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users design the invoice layout in GrapesJS and render it to a real PDF through mPDF, behind an opt-in toggle, leaving every existing PHP template path untouched.

**Architecture:** A new `WOI\PDF\Visual` namespace adds a token-merge engine (`TemplateTokens`), a settings-backed store (`VisualTemplateStore`), a dedicated admin page hosting vendored GrapesJS, and a REST save route. At invoice render time, when the toggle is on and a template is stored, `OrderDocument` builds the body by merging tokens into the stored HTML and wrapping it in a dedicated mPDF wrapper, instead of including `invoice.php`.

**Tech Stack:** PHP 8.1+, WordPress/WooCommerce APIs, mPDF (via `MpdfMaker`), GrapesJS (vendored dist), PHPUnit 9.5 + Brain Monkey.

## Global Constraints

- PHP floor: **8.1** (typed properties/return types are fine; no 8.2+-only syntax).
- All PHP files start with `if ( ! defined( 'ABSPATH' ) ) exit;` after the namespace.
- Class files guard with `if ( ! class_exists( … ) ) :` … `endif;` to match the codebase.
- Namespace root is `WOI\PDF`; new classes live under `WOI\PDF\Visual` in `includes/Visual/`.
- PHPUnit MUST run with the bootstrap prepended or it dies silently:
  `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`.
- Unit tests use Brain Monkey; namespace `WOI\PDF\Tests\Unit\Visual`; stub WP functions with `Brain\Monkey\Functions\when()`.
- Token syntax is `{{snake_case}}`. The kses sanitiser MUST NOT mangle braces.
- Default state is OFF: nothing renders from the visual path unless `enable_visual_template_invoice` is truthy AND a non-empty template is stored.
- Bump `WOI_PDF_VERSION` whenever shipping new JS/CSS so browsers don't serve stale assets.
- The branch is `feat/grapesjs-visual-template` (already created; the spec is committed there).

## Canonical token list (invoice)

| Token | Resolver | Output |
|---|---|---|
| `{{logo}}` | capture `$doc->header_logo()` (guard `$doc->has_header_logo()`) | raw `<img>` |
| `{{shop_name}}` | `esc_html( $doc->get_shop_name() )` | text |
| `{{shop_address}}` | `esc_html( $doc->get_shop_address() )` | text |
| `{{shop_name_ar}}` | `esc_html( BilingualEngine::instance()->secondary_shop_name() )` | text |
| `{{shop_address_ar}}` | `esc_html( BilingualEngine::instance()->secondary_shop_address() )` | text |
| `{{document_title}}` | `esc_html( $doc->get_title() )` | text |
| `{{document_title_ar}}` | `esc_html( BilingualEngine::instance()->secondary_label( 'document', $doc ) )` | text |
| `{{trn}}` | `esc_html( $doc->get_shop_vat_number() )` | text |
| `{{shop_phone}}` | `esc_html( $doc->get_shop_phone_number() )` | text |
| `{{shop_email}}` | `esc_html( $doc->get_shop_email_address() )` | text |
| `{{invoice_number}}` | capture `$doc->number( $doc->get_type() )` | text |
| `{{invoice_date}}` | capture `$doc->date( $doc->get_type() )` | text |
| `{{order_number}}` | `esc_html( (string) $doc->get_order_number() )` | text |
| `{{payment_method}}` | `esc_html( (string) $doc->get_payment_method() )` | text |
| `{{billing_address}}` | `$doc->get_billing_address()` | raw (already sanitised html) |
| `{{line_items}}` | iterate `woi_pdf_templates_get_table_headers/_body( $doc )` → `<table>` | raw html |
| `{{totals}}` | iterate `woi_pdf_templates_get_totals( $doc )` → `<table>` | raw html |

These getters/functions are verified present: `OrderDocument::get_shop_name/get_shop_address/get_shop_vat_number/get_shop_phone_number/get_shop_email_address/get_title/get_order_number/get_payment_method/get_billing_address/header_logo/has_header_logo/number/date/get_type`; `BilingualEngine::secondary_shop_name/secondary_shop_address/secondary_label`; global `woi_pdf_templates_get_table_headers/_get_table_body/_get_totals`.

---

### Task 1: `TemplateTokens` merge engine

**Files:**
- Create: `includes/Visual/TemplateTokens.php`
- Test: `tests/Unit/Visual/TemplateTokensTest.php`

**Interfaces:**
- Consumes: an `OrderDocument`-like object exposing the getters in the token table; globals `woi_pdf_templates_get_table_headers($doc)`, `woi_pdf_templates_get_table_body($doc)`, `woi_pdf_templates_get_totals($doc)`; `\WOI\PDF\Bilingual\BilingualEngine::instance()`.
- Produces:
  - `public function map( $document ): array` → `array<string,string>` keyed by the literal `{{token}}` string, values are the fully-resolved (escaped where appropriate) replacements.
  - `public function merge( string $html, $document ): string` → `$html` with all known tokens replaced and any leftover `{{…}}` stripped.

- [ ] **Step 1: Write the failing test (scalars + leftover stripping)**

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\TemplateTokens;

class TemplateTokensTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // esc_html / esc_attr passthrough so assertions are readable.
        Functions\when( 'esc_html' )->returnArg( 1 );
        Functions\when( 'esc_attr' )->returnArg( 1 );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        // BilingualEngine reads options + dictionary file.
        Functions\when( 'get_option' )->justReturn( array(
            'shop_name_ar'    => 'متجر',
            'shop_address_ar' => 'دبي',
        ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stub_document() {
        return new class {
            public function get_type() { return 'invoice'; }
            public function get_shop_name() { return 'Acme Co'; }
            public function get_shop_address() { return '1 Main St'; }
            public function get_shop_vat_number() { return '100' ; }
            public function get_shop_phone_number() { return '+971' ; }
            public function get_shop_email_address() { return 'a@b.co'; }
            public function get_title() { return 'Tax Invoice'; }
            public function get_order_number() { return '4242'; }
            public function get_payment_method() { return 'Card'; }
            public function get_billing_address() { return 'John<br>Dubai'; }
            public function has_header_logo() { return true; }
            public function header_logo() { echo '<img src="x.png">'; }
            public function number( $t ) { echo 'INV-7'; }
            public function date( $t ) { echo '2026-06-18'; }
            public function get_setting( $k ) { return ''; }
        };
    }

    public function test_scalar_tokens_resolve_and_escape(): void {
        $tokens = new TemplateTokens();
        $map    = $tokens->map( $this->stub_document() );

        $this->assertSame( 'Acme Co', $map['{{shop_name}}'] );
        $this->assertSame( '1 Main St', $map['{{shop_address}}'] );
        $this->assertSame( 'Tax Invoice', $map['{{document_title}}'] );
        $this->assertSame( 'INV-7', $map['{{invoice_number}}'] );
        $this->assertSame( '2026-06-18', $map['{{invoice_date}}'] );
        $this->assertSame( '4242', $map['{{order_number}}'] );
        $this->assertSame( '<img src="x.png">', $map['{{logo}}'] );
        $this->assertSame( 'متجر', $map['{{shop_name_ar}}'] );
    }

    public function test_merge_replaces_known_and_strips_unknown_tokens(): void {
        $tokens = new TemplateTokens();
        $html   = '<h1>{{document_title}}</h1><p>{{shop_name}}</p><i>{{bogus}}</i>';
        $out    = $tokens->merge( $html, $this->stub_document() );

        $this->assertStringContainsString( '<h1>Tax Invoice</h1>', $out );
        $this->assertStringContainsString( '<p>Acme Co</p>', $out );
        $this->assertStringNotContainsString( '{{', $out );
        $this->assertStringContainsString( '<i></i>', $out );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/TemplateTokensTest.php`
Expected: FAIL — `Class "WOI\PDF\Visual\TemplateTokens" not found`.

- [ ] **Step 3: Implement `TemplateTokens` (scalars + blocks + merge)**

```php
<?php
namespace WOI\PDF\Visual;

use WOI\PDF\Bilingual\BilingualEngine;
use function esc_html;
use function esc_attr;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\TemplateTokens' ) ) :

class TemplateTokens {

    /**
     * Build the {{token}} => replacement map for a document.
     *
     * Scalar tokens are esc_html()'d. Block tokens (logo, billing_address,
     * line_items, totals) are trusted HTML produced by existing renderers.
     *
     * @param object $document An OrderDocument (or compatible stub).
     * @return array<string,string>
     */
    public function map( $document ): array {
        $engine = BilingualEngine::instance();

        return array(
            '{{logo}}'             => $document->has_header_logo() ? $this->capture( array( $document, 'header_logo' ) ) : '',
            '{{shop_name}}'        => esc_html( (string) $document->get_shop_name() ),
            '{{shop_address}}'     => esc_html( (string) $document->get_shop_address() ),
            '{{shop_name_ar}}'     => esc_html( $engine->secondary_shop_name() ),
            '{{shop_address_ar}}'  => esc_html( $engine->secondary_shop_address() ),
            '{{document_title}}'   => esc_html( $document->get_title() ),
            '{{document_title_ar}}'=> esc_html( $engine->secondary_label( 'document', $document ) ),
            '{{trn}}'              => esc_html( (string) $document->get_shop_vat_number() ),
            '{{shop_phone}}'       => esc_html( (string) $document->get_shop_phone_number() ),
            '{{shop_email}}'       => esc_html( (string) $document->get_shop_email_address() ),
            '{{invoice_number}}'   => $this->capture( fn() => $document->number( $document->get_type() ) ),
            '{{invoice_date}}'     => $this->capture( fn() => $document->date( $document->get_type() ) ),
            '{{order_number}}'     => esc_html( (string) $document->get_order_number() ),
            '{{payment_method}}'   => esc_html( (string) $document->get_payment_method() ),
            '{{billing_address}}'  => (string) $document->get_billing_address(),
            '{{line_items}}'       => $this->render_line_items( $document ),
            '{{totals}}'           => $this->render_totals( $document ),
        );
    }

    /**
     * Replace all known tokens, then strip any leftover {{...}} so stray
     * braces never reach the PDF.
     */
    public function merge( string $html, $document ): string {
        $html = strtr( $html, $this->map( $document ) );
        return (string) preg_replace( '/\{\{\s*[a-z0-9_]+\s*\}\}/i', '', $html );
    }

    /** Capture the output of an echo-style callback. */
    private function capture( callable $callback ): string {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }

    /** Build the line-items table, mirroring the Standard UAE invoice markup. */
    private function render_line_items( $document ): string {
        $headers = (array) woi_pdf_templates_get_table_headers( $document );
        $body    = (array) woi_pdf_templates_get_table_body( $document );

        $html = '<table class="order-details"><thead><tr>';
        foreach ( $headers as $header_data ) {
            $html .= '<th class="' . esc_attr( $header_data['class'] ?? '' ) . '">' . esc_html( $header_data['title'] ?? '' );
            if ( ! empty( $header_data['secondary'] ) ) {
                $html .= '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $header_data['secondary'] ) . '</span>';
            }
            $html .= '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $body as $item_columns ) {
            $html .= '<tr>';
            foreach ( (array) $item_columns as $column_data ) {
                $html .= '<td class="' . esc_attr( $column_data['class'] ?? '' ) . '"><span>' . esc_html( $column_data['data'] ?? '' ) . '</span></td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** Build the totals table, mirroring the Standard UAE invoice markup. */
    private function render_totals( $document ): string {
        $totals = (array) woi_pdf_templates_get_totals( $document );

        $html = '<table class="totals-table">';
        foreach ( $totals as $total_data ) {
            $html .= '<tr class="' . esc_attr( $total_data['class'] ?? '' ) . '">';
            $html .= '<th class="description"><span>' . esc_html( $total_data['label'] ?? '' );
            if ( ! empty( $total_data['secondary'] ) ) {
                $html .= '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $total_data['secondary'] ) . '</span>';
            }
            $html .= '</span></th>';
            $html .= '<td class="price"><span class="totals-price">' . esc_html( $total_data['value'] ?? '' ) . '</span></td>';
            $html .= '</tr>';
        }
        return $html . '</table>';
    }
}

endif;
```

- [ ] **Step 4: Run test to verify scalars/merge pass**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/TemplateTokensTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Add a block-token test**

Append to `TemplateTokensTest.php`:

```php
    public function test_block_tokens_render_tables(): void {
        Functions\when( 'woi_pdf_templates_get_table_headers' )->justReturn( array(
            array( 'class' => 'sku', 'title' => 'SKU' ),
        ) );
        Functions\when( 'woi_pdf_templates_get_table_body' )->justReturn( array(
            array( array( 'class' => 'sku', 'data' => 'A-1' ) ),
        ) );
        Functions\when( 'woi_pdf_templates_get_totals' )->justReturn( array(
            array( 'class' => 'total', 'label' => 'Total', 'value' => 'AED 10' ),
        ) );

        $tokens = new TemplateTokens();
        $map    = $tokens->map( $this->stub_document() );

        $this->assertStringContainsString( '<table class="order-details">', $map['{{line_items}}'] );
        $this->assertStringContainsString( 'A-1', $map['{{line_items}}'] );
        $this->assertStringContainsString( '<table class="totals-table">', $map['{{totals}}'] );
        $this->assertStringContainsString( 'AED 10', $map['{{totals}}'] );
    }
```

- [ ] **Step 6: Run the full file to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/TemplateTokensTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add includes/Visual/TemplateTokens.php tests/Unit/Visual/TemplateTokensTest.php
git commit -m "feat: TemplateTokens merge engine for visual invoice templates"
```

---

### Task 2: `VisualTemplateStore` (persist + sanitise)

**Files:**
- Create: `includes/Visual/VisualTemplateStore.php`
- Test: `tests/Unit/Visual/VisualTemplateStoreTest.php`

**Interfaces:**
- Consumes: WP options API (`get_option`, `update_option`), `wp_kses`.
- Produces:
  - `public function get( string $doc_type ): string` — stored HTML or `''`.
  - `public function save( string $doc_type, string $html ): void` — kses-sanitises (preserving `{{tokens}}`) and persists, unautoloaded.
  - `public function option_name( string $doc_type ): string` → `woi_pdf_visual_template_{doc_type}`.
  - `public function allowed_html(): array` — the kses allowlist used on save (also reused by tests).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\VisualTemplateStore;

class VisualTemplateStoreTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_option_name_is_namespaced_per_doc_type(): void {
        $store = new VisualTemplateStore();
        $this->assertSame( 'woi_pdf_visual_template_invoice', $store->option_name( 'invoice' ) );
    }

    public function test_get_returns_stored_html(): void {
        Functions\when( 'get_option' )->justReturn( '<p>{{shop_name}}</p>' );
        $store = new VisualTemplateStore();
        $this->assertSame( '<p>{{shop_name}}</p>', $store->get( 'invoice' ) );
    }

    public function test_get_returns_empty_string_when_unset(): void {
        Functions\when( 'get_option' )->justReturn( false );
        $store = new VisualTemplateStore();
        $this->assertSame( '', $store->get( 'invoice' ) );
    }

    public function test_save_preserves_tokens_through_kses(): void {
        $captured = null;
        // Real wp_kses would strip nothing here; emulate a passthrough that
        // proves save() does not pre-mangle braces before handing to kses.
        Functions\when( 'wp_kses' )->returnArg( 1 );
        Functions\when( 'update_option' )->alias(
            function ( $name, $value, $autoload ) use ( &$captured ) {
                $captured = array( $name, $value, $autoload );
                return true;
            }
        );

        $store = new VisualTemplateStore();
        $store->save( 'invoice', '<h1>{{document_title}}</h1>' );

        $this->assertSame( 'woi_pdf_visual_template_invoice', $captured[0] );
        $this->assertStringContainsString( '{{document_title}}', $captured[1] );
        $this->assertFalse( $captured[2] ); // unautoloaded
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/VisualTemplateStoreTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `VisualTemplateStore`**

```php
<?php
namespace WOI\PDF\Visual;

use function get_option;
use function update_option;
use function wp_kses;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\VisualTemplateStore' ) ) :

class VisualTemplateStore {

    public function option_name( string $doc_type ): string {
        return 'woi_pdf_visual_template_' . preg_replace( '/[^a-z0-9_]/', '', $doc_type );
    }

    public function get( string $doc_type ): string {
        $stored = get_option( $this->option_name( $doc_type ), '' );
        return is_string( $stored ) ? $stored : '';
    }

    public function save( string $doc_type, string $html ): void {
        $clean = wp_kses( $html, $this->allowed_html() );
        update_option( $this->option_name( $doc_type ), $clean, false );
    }

    /**
     * kses allowlist for GrapesJS output. Covers tables, common block/inline
     * tags, images, and a <style> element. The {{token}} brace syntax is plain
     * text content/attribute data, which kses leaves untouched.
     *
     * @return array<string,array<string,bool>>
     */
    public function allowed_html(): array {
        $common = array(
            'class' => true,
            'id'    => true,
            'style' => true,
            'dir'   => true,
        );

        return array(
            'table' => $common, 'thead' => $common, 'tbody' => $common, 'tfoot' => $common,
            'tr' => $common, 'td' => $common + array( 'colspan' => true, 'rowspan' => true ),
            'th' => $common + array( 'colspan' => true, 'rowspan' => true ),
            'div' => $common, 'span' => $common, 'p' => $common,
            'h1' => $common, 'h2' => $common, 'h3' => $common,
            'h4' => $common, 'h5' => $common, 'h6' => $common,
            'strong' => $common, 'em' => $common, 'b' => $common, 'i' => $common,
            'br' => array(), 'hr' => $common,
            'ul' => $common, 'ol' => $common, 'li' => $common,
            'img' => $common + array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true ),
            'style' => array( 'type' => true ),
        );
    }
}

endif;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/VisualTemplateStoreTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Add a real-kses token-survival test (integration safety)**

This test exercises the *real* `wp_kses` to prove braces survive. It only runs when WordPress's kses is loadable; skip otherwise.

```php
    public function test_real_kses_keeps_tokens_when_available(): void {
        if ( ! function_exists( 'wp_kses' ) ) {
            $this->markTestSkipped( 'wp_kses not loaded in this suite' );
        }
        $store = new VisualTemplateStore();
        $out   = wp_kses( '<h1>{{document_title}}</h1>', $store->allowed_html() );
        $this->assertStringContainsString( '{{document_title}}', $out );
    }
```

- [ ] **Step 6: Run + commit**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/VisualTemplateStoreTest.php`
Expected: PASS (5 tests; the kses test may be skipped — acceptable).

```bash
git add includes/Visual/VisualTemplateStore.php tests/Unit/Visual/VisualTemplateStoreTest.php
git commit -m "feat: VisualTemplateStore persists/sanitises visual templates"
```

---

### Task 3: Visual mPDF wrapper + invoice render interception + settings toggle

**Files:**
- Create: `templates/_visual/visual-document-wrapper.php`
- Modify: `includes/Documents/OrderDocument.php` (invoice body build path)
- Modify: settings registration for the `enable_visual_template_invoice` toggle (in the invoice/documents settings group — locate the existing per-document settings array and add the checkbox there)
- Test: `tests/Unit/Visual/VisualRenderPathTest.php`

**Interfaces:**
- Consumes: `TemplateTokens::merge()`, `VisualTemplateStore::get()`, `$document->get_setting('enable_visual_template_invoice')`, `$document->get_type()`.
- Produces:
  - `OrderDocument::visual_template_active(): bool` — true only for `invoice` type when the toggle is on AND a non-empty template is stored.
  - `OrderDocument::render_visual_body(): string` — merged + wrapped HTML ready for mPDF.

First, locate the exact point where the invoice HTML body is currently built (the call that includes `invoice.php`). Search:

```bash
grep -rn "locate_template_file\|wrap_html_content\|render_template" includes/Documents/OrderDocument.php
```

The body is produced by `render_template( locate_template_file( $type . '.php' ), … )` and later wrapped by `wrap_html_content()`. Insert the branch at the point the per-document body string is assembled (the method that returns the document body HTML — confirm by reading the surrounding code before editing).

- [ ] **Step 1: Write the visual wrapper template**

Create `templates/_visual/visual-document-wrapper.php`:

```php
<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 15mm; }
body { font-family: "dejavusans", sans-serif; font-size: 11pt; color: #222; }
/* Arabic shaping is handled natively by mPDF; the secondary-language
   font stack is registered by MpdfMaker. RTL spans carry dir="rtl". */
.woi-bilingual-secondary, [dir="rtl"] { direction: rtl; }
table { border-collapse: collapse; width: 100%; }
</style>
</head>
<body>
<?php echo $content; // already token-merged + sanitised on save ?>
</body>
</html>
```

Note: this wrapper deliberately does NOT redeclare `@font-face` — font registration for Arabic lives in `MpdfMaker` (confirm by reading `includes/Makers/MpdfMaker.php`; if fonts are registered there per-render, nothing more is needed here. If a template-level `@font-face` is required, copy the block from `templates/Standard UAE Tax Invoice/html-document-wrapper.php` into the `<style>` above).

- [ ] **Step 2: Write the failing test for the render-path helpers**

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class VisualRenderPathTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_visual_active_requires_toggle_and_stored_template(): void {
        // Helper extracted as a pure function for isolated testing.
        $active = \WOI\PDF\Visual\visual_template_active( 'invoice', true, '<p>x</p>' );
        $this->assertTrue( $active );

        $this->assertFalse( \WOI\PDF\Visual\visual_template_active( 'invoice', false, '<p>x</p>' ) );
        $this->assertFalse( \WOI\PDF\Visual\visual_template_active( 'invoice', true, '' ) );
        $this->assertFalse( \WOI\PDF\Visual\visual_template_active( 'credit-note', true, '<p>x</p>' ) );
    }
}
```

- [ ] **Step 3: Implement the pure gate helper**

Add to `includes/Visual/TemplateTokens.php` (bottom of file, outside the class but inside the namespace) OR a small `includes/Visual/functions.php` autoloaded via `Main`. Recommended: create `includes/Visual/functions.php`:

```php
<?php
namespace WOI\PDF\Visual;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Whether the visual template path should be used for a given document.
 * Invoice-only in slice 1; requires the toggle ON and a non-empty stored template.
 */
function visual_template_active( string $doc_type, bool $toggle_on, string $stored_html ): bool {
    return 'invoice' === $doc_type && $toggle_on && '' !== trim( $stored_html );
}
```

Ensure this file is required where `Main` loads includes (search `grep -n "includes/Visual\|require.*Visual\|TemplateTokens" includes/Main.php`; add a `require_once` alongside the other `includes/` requires, or add `Visual\\` to the autoloader if one is used).

- [ ] **Step 4: Run helper test to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/VisualRenderPathTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Wire the branch into `OrderDocument`**

In the method that assembles the document body (the one that calls `render_template( $this->locate_template_file( … ) )` for the per-type template), add at the top:

```php
$store   = new \WOI\PDF\Visual\VisualTemplateStore();
$stored  = $store->get( $this->get_type() );
$toggle  = (bool) $this->get_setting( 'enable_visual_template_invoice' );

if ( \WOI\PDF\Visual\visual_template_active( $this->get_type(), $toggle, $stored ) ) {
    $merged  = ( new \WOI\PDF\Visual\TemplateTokens() )->merge( $stored, $this );
    return $this->render_template(
        $this->plugin_path() . '/templates/_visual/visual-document-wrapper.php',
        array( 'content' => $merged )
    );
}
// …existing per-type template path continues unchanged below…
```

Adjust `plugin_path()` to whatever accessor the class uses (confirm via the existing `locate_template_file()` which references `WOI_PDF()->plugin_path()`). The merged body is passed as `$content`, matching `wrap_html_content()`'s contract, so it flows to mPDF exactly like the legacy path.

- [ ] **Step 6: Register the settings toggle**

Find the invoice/documents settings field array (search `grep -rn "display_number\|display_date" includes/Settings*`). Add a checkbox field next to those, keyed `enable_visual_template_invoice`, label `Visual template (invoice)`, description: "Render the invoice from the visual editor design instead of the selected template." Follow the exact field-array shape used by the neighbouring checkboxes (do not invent a new pattern).

- [ ] **Step 7: Run the whole suite + manually sanity-check the toggle-off path**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: PASS (all existing + new tests). Toggle OFF must leave existing tests green (legacy path untouched).

- [ ] **Step 8: Commit**

```bash
git add includes/Visual/functions.php templates/_visual/visual-document-wrapper.php includes/Documents/OrderDocument.php includes/Settings* includes/Main.php tests/Unit/Visual/VisualRenderPathTest.php
git commit -m "feat: render invoice from stored visual template when toggle on"
```

---

### Task 4: REST save endpoint

**Files:**
- Modify: `includes/Rest.php`
- Test: `tests/Unit/Visual/VisualRestTest.php`

**Interfaces:**
- Consumes: `VisualTemplateStore::save()`, WP REST API (`register_rest_route`), `current_user_can('manage_woocommerce')`, nonce check.
- Produces: route `POST /woi-pdf/v1/visual-template` accepting `{ doc_type: string, html: string }`; returns `{ saved: true }` on success, `403` on capability failure.

- [ ] **Step 1: Write the failing test for the handler**

Extract the handler as a testable method `handle_visual_template_save( $request ): array|\WP_Error` taking an object with `get_param()`.

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class VisualRestTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_save_handler_persists_and_returns_saved(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_kses' )->returnArg( 1 );
        $saved = null;
        Functions\when( 'update_option' )->alias( function ( $n, $v ) use ( &$saved ) { $saved = $v; return true; } );

        $request = new class {
            public function get_param( $k ) {
                return array( 'doc_type' => 'invoice', 'html' => '<p>{{shop_name}}</p>' )[ $k ] ?? null;
            }
        };

        $rest   = new Rest();
        $result = $rest->handle_visual_template_save( $request );

        $this->assertSame( array( 'saved' => true ), $result );
        $this->assertStringContainsString( '{{shop_name}}', $saved );
    }
}
```

(If `Rest` has constructor dependencies, instantiate it the way existing `Rest` tests do — check `tests/Unit` for prior usage; otherwise add a no-arg constructor path or test the handler via a minimal subclass.)

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/VisualRestTest.php`
Expected: FAIL — method not defined.

- [ ] **Step 3: Implement the route + handler in `Rest.php`**

Register in the existing `register_rest_route` hook block:

```php
register_rest_route( 'woi-pdf/v1', '/visual-template', array(
    'methods'             => 'POST',
    'callback'            => array( $this, 'handle_visual_template_save' ),
    'permission_callback' => function () {
        return current_user_can( 'manage_woocommerce' );
    },
    'args'                => array(
        'doc_type' => array( 'type' => 'string', 'required' => true ),
        'html'     => array( 'type' => 'string', 'required' => true ),
    ),
) );
```

Handler:

```php
public function handle_visual_template_save( $request ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
    }
    $doc_type = (string) $request->get_param( 'doc_type' );
    $html     = (string) $request->get_param( 'html' );

    ( new \WOI\PDF\Visual\VisualTemplateStore() )->save( $doc_type, $html );

    return array( 'saved' => true );
}
```

(The REST cookie nonce `X-WP-Nonce` is validated by WordPress when the request carries it; the JS in Task 6 sends `wpApiSettings.nonce`.)

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/VisualRestTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add includes/Rest.php tests/Unit/Visual/VisualRestTest.php
git commit -m "feat: REST route to save visual invoice templates"
```

---

### Task 5: Admin page + vendored GrapesJS + starter layout

**Files:**
- Create: `includes/Visual/VisualEditorPage.php`
- Create: `assets/visual-editor/grapesjs/grapes.min.js` (vendored)
- Create: `assets/visual-editor/grapesjs/grapes.min.css` (vendored)
- Create: `assets/visual-editor/starter-invoice.html`
- Modify: `includes/Main.php` (instantiate `VisualEditorPage`) and `includes/Admin.php` if menu registration lives there

**Interfaces:**
- Consumes: `add_submenu_page`, `wp_enqueue_script/style`, `VisualTemplateStore::get('invoice')`, `wp_localize_script`.
- Produces: an admin page at the plugin's menu; enqueues GrapesJS + `app.js`; prints `<div id="woi-visual-editor">` and localises `woiVisual` with `{ restUrl, nonce, stored, starter, sampleData }`.

- [ ] **Step 1: Vendor GrapesJS**

Download the pinned dist (same version the prototype's CDN `index.html` pins — read `prototypes/grapesjs-template-editor/index.html` on branch `prototype/grapesjs-template-editor` to get the exact version and integrity, then fetch that version's `dist/grapes.min.js` and `dist/css/grapes.min.css`). Place them at the paths above. No build step.

```bash
git show prototype/grapesjs-template-editor:prototypes/grapesjs-template-editor/index.html | grep -i grapes
```

- [ ] **Step 2: Write the starter layout**

`assets/visual-editor/starter-invoice.html` — a token-populated HTML body mirroring the current invoice (header row English | logo | Arabic, title, billing, meta, line items, totals). Seed it from the Standard UAE invoice structure using tokens:

```html
<table style="width:100%"><tr>
  <td style="text-align:left">{{shop_name}}<br>{{shop_address}}<br>TRN: {{trn}} · {{shop_phone}} · {{shop_email}}</td>
  <td style="text-align:center">{{logo}}</td>
  <td style="text-align:right" dir="rtl">{{shop_name_ar}}<br>{{shop_address_ar}}</td>
</tr></table>
<h1 style="text-align:center">{{document_title}} <span dir="rtl">{{document_title_ar}}</span></h1>
<table style="width:100%"><tr>
  <td>{{billing_address}}</td>
  <td>Invoice #: {{invoice_number}}<br>Date: {{invoice_date}}<br>Order: {{order_number}}<br>Payment: {{payment_method}}</td>
</tr></table>
{{line_items}}
{{totals}}
```

- [ ] **Step 3: Implement `VisualEditorPage`**

```php
<?php
namespace WOI\PDF\Visual;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\VisualEditorPage' ) ) :

class VisualEditorPage {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    public function register_page(): void {
        add_submenu_page(
            'woi-pdf', // confirm the plugin's top-level menu slug via includes/Admin.php
            __( 'Visual Template', 'woocommerce-orders-invoice-pdf' ),
            __( 'Visual Template', 'woocommerce-orders-invoice-pdf' ),
            'manage_woocommerce',
            'woi-pdf-visual',
            array( $this, 'render_page' )
        );
    }

    public function enqueue( string $hook ): void {
        if ( false === strpos( $hook, 'woi-pdf-visual' ) ) {
            return;
        }
        $base = WOI_PDF()->plugin_url() . '/assets/visual-editor';
        wp_enqueue_style( 'woi-grapesjs', $base . '/grapesjs/grapes.min.css', array(), WOI_PDF_VERSION );
        wp_enqueue_script( 'woi-grapesjs', $base . '/grapesjs/grapes.min.js', array(), WOI_PDF_VERSION, true );
        wp_enqueue_script( 'woi-visual-editor', $base . '/app.js', array( 'woi-grapesjs' ), WOI_PDF_VERSION, true );

        $store = new VisualTemplateStore();
        wp_localize_script( 'woi-visual-editor', 'woiVisual', array(
            'restUrl'    => esc_url_raw( rest_url( 'woi-pdf/v1/visual-template' ) ),
            'previewUrl' => esc_url_raw( admin_url( 'admin-ajax.php?action=woi_pdf_preview&document_type=invoice' ) ), // confirm existing preview action
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'docType'    => 'invoice',
            'stored'     => $store->get( 'invoice' ),
            'starter'    => $this->starter_html(),
            'sampleData' => $this->sample_data(),
        ) );
    }

    public function render_page(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Visual Invoice Template', 'woocommerce-orders-invoice-pdf' ) . '</h1>';
        echo '<p>' . esc_html__( 'Design with table/block layout for best mPDF fidelity. Use "Preview real PDF" to verify Arabic and pagination.', 'woocommerce-orders-invoice-pdf' ) . '</p>';
        echo '<div id="woi-visual-editor"></div></div>';
    }

    private function starter_html(): string {
        $file = WOI_PDF()->plugin_path() . '/assets/visual-editor/starter-invoice.html';
        return is_readable( $file ) ? (string) file_get_contents( $file ) : '';
    }

    /** Static sample values for the in-editor preview (browser-only, approximate). */
    private function sample_data(): array {
        return array(
            '{{shop_name}}'       => 'Acme Trading LLC',
            '{{shop_address}}'    => 'Office 12, Dubai, UAE',
            '{{shop_name_ar}}'    => 'أكمي للتجارة',
            '{{shop_address_ar}}' => 'مكتب ١٢، دبي',
            '{{trn}}'             => '100123456700003',
            '{{shop_phone}}'      => '+971 4 000 0000',
            '{{shop_email}}'      => 'billing@acme.example',
            '{{logo}}'            => '',
            '{{document_title}}'  => 'Tax Invoice',
            '{{document_title_ar}}' => 'فاتورة ضريبية',
            '{{billing_address}}' => 'John Buyer<br>Abu Dhabi, UAE',
            '{{invoice_number}}'  => 'INV-001',
            '{{invoice_date}}'    => '18 June 2026',
            '{{order_number}}'    => '4242',
            '{{payment_method}}'  => 'Credit Card',
            '{{line_items}}'      => '<table class="order-details"><thead><tr><th>Item</th><th>Qty</th><th>Price</th></tr></thead><tbody><tr><td>Widget</td><td>2</td><td>AED 50</td></tr></tbody></table>',
            '{{totals}}'          => '<table class="totals-table"><tr><th>Total</th><td>AED 100</td></tr></table>',
        );
    }
}

endif;
```

Confirm the menu slug (`woi-pdf`), `plugin_url()`, `plugin_path()`, and the existing preview action by reading `includes/Admin.php` and `includes/Main.php` before finalising.

- [ ] **Step 4: Instantiate the page**

In `includes/Main.php`, alongside other admin component instantiations (search for where `Admin`/`Settings` are `new`'d), add `new \WOI\PDF\Visual\VisualEditorPage();` (admin context only — gate with `is_admin()` if neighbouring code does).

- [ ] **Step 5: Manual verification**

Load `wp-admin` → plugin menu → **Visual Template**. Expected: the page renders, GrapesJS mounts in `#woi-visual-editor` with the starter layout (no JS console errors). `app.js` (Task 6) provides interactivity; at this step a bare GrapesJS canvas with vendored assets loading is sufficient.

- [ ] **Step 6: Commit**

```bash
git add includes/Visual/VisualEditorPage.php assets/visual-editor/ includes/Main.php
git commit -m "feat: visual template admin page with vendored GrapesJS"
```

---

### Task 6: Editor JS — blocks, sample-data preview, save, preview-real-PDF

**Files:**
- Create: `assets/visual-editor/app.js`

**Interfaces:**
- Consumes: global `grapesjs`, `woiVisual` (localised in Task 5).
- Produces: a booted editor with token blocks, a Save button (POST to `woiVisual.restUrl`), a "Preview real PDF" button (saves, then opens `woiVisual.previewUrl`), and a sample-data merge for the in-canvas preview.

- [ ] **Step 1: Implement `app.js`**

```js
( function () {
    if ( typeof grapesjs === 'undefined' || ! window.woiVisual ) { return; }

    var tokens = [
        'logo', 'shop_name', 'shop_address', 'shop_name_ar', 'shop_address_ar',
        'document_title', 'document_title_ar', 'trn', 'shop_phone', 'shop_email',
        'billing_address', 'invoice_number', 'invoice_date', 'order_number',
        'payment_method', 'line_items', 'totals'
    ];

    var editor = grapesjs.init( {
        container: '#woi-visual-editor',
        height: '80vh',
        fromElement: false,
        storageManager: false,
        components: woiVisual.stored || woiVisual.starter || ''
    } );

    // Register one draggable block per token.
    tokens.forEach( function ( t ) {
        editor.BlockManager.add( 'token-' + t, {
            label: '{{' + t + '}}',
            category: 'Invoice tokens',
            content: '<span data-woi-token="' + t + '">{{' + t + '}}</span>'
        } );
    } );

    function getHtml() {
        return editor.getHtml() + '<style>' + editor.getCss() + '</style>';
    }

    function save() {
        return fetch( woiVisual.restUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': woiVisual.nonce },
            body: JSON.stringify( { doc_type: woiVisual.docType, html: getHtml() } )
        } ).then( function ( r ) { return r.json(); } );
    }

    function mergeSample( html ) {
        var out = html;
        Object.keys( woiVisual.sampleData ).forEach( function ( k ) {
            out = out.split( k ).join( woiVisual.sampleData[ k ] );
        } );
        return out.replace( /\{\{\s*[a-z0-9_]+\s*\}\}/gi, '' );
    }

    // Toolbar buttons.
    editor.Panels.addButton( 'options', {
        id: 'woi-save', className: 'fa fa-floppy-o', attributes: { title: 'Save' },
        command: function () { save().then( function () { alert( 'Saved' ); } ); }
    } );
    editor.Panels.addButton( 'options', {
        id: 'woi-preview-pdf', className: 'fa fa-file-pdf-o', attributes: { title: 'Preview real PDF' },
        command: function () { save().then( function () { window.open( woiVisual.previewUrl, '_blank' ); } ); }
    } );
    editor.Panels.addButton( 'options', {
        id: 'woi-preview-sample', className: 'fa fa-eye', attributes: { title: 'Preview sample data' },
        command: function () {
            var w = window.open( '', '_blank' );
            w.document.write( mergeSample( getHtml() ) );
            w.document.close();
        }
    } );
}() );
```

- [ ] **Step 2: Manual verification (the slice's acceptance test)**

1. Open the Visual Template admin page. Drag a couple of token blocks; confirm the palette lists all 17 tokens.
2. Click **Preview sample data** → a new tab shows the design with sample values merged, no `{{…}}` left.
3. Click **Save** → "Saved"; reload the page → the design persists (loaded from `woiVisual.stored`).
4. In invoice settings, enable **Visual template (invoice)**.
5. Generate/preview an invoice PDF for a real order. Confirm it renders from the visual design via mPDF.
6. Rasterise the PDF with PyMuPDF and eyeball: Arabic shaping correct, line items present, totals present. (Per the rendering-PDFs-for-verification memory.)
7. Toggle **off** → invoice reverts to the selected PHP template. Confirm unchanged.

- [ ] **Step 3: Commit**

```bash
git add assets/visual-editor/app.js
git commit -m "feat: GrapesJS editor app — tokens, save, sample + real-PDF preview"
```

---

### Task 7: Version bump + full verification

**Files:**
- Modify: the file defining `WOI_PDF_VERSION` (plugin header + constant — search `grep -rn "WOI_PDF_VERSION" *.php`)

- [ ] **Step 1: Bump the version**

Increment `WOI_PDF_VERSION` and the plugin-header `Version:` (patch bump) so the new JS/CSS is not served stale. (Per the asset-version-cache-bust memory.)

- [ ] **Step 2: Run the full unit suite**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: PASS — all tests green, including the new `Visual\*` tests and all pre-existing tests.

- [ ] **Step 3: Run the full manual acceptance flow**

Repeat Task 6 Step 2 end-to-end on a clean reload to confirm no caching/regressions.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: bump version for visual template editor assets"
```

---

## Self-Review

**Spec coverage:**
- Admin editor surface + vendored GrapesJS → Tasks 5, 6. ✓
- Token mapper + escaping policy → Task 1 (token table). ✓
- Block tokens via existing renderers → Task 1 (`render_line_items`/`render_totals`). ✓
- Store + kses preserving tokens → Task 2. ✓
- Dedicated mPDF wrapper → Task 3 Step 1. ✓
- Global per-doc-type override behind toggle → Task 3 (`visual_template_active` + branch + settings field). ✓
- REST save (cap + nonce) → Task 4. ✓
- Sample-data preview + real-PDF button → Task 6. ✓
- Error handling: empty-template fallback (`visual_template_active` returns false → legacy path), leftover-token stripping (Task 1 `merge`), kses allowlist (Task 2) → covered. ✓
- Default OFF / backward compatible → gate helper + toggle default unchecked. ✓
- Version bump → Task 7. ✓
- Testing (unit + manual PyMuPDF) → per-task tests + Task 6/7 manual. ✓

**Placeholder scan:** No "TBD"/"add error handling"/vague steps — each code step carries real code. Three spots require the implementer to *confirm an existing accessor by reading neighbouring code* (menu slug, plugin_url/path, the exact preview action, the body-build insertion point); these are explicit "confirm via grep/read" instructions with the search commands provided, not placeholders, because the surrounding code is the source of truth and must not be guessed.

**Type consistency:** `visual_template_active(string,bool,string):bool`, `TemplateTokens::map():array` / `merge(string,$doc):string`, `VisualTemplateStore::get(string):string` / `save(string,string):void` / `option_name(string):string` / `allowed_html():array`, `Rest::handle_visual_template_save($request):array|WP_Error` — all referenced consistently across tasks. Option name `woi_pdf_visual_template_invoice` and toggle key `enable_visual_template_invoice` are used identically in Tasks 2, 3, 5. ✓

## Out of scope (later slices)

Other document types, per-folder override models, real-order in-editor data, block-set hardening (required-token validation, page-break controls), the title-spacing tweak, and retiring `FontSynchronizer`/Noto `.ttf`/Dompdf.
