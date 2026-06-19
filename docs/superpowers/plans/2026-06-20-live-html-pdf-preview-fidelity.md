# Live-HTML ↔ PDF Preview Fidelity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Visual Template editor's Live HTML preview render the same layout as the actual mPDF output (columns, 13mm thumbnails, stacked bilingual labels), by fixing the visual PDF's CSS and giving both previews one shared stylesheet.

**Architecture:** A new static `templates/_visual/visual-document.css` is the single source of truth. The mPDF wrapper inlines it; the browser preview reads the same file (delivered via `woiVisual.previewCss`) and adds a small preview-only shim. The bilingual-label fix is a markup change in `TemplateTokens` (wrap the primary label in a block span) shared by both paths.

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce, mPDF (vendored via Strauss), GrapesJS + vanilla JS (`assets/visual-editor/app.js`), PHPUnit + Brain Monkey.

## Global Constraints

- PHP floor: **7.4** (no `enum`, no `readonly`, no first-class callable beyond `fn()`/`[$o,'m']`).
- Run PHPUnit with the ABSPATH prepend: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php` (it dies silently otherwise).
- Any change to `app.js` or CSS **requires** bumping `WOI_PDF_VERSION` (property `$version` line 24) **and** the plugin header `Version:` (line 6) in `woocommerce-orders-invoice-pdf.php`, or browsers serve stale cached assets. Target bump: **1.4.26 → 1.4.27**.
- The visual line-item/total cell `class` equals the column `type` (`thumbnail`, `quantity`, `sku`, `price`, `tax_rate`, `total`, `position`, `vat-split`) — the canonical CSS selectors.
- Do NOT modify the canonical `Standard UAE Tax Invoice` template or any other PDF template.
- mPDF needs **inline** CSS (no external `<link>`).

---

### Task 1: Wrap the primary bilingual label in a block span (`TemplateTokens`)

This is the actual fix for the jammed Arabic/English labels: mPDF only stacks the pair when **both** sides are block-level spans. Currently the English title is a bare text node.

**Files:**
- Modify: `includes/Visual/TemplateTokens.php` (`render_line_items()` ~lines 96-121, `render_totals()` ~lines 127-146)
- Test: `tests/Unit/Visual/TemplateTokensTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `{{line_items}}` headers now emit `<th class="…"><span class="woi-lbl-primary">{title}</span>[<span class="woi-lbl-secondary" dir="rtl">{secondary}</span>]</th>`. `{{totals}}` description cells now emit `<th class="description"><span><span class="woi-lbl-primary">{label}</span>[<span class="woi-lbl-secondary" dir="rtl">{secondary}</span>]</span></th>`.

- [ ] **Step 1: Write the failing tests**

Add these two methods to `tests/Unit/Visual/TemplateTokensTest.php` (before the final closing `}`):

```php
    /**
     * Bilingual column headers must wrap BOTH labels in block spans so mPDF
     * stacks them (English over Arabic) instead of jamming them on one line.
     */
    public function test_line_item_headers_wrap_primary_label_in_block_span(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array {
                return array( array( 'class' => 'total', 'title' => 'Total', 'secondary' => 'المبلغ' ) );
            }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );

        $this->assertStringContainsString( '<span class="woi-lbl-primary">Total</span>', $map['{{line_items}}'] );
        $this->assertStringContainsString( '<span class="woi-lbl-secondary" dir="rtl">المبلغ</span>', $map['{{line_items}}'] );
    }

    /**
     * Totals labels get the same block-span pairing. When no secondary exists,
     * the primary span still renders (single block span == old bare text).
     */
    public function test_totals_wrap_primary_label_and_render_without_secondary(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array {
                return array(
                    array( 'class' => 'total grand-total', 'label' => 'Total', 'value' => 'AED 10', 'secondary' => 'المجموع' ),
                    array( 'class' => 'subtotal', 'label' => 'Subtotal', 'value' => 'AED 8' ),
                );
            }
        };
        $map = $tokens->map( $this->stub_document() );

        $this->assertStringContainsString( '<span class="woi-lbl-primary">Total</span>', $map['{{totals}}'] );
        $this->assertStringContainsString( '<span class="woi-lbl-secondary" dir="rtl">المجموع</span>', $map['{{totals}}'] );
        // No-secondary row still renders its primary label.
        $this->assertStringContainsString( '<span class="woi-lbl-primary">Subtotal</span>', $map['{{totals}}'] );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter 'test_line_item_headers_wrap_primary_label_in_block_span|test_totals_wrap_primary_label_and_render_without_secondary'`
