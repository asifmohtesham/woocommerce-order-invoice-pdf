# PDF Preview Download + "Sample" Watermark Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Download control to the settings PDF preview that saves the previewed PDF, with a single centered diagonal "SAMPLE" watermark stamped on every page of the preview (on-screen and downloaded).

**Architecture:** A new `PreviewWatermark` class stamps "SAMPLE" onto the live Dompdf object via the existing `woi_pdf_after_dompdf_render` filter. It is registered only during `OrderDocument::preview_pdf()`, so real/customer-facing PDFs are never touched. The browser already receives the full watermarked PDF as base64; a Download button saves that in-memory blob client-side with no extra server request.

**Tech Stack:** PHP 7.4+, Dompdf (vendored, Strauss-namespaced as `WOI\PDF\Vendor\Dompdf`), PHPUnit + Brain Monkey for unit tests, plain jQuery (`assets/js/admin.js`).

## Global Constraints

- PHP namespace root `WOI\PDF\` maps PSR-4 to `includes/` (composer.json).
- New PHP classes follow the codebase guard convention: `if ( ! defined( 'ABSPATH' ) ) { exit; }` and an `if ( ! class_exists( ... ) ) :` wrapper.
- PHPUnit MUST be run with the ABSPATH bootstrap or it dies silently: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`.
- Watermark default text is exactly `SAMPLE`. Filters: `woi_pdf_preview_watermark_enabled` (bool, default `true`), `woi_pdf_preview_watermark_text` (string, default `SAMPLE`).
- The watermark hook constant is `woi_pdf_after_dompdf_render` (priority 10, 4 args), matching `PDFMaker::output()`.
- All user-facing strings use the text domain `woocommerce-orders-invoice-pdf`.
- Download is PDF-only. No new AJAX endpoint, nonce, or server round-trip for download.

---

## File Structure

- **Create:** `includes/Makers/PreviewWatermark.php` — watermark logic + filter (un)registration. PSR-4 autoloaded as `WOI\PDF\Makers\PreviewWatermark`.
- **Create:** `tests/Unit/Makers/PreviewWatermarkTest.php` — unit tests for the class.
- **Modify:** `includes/Documents/OrderDocument.php` — `use` the class; register/unregister the watermark around the preview render in `preview_pdf()` (lines 1757-1773).
- **Modify:** `views/settings-page.php` — add the Download button to the `.preview-data-wrapper` toolbar (lines 166-198).
- **Modify:** `assets/js/admin.js` — cache the latest PDF base64, wire the Download click handler, toggle button visibility by output format (preview flow around lines 568-692).

---

### Task 1: `PreviewWatermark` class

Pure, fully unit-testable class. No WordPress or real Dompdf dependency in the tests — WP functions are stubbed with Brain Monkey and the Dompdf object is faked with anonymous classes that record `page_text()` calls.

**Files:**
- Create: `includes/Makers/PreviewWatermark.php`
- Test: `tests/Unit/Makers/PreviewWatermarkTest.php`

**Interfaces:**
- Consumes: nothing (leaf class).
- Produces:
  - `WOI\PDF\Makers\PreviewWatermark::register(): void` — adds the `woi_pdf_after_dompdf_render` filter (priority 10, 4 args) bound to `stamp_after_render`.
  - `WOI\PDF\Makers\PreviewWatermark::unregister(): void` — removes that exact filter.
  - `WOI\PDF\Makers\PreviewWatermark::stamp_after_render( $dompdf, $html = '', $options = null, $document = null )` — stamps the watermark and returns `$dompdf` unchanged (filter contract).
  - `WOI\PDF\Makers\PreviewWatermark::is_enabled(): bool`
  - `WOI\PDF\Makers\PreviewWatermark::get_text(): string`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Makers/PreviewWatermarkTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Makers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Makers\PreviewWatermark;

class PreviewWatermarkTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Default: apply_filters returns the passed default value (arg #2).
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fake Dompdf canvas that records page_text() calls.
	 */
	private function fakeCanvas() {
		return new class {
			public array $texts = array();
			public function get_width() { return 600.0; }
			public function get_height() { return 800.0; }
			public function page_text( $x, $y, $text, $font, $size, $color = array(), $ws = 0.0, $cs = 0.0, $angle = 0.0 ) {
				$this->texts[] = $text;
			}
		};
	}

	private function fakeFonts() {
		return new class {
			public function getFont( $family, $subtype = 'normal' ) { return 'font-handle'; }
			public function getTextWidth( $text, $font, $size, $ws = 0.0, $cs = 0.0 ) { return 200.0; }
		};
	}

	private function fakeDompdf( $canvas, $fonts ) {
		return new class( $canvas, $fonts ) {
			private $canvas;
			private $fonts;
			public function __construct( $c, $f ) { $this->canvas = $c; $this->fonts = $f; }
			public function getCanvas() { return $this->canvas; }
			public function getFontMetrics() { return $this->fonts; }
		};
	}

	public function test_is_enabled_defaults_true(): void {
		$this->assertTrue( PreviewWatermark::is_enabled() );
	}

	public function test_is_enabled_respects_filter(): void {
		Functions\when( 'apply_filters' )->alias( function( $hook, $value ) {
			return ( 'woi_pdf_preview_watermark_enabled' === $hook ) ? false : $value;
		} );
		$this->assertFalse( PreviewWatermark::is_enabled() );
	}

	public function test_get_text_defaults_to_sample(): void {
		$this->assertSame( 'SAMPLE', PreviewWatermark::get_text() );
	}

	public function test_get_text_respects_filter(): void {
		Functions\when( 'apply_filters' )->alias( function( $hook, $value ) {
			return ( 'woi_pdf_preview_watermark_text' === $hook ) ? 'DRAFT' : $value;
		} );
		$this->assertSame( 'DRAFT', PreviewWatermark::get_text() );
	}

	public function test_stamp_returns_same_dompdf(): void {
		$dompdf = $this->fakeDompdf( $this->fakeCanvas(), $this->fakeFonts() );
		$this->assertSame( $dompdf, PreviewWatermark::stamp_after_render( $dompdf ) );
	}

	public function test_stamp_draws_text_when_enabled(): void {
		$canvas = $this->fakeCanvas();
		$dompdf = $this->fakeDompdf( $canvas, $this->fakeFonts() );
		PreviewWatermark::stamp_after_render( $dompdf );
		$this->assertSame( array( 'SAMPLE' ), $canvas->texts );
	}

	public function test_stamp_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias( function( $hook, $value ) {
			return ( 'woi_pdf_preview_watermark_enabled' === $hook ) ? false : $value;
		} );
		$canvas = $this->fakeCanvas();
		$dompdf = $this->fakeDompdf( $canvas, $this->fakeFonts() );
		PreviewWatermark::stamp_after_render( $dompdf );
		$this->assertSame( array(), $canvas->texts );
	}

	public function test_register_adds_filter(): void {
		Functions\expect( 'add_filter' )->once()->with(
			'woi_pdf_after_dompdf_render',
			array( PreviewWatermark::class, 'stamp_after_render' ),
			10,
			4
		);
		PreviewWatermark::register();
	}

	public function test_unregister_removes_filter(): void {
		Functions\expect( 'remove_filter' )->once()->with(
			'woi_pdf_after_dompdf_render',
			array( PreviewWatermark::class, 'stamp_after_render' ),
			10
		);
		PreviewWatermark::unregister();
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Makers/PreviewWatermarkTest.php`
Expected: FAIL — `Class "WOI\PDF\Makers\PreviewWatermark" not found`.

- [ ] **Step 3: Write the implementation**

Create `includes/Makers/PreviewWatermark.php`:

```php
<?php
namespace WOI\PDF\Makers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WOI\\PDF\\Makers\\PreviewWatermark' ) ) :

/**
 * Stamps a "SAMPLE" watermark onto the preview PDF.
 *
 * Registered only during OrderDocument::preview_pdf() via the
 * woi_pdf_after_dompdf_render filter, so real (customer-facing) PDFs are
 * never affected.
 */
class PreviewWatermark {

	const HOOK = 'woi_pdf_after_dompdf_render';

	/**
	 * Attach the watermark to the post-render filter.
	 */
	public static function register(): void {
		add_filter( self::HOOK, array( self::class, 'stamp_after_render' ), 10, 4 );
	}

	/**
	 * Detach the watermark from the post-render filter.
	 */
	public static function unregister(): void {
		remove_filter( self::HOOK, array( self::class, 'stamp_after_render' ), 10 );
	}

	/**
	 * Whether the watermark should be drawn.
	 */
	public static function is_enabled(): bool {
		return (bool) apply_filters( 'woi_pdf_preview_watermark_enabled', true );
	}

	/**
	 * The watermark text.
	 */
	public static function get_text(): string {
		return (string) apply_filters( 'woi_pdf_preview_watermark_text', 'SAMPLE' );
	}

	/**
	 * Draw the watermark on every page of the rendered Dompdf document.
	 *
	 * Matches the woi_pdf_after_dompdf_render filter signature and returns the
	 * Dompdf object unchanged.
	 *
	 * @param object      $dompdf   The rendered Dompdf instance.
	 * @param string      $html     Source HTML (unused).
	 * @param object|null $options  Dompdf options (unused).
	 * @param object|null $document The order document (unused).
	 * @return object
	 */
	public static function stamp_after_render( $dompdf, $html = '', $options = null, $document = null ) {
		if ( ! self::is_enabled() ) {
			return $dompdf;
		}

		$text   = self::get_text();
		$canvas = $dompdf->getCanvas();
		$fonts  = $dompdf->getFontMetrics();
		$font   = $fonts->getFont( 'DejaVu Sans', 'normal' );

		$size  = 72.0;
		$color = array( 0.8, 0.8, 0.8 ); // light gray; page_text has no true alpha
		$angle = 45.0;

		$width      = $canvas->get_width();
		$height     = $canvas->get_height();
		$text_width = $fonts->getTextWidth( $text, $font, $size );

		// Center the rotated text roughly on the page.
		$x = ( $width - ( $text_width * cos( deg2rad( $angle ) ) ) ) / 2;
		$y = ( $height + ( $text_width * sin( deg2rad( $angle ) ) ) ) / 2;

		// page_text() applies the text to EVERY page of the document.
		$canvas->page_text( $x, $y, $text, $font, $size, $color, 0.0, 0.0, $angle );

		return $dompdf;
	}
}

endif; // class_exists
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Makers/PreviewWatermarkTest.php`
Expected: PASS — 9 tests, no failures.

- [ ] **Step 5: Commit**

```bash
git add includes/Makers/PreviewWatermark.php tests/Unit/Makers/PreviewWatermarkTest.php
git commit -m "feat: PreviewWatermark stamps SAMPLE on preview PDFs

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Wire the watermark into `preview_pdf()`

Register the watermark just before the preview render and unregister immediately after, so only the preview path is affected. This is integration wiring on a heavyweight method (requires a real order + Dompdf to run end-to-end), so it is verified manually; the register/unregister mechanism itself is already unit-tested in Task 1.

**Files:**
- Modify: `includes/Documents/OrderDocument.php` (`use` block at line 4-6; `preview_pdf()` at lines 1757-1773)

**Interfaces:**
- Consumes: `PreviewWatermark::register()`, `PreviewWatermark::unregister()` from Task 1.
- Produces: nothing new (behavioral change to existing `preview_pdf()`).

- [ ] **Step 1: Add the `use` import**

In `includes/Documents/OrderDocument.php`, add to the existing `use` block (after line 6):

```php
use WOI\PDF\Documents\BilingualLabelTrait;
use WOI\PDF\Makers\PreviewWatermark;
```

- [ ] **Step 2: Register/unregister around the preview render**

In `preview_pdf()` (lines 1769-1772), replace:

```php
		$pdf_maker = woi_pdf_get_pdf_maker( $this->get_html(), $pdf_settings, $this );
		$pdf       = $pdf_maker->output();

		return $pdf;
```

with:

```php
		$pdf_maker = woi_pdf_get_pdf_maker( $this->get_html(), $pdf_settings, $this );

		// Stamp a "SAMPLE" watermark onto the preview only.
		PreviewWatermark::register();
		$pdf = $pdf_maker->output();
		PreviewWatermark::unregister();

		return $pdf;
```

- [ ] **Step 3: Verify the full suite still passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: PASS — all tests green (no regressions).

- [ ] **Step 4: Manual verification**

In a WordPress dev site with this plugin active and at least one order:
1. Open the plugin settings → a tab with the live preview (e.g. Documents → Invoice).
2. Confirm the on-screen preview now shows a faint diagonal "SAMPLE" across the page.
3. Generate a real invoice for an order (front-end My-Account download or order email) and confirm it has **no** watermark.

- [ ] **Step 5: Commit**

```bash
git add includes/Documents/OrderDocument.php
git commit -m "feat: watermark the settings preview PDF only

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Download button markup

Add a disabled-by-default Download button to the preview toolbar. The label lives in the PHP view (no JS string needed).

**Files:**
- Modify: `views/settings-page.php` (`.preview-data-wrapper`, lines 166-192)

**Interfaces:**
- Consumes: nothing.
- Produces: a `button.woi-preview-download` element inside `.preview-data-wrapper`, consumed by Task 4's JS.

- [ ] **Step 1: Add the button**

In `views/settings-page.php`, inside `.preview-data-wrapper`, immediately after the `preview-document-type` block (after line 191 `</div>`, before the closing `</div>` of `.preview-data-wrapper` at line 192), add:

```php
							<div class="preview-data preview-download">
								<button type="button" class="button woi-preview-download" disabled
									title="<?php esc_attr_e( 'Download the preview as a watermarked sample PDF.', 'woocommerce-orders-invoice-pdf' ); ?>">
									<?php esc_html_e( 'Download', 'woocommerce-orders-invoice-pdf' ); ?>
								</button>
							</div>
```

- [ ] **Step 2: Verify it renders**

Load the plugin settings preview page in a browser. Expected: a disabled "Download" button appears in the preview toolbar alongside the order/document-type pickers.

- [ ] **Step 3: Commit**

```bash
git add views/settings-page.php
git commit -m "feat: add Download button to preview toolbar

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Download click handler + visibility (JS)

Cache the latest PDF base64 when a PDF preview loads, save it as a file on click, and show the button only in PDF mode. No JS test harness exists in this repo, so this is verified manually.

**Files:**
- Modify: `assets/js/admin.js` (preview module; success callback ~lines 651-682; output-format toggle handling ~lines 568-608)

**Interfaces:**
- Consumes: `button.woi-preview-download` from Task 3; `response.data.preview_data` (base64 PDF) and `response.data.output_format` from the existing `woi_pdf_preview` AJAX response.
- Produces: nothing for later tasks (terminal task).

- [ ] **Step 1: Add a module-level cache + button reference**

Near the other preview variables (after line 120, `$previewOutputFormatInput`), add:

```javascript
	let $previewDownloadBtn       = $( '#woi-pdf-preview-wrapper .woi-preview-download' );
	let lastPreviewPdfBase64      = null;
```

- [ ] **Step 2: Helper to set the button state**

Add this helper inside the preview scope (e.g. just before `ajaxLoadPreview`, around line 616):

```javascript
	// Enable the Download button only when a PDF preview is loaded and PDF is the active format.
	function updateDownloadButton() {
		let isPdf = ( previewOutputFormat === 'pdf' );
		$previewDownloadBtn.toggle( isPdf );
		$previewDownloadBtn.prop( 'disabled', ! ( isPdf && lastPreviewPdfBase64 ) );
	}
```

- [ ] **Step 3: Invalidate the cache when a load starts**

In `ajaxLoadPreview`, right after `console.log( 'Loading preview...' );` (line 618), add:

```javascript
		lastPreviewPdfBase64 = null;
		updateDownloadButton();
```

- [ ] **Step 4: Cache the base64 on a successful PDF load**

In the success handler, inside the `case 'pdf':` block (after `renderPdf( worker, canvasId, response.data.preview_data );`, line 662), add:

```javascript
								lastPreviewPdfBase64 = response.data.preview_data;
								updateDownloadButton();
```

And in the `case 'xml':` block (after line 675 `Prism.highlightElement( $code[0] );`), add:

```javascript
								lastPreviewPdfBase64 = null;
								updateDownloadButton();
```

- [ ] **Step 5: Wire the download click handler**

Add near the other preview event bindings (e.g. after the output-format toggle handler, around line 609):

```javascript
	// Save the cached preview PDF as a watermarked sample file.
	$previewDownloadBtn.on( 'click', function() {
		if ( ! lastPreviewPdfBase64 ) {
			return;
		}

		let binary = window.atob( lastPreviewPdfBase64 );
		let bytes  = new Uint8Array( binary.length );
		for ( let i = 0; i < binary.length; i++ ) {
			bytes[ i ] = binary.charCodeAt( i );
		}

		let blob = new Blob( [ bytes ], { type: 'application/pdf' } );
		let url  = window.URL.createObjectURL( blob );
		let $a   = $( '<a>', {
			href:     url,
			download: previewDocumentType + '-preview-sample.pdf'
		} ).appendTo( 'body' );

		$a[0].click();
		$a.remove();
		window.URL.revokeObjectURL( url );
	} );
```

- [ ] **Step 6: Keep the button in sync when the format toggles**

In the output-format change handler (the block ending with `triggerPreview();` at line 607, inside the `.on( 'change', ... )` for the format input around lines 588-608), add right before `triggerPreview();` (line 607):

```javascript
			updateDownloadButton();
```

- [ ] **Step 7: Manual verification**

In a WordPress dev site:
1. Open the settings preview (PDF format). After it loads, the "Download" button becomes enabled.
2. Click Download → a file `invoice-preview-sample.pdf` (or current document type) is saved. Open it → every page shows the diagonal "SAMPLE" watermark.
3. Switch a document type / order → button briefly disables during reload, re-enables when the new preview loads.
4. Switch output format to XML → the Download button is hidden. Switch back to PDF → it reappears and works.

- [ ] **Step 8: Commit**

```bash
git add assets/js/admin.js
git commit -m "feat: download cached preview PDF as watermarked sample

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review Notes

- **Spec coverage:** Server-side watermark via `woi_pdf_after_dompdf_render`, preview-only registration, `_enabled`/`_text` filters, default `SAMPLE`, single centered diagonal style → Task 1 + Task 2. Download button → Task 3. Client-side blob save, PDF-only visibility, no extra request, filename → Task 4. XML preview unaffected (Task 1 only stamps PDF render path; Task 4 hides button in XML mode).
- **Type consistency:** `register()`/`unregister()`/`stamp_after_render()`/`is_enabled()`/`get_text()` names are used identically across Task 1 (definition), Task 1 tests, and Task 2 (wiring). JS `lastPreviewPdfBase64`, `updateDownloadButton`, `$previewDownloadBtn` are consistent across all Task 4 steps. Button class `woi-preview-download` matches between Task 3 (markup) and Task 4 (selector).
- **No placeholders:** every code step shows complete code; every run step gives the exact command (including the `-d auto_prepend_file=tests/bootstrap.php` flag) and expected result.