Expected: FAIL — assertions about `<span class="woi-lbl-primary">` are not found (current code emits bare `esc_html($title)`).

- [ ] **Step 3: Implement the markup change**

In `render_line_items()`, replace the header-building line:

```php
                $html .= '<th class="' . esc_attr( $header_data['class'] ?? '' ) . '">' . esc_html( $header_data['title'] ?? '' );
```

with:

```php
                $html .= '<th class="' . esc_attr( $header_data['class'] ?? '' ) . '"><span class="woi-lbl-primary">' . esc_html( $header_data['title'] ?? '' ) . '</span>';
```

In `render_totals()`, replace:

```php
                $html .= '<th class="description"><span>' . esc_html( $total_data['label'] ?? '' );
```

with:

```php
                $html .= '<th class="description"><span><span class="woi-lbl-primary">' . esc_html( $total_data['label'] ?? '' ) . '</span>';
```

(The existing `woi-lbl-secondary` span append and the closing `</span></th>` stay unchanged — the outer `<span>` in totals still closes correctly.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/TemplateTokensTest.php`
Expected: PASS (all methods, including the 4 pre-existing ones).

- [ ] **Step 5: Commit**

```bash
git add includes/Visual/TemplateTokens.php tests/Unit/Visual/TemplateTokensTest.php
git commit -m "fix: wrap primary bilingual label in block span so mPDF stacks labels

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Extract shared visual-document CSS + helper, wire mPDF wrapper

Creates the single source of truth and ports the canonical fidelity rules into it, then makes the mPDF wrapper inline it via a helper.

**Files:**
- Create: `templates/_visual/visual-document.css`
- Create: `tests/Unit/Visual/VisualDocumentCssTest.php`
- Modify: `woi-pdf-functions.php` (add `woi_pdf_visual_document_css()` — place near other `woi_pdf_templates_*` helpers, e.g. after `woi_pdf_templates_get_totals()` ~line 2928)
- Modify: `templates/_visual/visual-document-wrapper.php` (replace the inline `<style>` body)

**Interfaces:**
- Consumes: constant `WOI_PDF_PLUGIN_PATH` (defined in `woocommerce-orders-invoice-pdf.php:66`).
- Produces: `woi_pdf_visual_document_css(): string` — returns the contents of `visual-document.css`, or `''` if unreadable. Used by Task 3 (`VisualEditorPage::enqueue()`) and by the wrapper.

- [ ] **Step 1: Create the shared CSS file**

Create `templates/_visual/visual-document.css` with exactly this content (current wrapper rules + ported canonical fidelity rules):

```css
@page { margin: 15mm; }
body { font-family: "dejavusans", sans-serif; font-size: 11pt; color: #222; overflow-wrap: anywhere; }
/* Arabic shaping is handled natively by mPDF; the secondary-language
   font stack is registered by MpdfMaker. RTL spans carry dir="rtl". */
.woi-bilingual-secondary, [dir="rtl"] { direction: rtl; }
table { border-collapse: collapse; width: 100%; }

/* --- Invoice table fidelity (ported from Standard UAE Tax Invoice/style.css) --- */

/* Line-items table */
table.order-details { width: 100%; margin-top: 3mm; margin-bottom: 5mm; }
table.order-details th, table.order-details td { border: 0.5pt solid #000; padding: 2px 4px; }
.order-details th {
    font-weight: normal;
    text-align: inherit;
    border-bottom: 1px solid #000;
    border-top: 1px solid #000;
    padding-top: 0;
    overflow-wrap: normal;
}
.order-details td, .order-details th { padding: 0.375em; }

/* Column widths (cells default class == column type) */
.order-details .thumbnail,
.order-details .quantity,
.order-details .weight { width: 8%; }
.order-details .sku,
.order-details .price,
.order-details .regular_price,
.order-details .vat,
.order-details .discount,
.order-details .tax_rate,
.order-details .total { width: 10%; }
.order-details .vat-split { width: 12%; }
.order-details .position { width: 5%; }

/* Product thumbnails. !important beats WooCommerce's inline width/height attrs
   (this is why the canonical template uses !important here too). */
td.thumbnail img { width: 13mm !important; height: auto !important; }

/* Item meta (variations / personalisation lines) */
.wc-item-meta { margin: 4px 0; font-size: 7pt; line-height: 115%; }
.wc-item-meta p { display: inline; }
.wc-item-meta li { margin: 0; margin-left: 5px; }
dl { margin: 4px 0; }
dt, dd, dd p { display: inline; font-size: 7pt; line-height: 115%; }
dd { margin-left: 5px; }

/* Totals table */
table.totals-table { width: 100%; border-collapse: separate; }
table.totals-table th, table.totals-table td { border: 0; padding: 4px; }
table.totals-table th.description { text-align: inherit; vertical-align: top; min-width: 2cm; }
table.totals-table td.price { text-align: right; }
tr.grand-total td, tr.grand-total th { border-top: 1px solid #000; border-bottom: 1px solid #000; }

/* Bilingual label pairs (Arabic column headers / totals labels) */
.woi-lbl-primary { display: block; }
.woi-lbl-secondary { display: block; direction: rtl; }

/* --- Layout blocks (visual editor) --- */
.woi-pagebreak { page-break-after: always; height: 0; }
.woi-spacer { height: 12mm; }
.woi-row { width: 100%; }
.woi-row td { vertical-align: top; }
.totals-table { page-break-inside: avoid; }

/* --- Document title --- */
.woi-doc-title { text-align: center; margin: 4mm 0; }
.woi-doc-title .title-en,
.woi-doc-title .title-ar { font-size: 16pt; font-weight: bold; }
.woi-doc-title .title-ar { margin-left: 6mm; }
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Visual/VisualDocumentCssTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use PHPUnit\Framework\TestCase;

class VisualDocumentCssTest extends TestCase {

    private function repo_root(): string {
        return dirname( __DIR__, 3 ); // tests/Unit/Visual -> repo root
    }

    public function test_css_file_exists_and_has_fidelity_rules(): void {
        $css = (string) file_get_contents( $this->repo_root() . '/templates/_visual/visual-document.css' );

        $this->assertStringContainsString( 'width: 13mm !important', $css );
        $this->assertStringContainsString( '.woi-lbl-primary { display: block; }', $css );
        $this->assertStringContainsString( '.order-details .vat-split { width: 12%; }', $css );
    }

    public function test_helper_returns_css_contents(): void {
        if ( ! defined( 'WOI_PDF_PLUGIN_PATH' ) ) {
            define( 'WOI_PDF_PLUGIN_PATH', $this->repo_root() );
        }
        require_once $this->repo_root() . '/woi-pdf-functions.php';

        $css = woi_pdf_visual_document_css();
        $this->assertStringContainsString( 'width: 13mm !important', $css );
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/VisualDocumentCssTest.php`
Expected: `test_css_file_exists_and_has_fidelity_rules` PASSES (file created in Step 1); `test_helper_returns_css_contents` FAILS with "Call to undefined function woi_pdf_visual_document_css()".

> Note: if `woi-pdf-functions.php` requires WordPress functions at include time and fatals under PHPUnit, drop `test_helper_returns_css_contents` and rely on `test_css_file_exists_and_has_fidelity_rules` + the wrapper inlining (verified manually in Task 4). Check by running the command above; keep the helper test only if it loads cleanly.

- [ ] **Step 4: Add the helper function**

In `woi-pdf-functions.php`, after the `woi_pdf_templates_get_totals()` function block (~line 2928), add:

```php
if ( ! function_exists( 'woi_pdf_visual_document_css' ) ) {
	/**
	 * Single source of truth for the visual document stylesheet, shared by the
	 * mPDF wrapper (templates/_visual/visual-document-wrapper.php) and the
	 * browser Live HTML preview (VisualEditorPage::enqueue -> woiVisual.previewCss).
	 *
	 * @return string CSS text, or '' when the file is unreadable.
	 */
	function woi_pdf_visual_document_css() {
		$path = WOI_PDF_PLUGIN_PATH . '/templates/_visual/visual-document.css';
		return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}
}
```

- [ ] **Step 5: Wire the mPDF wrapper to inline the shared CSS**

In `templates/_visual/visual-document-wrapper.php`, replace the entire `<style>...</style>` block (lines 6-50) with:

```php
<style>
<?php echo woi_pdf_visual_document_css(); ?>
</style>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/VisualDocumentCssTest.php`
Expected: PASS (both methods, unless the helper test was dropped per the Step 3 note).

- [ ] **Step 7: Commit**

```bash
git add templates/_visual/visual-document.css templates/_visual/visual-document-wrapper.php woi-pdf-functions.php tests/Unit/Visual/VisualDocumentCssTest.php
git commit -m "feat: shared visual-document.css as single source of truth for PDF + preview

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Preview consumes shared CSS (`VisualEditorPage` + `app.js`)

Replaces the stale hardcoded `PREVIEW_CSS` with the shared stylesheet plus a clearly-separated preview-only shim, so the Live HTML tab matches the PDF.

**Files:**
- Modify: `includes/Visual/VisualEditorPage.php` (`enqueue()` `wp_localize_script` array, ~lines 81-93)
- Modify: `assets/visual-editor/app.js` (`PREVIEW_CSS` ~lines 647-655, `woiWrapForPreview()` ~lines 667-669)

**Interfaces:**
- Consumes: `woi_pdf_visual_document_css()` (Task 2).
- Produces: `woiVisual.previewCss` (string) available to `app.js`.

- [ ] **Step 1: Pass the shared CSS to JS**

In `includes/Visual/VisualEditorPage.php`, inside the `wp_localize_script( 'woi-visual-editor', 'woiVisual', array( ... ) )` call, add this entry (e.g. after `'starter' => $this->starter_html(),`):

```php
            'previewCss'      => woi_pdf_visual_document_css(),
```

- [ ] **Step 2: Replace `PREVIEW_CSS` and the preview wrapper in `app.js`**

Replace the `PREVIEW_CSS` definition (the `var PREVIEW_CSS = '...';` block, ~lines 647-655) with a preview-only shim constant:

```javascript
    // Preview-only shim layered ON TOP of the shared visual-document CSS
    // (woiVisual.previewCss). These rules exist solely because the browser
    // preview is a scrolling iframe, not paged media:
    //   - @page doesn't apply in an iframe, so simulate the 15mm page margin
    //     and the ~180mm content width mPDF lays out against.
    //   - the shared .woi-pagebreak rule is height:0 (invisible) — fine for a
    //     real PDF, useless in a continuous view, so show a dashed divider.
    var PREVIEW_SHIM_CSS =
        'body{max-width:210mm;margin:0 auto;padding:15mm;box-sizing:border-box;background:#fff}' +
        '.woi-pagebreak{border-top:1px dashed #999;margin:4mm 0;height:auto;page-break-after:auto}';
    // Fallback if the server failed to deliver the shared stylesheet.
    var PREVIEW_FALLBACK_CSS =
        'table{border-collapse:collapse;width:100%}' +
        '.order-details th,.order-details td{border:0.5pt solid #000;padding:0.375em}' +
        '.woi-lbl-primary,.woi-lbl-secondary{display:block}.woi-lbl-secondary{direction:rtl}';
```

Then replace `woiWrapForPreview()` (~lines 667-669):

```javascript
    function woiWrapForPreview( bodyHtml ) {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' + PREVIEW_CSS + '</style></head><body>' + bodyHtml + '</body></html>';
    }
```

with:

```javascript
    function woiWrapForPreview( bodyHtml ) {
        var docCss = ( woiVisual && woiVisual.previewCss ) ? woiVisual.previewCss : PREVIEW_FALLBACK_CSS;
        var css = docCss + PREVIEW_SHIM_CSS;
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' + css + '</style></head><body>' + bodyHtml + '</body></html>';
    }
```

- [ ] **Step 3: Verify no remaining references to `PREVIEW_CSS`**

Run: `grep -n "PREVIEW_CSS" assets/visual-editor/app.js`
Expected: **no output** (the old constant name is fully removed; only `PREVIEW_SHIM_CSS` / `PREVIEW_FALLBACK_CSS` remain).

- [ ] **Step 4: Commit**

```bash
git add includes/Visual/VisualEditorPage.php assets/visual-editor/app.js
git commit -m "feat: Live HTML preview consumes shared visual-document CSS + page shim

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Version bump + full verification

Bumps the asset cache-bust version and verifies the whole suite + a manual live render.

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php` (header `Version:` line 6, property `$version` line 24)

- [ ] **Step 1: Bump the version**

In `woocommerce-orders-invoice-pdf.php`, change line 6:

```php
 * Version:              1.4.27
```

and line 24:

```php
	public string $version     = '1.4.27';
```

- [ ] **Step 2: Run the full unit suite**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: PASS (no regressions; the new Task 1 + Task 2 tests included).

- [ ] **Step 3: Manual live verification (Live HTML vs PDF for order #237)**

Using the live testing harness (debug Chrome on port 9222 + the deployed branch), open the Visual Template editor, preview order #237, and confirm in the **Live HTML** tab:
- product thumbnails render small (~13mm), not full-size;
- the line-item columns have the same proportions as the PDF;
- bilingual column headers and totals labels **stack** (English over Arabic), matching the PDF tab.

Then switch to the **PDF** tab → Render PDF and confirm the same three now render cleanly there too (labels stacked, thumbnails 13mm, columns sized).

Expected: Live HTML and PDF now agree on layout (residual: Arabic glyph shape / line metrics differ due to browser font fallback — acceptable per spec).

- [ ] **Step 4: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php
git commit -m "chore: bump version to 1.4.27 (preview fidelity assets)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review notes

- **Spec coverage:** label fix → Task 1 (markup) + Task 2 CSS (`.woi-lbl-primary` block); image fix → Task 2 (`td.thumbnail img 13mm`); column fix → Task 2 (width rules); shared-CSS source of truth → Task 2 (file + helper + wrapper) & Task 3 (preview); page shim + visible page break → Task 3; tests → Tasks 1-2; version bump → Task 4. All spec sections mapped.
- **No placeholders:** every code/CSS/command step is concrete.
- **Type consistency:** `woi_pdf_visual_document_css()` defined in Task 2, consumed by name in Tasks 2-3; `woiVisual.previewCss` produced in Task 3 Step 1, consumed in Step 2; class names (`woi-lbl-primary`/`woi-lbl-secondary`) consistent across Tasks 1-3.
- **Note on JS:** repo has no JS unit runner, so `app.js` is verified by the grep guard (Task 3 Step 3) + manual live render (Task 4 Step 3).
