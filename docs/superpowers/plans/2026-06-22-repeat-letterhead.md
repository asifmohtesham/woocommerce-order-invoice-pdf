# Repeat Letterhead On Every Page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a global toggle that repeats the visual-invoice letterhead banner at the top of every PDF page (currently only page 1).

**Architecture:** Mirror the existing mPDF running-footer mechanism. A new `repeat_letterhead` doc-option drives a running page-header (`<htmlpageheader name="woiHeader">` emitted by the wrapper) assigned via `@page { header: woiHeader }`. When on, the body `{{letterhead}}` token is suppressed so page 1 isn't doubled.

**Tech Stack:** PHP 8.1 (WordPress plugin), Brain Monkey + PHPUnit 9.5 unit tests, React/`@wordpress/scripts` (webpack) for the block editor, vendored mPDF, Python (PyMuPDF) for raster verification.

## Global Constraints

- Scope is the **visual invoice only** — do not touch classic templates or non-invoice document types.
- Default is **off** — with the toggle off, generated output must be byte-identical to today.
- Doc-options follow the existing `borders`/`stripes` on/off pattern (string `'on'`/`'off'`, whitelisted in `woi_pdf_visual_doc_options()`).
- The running-element assignment must use `@page { header: woiHeader }` (the all-pages method), not `<sethtmlpageheader>` (current-page-forward only).
- Bump `WOI_PDF_VERSION` (plugin header `Version:`) as the shared asset cache-bust key. Current released version: **1.5.58** → bump to **1.5.59**.
- Run the PHP test suite with: `vendor/bin/phpunit --filter <TestName>` from the repo root.

---

## File Structure

- `woi-pdf-functions.php` — `woi_pdf_visual_doc_options()` (new option + whitelist) and `woi_pdf_visual_options_css()` (new `@page` rule).
- `includes/Visual/TemplateTokens.php` — `repeat_letterhead_enabled()` seam, `map()` suppression, `running_header()`.
- `templates/_visual/visual-document-wrapper.php` — emit the running header when the option is on.
- `src/block-editor/index.js` — toggle UI + default; rebuilt into `assets/js/block-editor/index.js`.
- `tools/render-visual-sample.php` — harness support for the 2-page manual check.
- `tests/Unit/Visual/VisualDocOptionsTest.php` (new), `tests/Unit/Visual/VisualOptionsCssTest.php`, `tests/Unit/Visual/TemplateTokensTest.php` — tests.
- `woocommerce-orders-invoice-pdf.php` — version bump.

---

### Task 1: Doc-option `repeat_letterhead` (defaults + whitelist)

**Files:**
- Modify: `woi-pdf-functions.php` (`woi_pdf_visual_doc_options()`, ~lines 3064-3092)
- Test: `tests/Unit/Visual/VisualDocOptionsTest.php` (create)

**Interfaces:**
- Produces: `woi_pdf_visual_doc_options( 'invoice' )['repeat_letterhead']` returns `'on'` or `'off'` (default `'off'`).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Visual/VisualDocOptionsTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * woi_pdf_visual_doc_options() resolves + whitelists the visual document's
 * presentation options. These guard the repeat-letterhead toggle.
 */
class VisualDocOptionsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_repeat_letterhead_defaults_off(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        $opts = woi_pdf_visual_doc_options( 'invoice' );
        $this->assertSame( 'off', $opts['repeat_letterhead'] );
    }

    public function test_repeat_letterhead_accepts_on(): void {
        Functions\when( 'get_option' )->justReturn( array( 'repeat_letterhead' => 'on' ) );
        $this->assertSame( 'on', woi_pdf_visual_doc_options( 'invoice' )['repeat_letterhead'] );
    }

    public function test_repeat_letterhead_rejects_junk(): void {
        Functions\when( 'get_option' )->justReturn( array( 'repeat_letterhead' => 'maybe' ) );
        $this->assertSame( 'off', woi_pdf_visual_doc_options( 'invoice' )['repeat_letterhead'] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter VisualDocOptionsTest`
Expected: FAIL — `test_repeat_letterhead_defaults_off` fails with "Undefined array key 'repeat_letterhead'" (key not yet in defaults).

- [ ] **Step 3: Add the option to defaults and whitelist**

In `woi-pdf-functions.php`, in the `$defaults` array of `woi_pdf_visual_doc_options()` (after the `'stripes' => 'off',` line ~3072):

```php
			'stripes' => 'off',           // on | off — striped (zebra) row colour
			'repeat_letterhead' => 'off', // on | off — repeat letterhead on every page
```

And in the `$allowed` array (after the `'stripes' => array( 'on', 'off' ),` line ~3091):

```php
			'stripes' => array( 'on', 'off' ),
			'repeat_letterhead' => array( 'on', 'off' ),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter VisualDocOptionsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add woi-pdf-functions.php tests/Unit/Visual/VisualDocOptionsTest.php
git commit -m "feat(visual): add repeat_letterhead doc-option (default off)"
```

---

### Task 2: `@page` header rule in `woi_pdf_visual_options_css()`

**Files:**
- Modify: `woi-pdf-functions.php` (`woi_pdf_visual_options_css()`, before the `return implode` at ~line 3160)
- Test: `tests/Unit/Visual/VisualOptionsCssTest.php` (~line 39, add tests)

**Interfaces:**
- Consumes: `$opts['repeat_letterhead']` from Task 1.
- Produces: when `'on'`, the CSS string contains `@page { header: woiHeader; margin-top: 34mm; }`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Visual/VisualOptionsCssTest.php` (after `test_borders_and_stripes_off_by_default`, before the closing brace):

```php
    public function test_repeat_letterhead_on_emits_page_header_rule(): void {
        $css = woi_pdf_visual_options_css( array( 'repeat_letterhead' => 'on' ) + $this->base() );
        $this->assertStringContainsString( '@page { header: woiHeader;', $css );
        $this->assertStringContainsString( 'margin-top: 34mm', $css );
    }

    public function test_repeat_letterhead_off_emits_no_header_rule(): void {
        $css = woi_pdf_visual_options_css( $this->base() );
        $this->assertStringNotContainsString( 'woiHeader', $css );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter VisualOptionsCssTest`
Expected: FAIL — `test_repeat_letterhead_on_emits_page_header_rule` fails (string not found).

- [ ] **Step 3: Emit the rule**

In `woi-pdf-functions.php`, in `woi_pdf_visual_options_css()`, immediately before `return implode( "\n", $css );` (~line 3160), add:

```php
		// --- Repeat letterhead: assign the running page-header on every page and
		// reserve top-margin space for the banner (mPDF merges @page blocks). ---
		if ( 'on' === ( $opts['repeat_letterhead'] ?? 'off' ) ) {
			$css[] = '@page { header: woiHeader; margin-top: 34mm; }';
		}

		return implode( "\n", $css );
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter VisualOptionsCssTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add woi-pdf-functions.php tests/Unit/Visual/VisualOptionsCssTest.php
git commit -m "feat(visual): emit @page header rule when repeat_letterhead is on"
```

---

### Task 3: `TemplateTokens` — running header + body suppression

**Files:**
- Modify: `includes/Visual/TemplateTokens.php` (`map()` at ~line 66; add new methods)
- Test: `tests/Unit/Visual/TemplateTokensTest.php` (add tests)

**Interfaces:**
- Consumes: `woi_pdf_visual_doc_options('invoice')['repeat_letterhead']` (Task 1), `section_letterhead()` (existing), `BilingualEngine::instance()` (existing import).
- Produces:
  - `protected repeat_letterhead_enabled(): bool` — overridable test seam.
  - `public running_header( $document ): string` — `<htmlpageheader name="woiHeader">…letterhead…</htmlpageheader>`.
  - `map()['{{letterhead}}']` is `''` when repeat is on, full `<table class="woi-letterhead">` markup when off.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Visual/TemplateTokensTest.php` (before the final closing brace of the class):

```php
    public function test_letterhead_token_present_when_repeat_off(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
            protected function repeat_letterhead_enabled(): bool { return false; }
        };
        $map = $tokens->map( $this->stub_document() );
        $this->assertStringContainsString( '<table class="woi-letterhead">', $map['{{letterhead}}'] );
    }

    public function test_letterhead_token_empty_when_repeat_on(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
            protected function repeat_letterhead_enabled(): bool { return true; }
        };
        $map = $tokens->map( $this->stub_document() );
        $this->assertSame( '', $map['{{letterhead}}'] );
    }

    public function test_running_header_wraps_letterhead(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $html = $tokens->running_header( $this->stub_document() );
        $this->assertStringStartsWith( '<htmlpageheader name="woiHeader">', $html );
        $this->assertStringContainsString( '<table class="woi-letterhead">', $html );
        $this->assertStringEndsWith( '</htmlpageheader>', $html );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TemplateTokensTest`
Expected: FAIL — `test_letterhead_token_empty_when_repeat_on` fails (token still contains the table) and `test_running_header_wraps_letterhead` fails ("Call to undefined method … running_header()").

- [ ] **Step 3: Add the seam + running header, and gate the token**

In `includes/Visual/TemplateTokens.php`, change the `{{letterhead}}` line in `map()` (~line 66) from:

```php
            '{{letterhead}}'       => $this->section_letterhead( $document, $engine ),
```

to:

```php
            '{{letterhead}}'       => $this->repeat_letterhead_enabled() ? '' : $this->section_letterhead( $document, $engine ),
```

Then add these two methods immediately after `section_letterhead()` (after its closing brace ~line 199):

```php
    /**
     * Whether the repeat-letterhead toggle is on. When on, the body letterhead
     * token is suppressed and the banner is emitted as a running page-header
     * instead (see running_header()). Overridable as a test seam.
     */
    protected function repeat_letterhead_enabled(): bool {
        if ( ! function_exists( 'woi_pdf_visual_doc_options' ) ) {
            return false;
        }
        $opts = woi_pdf_visual_doc_options( 'invoice' );
        return isset( $opts['repeat_letterhead'] ) && 'on' === $opts['repeat_letterhead'];
    }

    /**
     * mPDF running page-header carrying the letterhead banner. The wrapper emits
     * this (when the toggle is on) BEFORE the content so it applies from page 1;
     * visual-document.css / woi_pdf_visual_options_css assigns it via
     * @page { header: woiHeader }. Mirrors running_footer(). No inline output.
     */
    public function running_header( $document ): string {
        $banner = $this->section_letterhead( $document, BilingualEngine::instance() );
        return '<htmlpageheader name="woiHeader">' . $banner . '</htmlpageheader>';
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter TemplateTokensTest`
Expected: PASS (all tests, including the 3 new ones — pre-existing letterhead test still passes because the default seam resolves to off under the test's `get_option` stub).

- [ ] **Step 5: Commit**

```bash
git add includes/Visual/TemplateTokens.php tests/Unit/Visual/TemplateTokensTest.php
git commit -m "feat(visual): TemplateTokens running_header + body letterhead suppression"
```

---

### Task 4: Wrapper emits the running header

**Files:**
- Modify: `templates/_visual/visual-document-wrapper.php` (lines 17-24)

**Interfaces:**
- Consumes: `running_header()` (Task 3), `$woi_doc_opts['repeat_letterhead']` (Task 1, already resolved at line 3-5 of the wrapper).

No isolated unit test (PHP template render); verified end-to-end in Task 6.

- [ ] **Step 1: Edit the wrapper**

Replace the PHP block at `templates/_visual/visual-document-wrapper.php` lines 17-24 (the `if ( isset( $document ) … echo $content;` block) with:

```php
<?php
// Running page-footer (accurate Page X of Y on every page) and, when the
// repeat-letterhead toggle is on, a running page-header carrying the letterhead
// banner — both registered before the content so they apply from page 1.
// mPDF only; no inline output. The browser canvas never sees this.
if ( isset( $document ) && is_object( $document ) ) {
	$woi_tokens = new \WOI\PDF\Visual\TemplateTokens();
	if ( 'on' === ( $woi_doc_opts['repeat_letterhead'] ?? 'off' ) ) {
		echo $woi_tokens->running_header( $document );
	}
	echo $woi_tokens->running_footer( $document );
}
echo $content; // already token-merged + sanitised on save
?>
```

- [ ] **Step 2: Lint the file**

Run: `php -l templates/_visual/visual-document-wrapper.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/_visual/visual-document-wrapper.php
git commit -m "feat(visual): wrapper emits running letterhead header when toggle on"
```

---

### Task 5: Block-editor toggle + build

**Files:**
- Modify: `src/block-editor/index.js` (`DEFAULT_DOC_OPTIONS` ~line 154; Settings panel ~line 423)
- Build artifact: `assets/js/block-editor/index.js` (regenerated by webpack)

**Interfaces:**
- Produces: a `ToggleControl` writing `repeat_letterhead` `'on'`/`'off'` via the existing `onDocOption` → `saveDocOptions` REST path; `DEFAULT_DOC_OPTIONS.repeat_letterhead = 'off'`.

- [ ] **Step 1: Add the default**

In `src/block-editor/index.js`, in `DEFAULT_DOC_OPTIONS` (after `stripes: 'off',` ~line 155):

```js
		borders: 'off',
		stripes: 'off',
		repeat_letterhead: 'off',
	};
```

- [ ] **Step 2: Add the toggle control**

In `src/block-editor/index.js`, immediately after the "Striped rows" `<ToggleControl … />` block (closes ~line 423), add:

```jsx
							<ToggleControl
								label={ __( 'Repeat letterhead on every page', 'woocommerce-orders-invoice-pdf' ) }
								help={ __( 'Shows the letterhead at the top of every PDF page. The preview shows it once; the effect appears in the generated PDF.', 'woocommerce-orders-invoice-pdf' ) }
								checked={ 'on' === docOptions.repeat_letterhead }
								onChange={ ( v ) => onDocOption( 'repeat_letterhead', v ? 'on' : 'off' ) }
								__nextHasNoMarginBottom
							/>
```

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: build completes with no errors; `assets/js/block-editor/index.js` is regenerated.

- [ ] **Step 4: Verify the option string is in the built bundle**

Run: `grep -c "repeat_letterhead" assets/js/block-editor/index.js`
Expected: a non-zero count (the option name is present in the compiled output).

- [ ] **Step 5: Commit**

```bash
git add src/block-editor/index.js assets/js/block-editor/index.js
git commit -m "feat(block-editor): repeat letterhead on every page toggle"
```

---

### Task 6: Harness support, 2-page manual verification, version bump

**Files:**
- Modify: `tools/render-visual-sample.php`
- Modify: `woocommerce-orders-invoice-pdf.php` (version header)

**Interfaces:**
- Consumes: `woi_pdf_visual_options_css()` with `repeat_letterhead` (Task 2).

- [ ] **Step 1: Add a `repeat` arg + running header to the harness**

In `tools/render-visual-sample.php`:

(a) After `$stripes = $argv[7] ?? 'off';` (~line 28) add:

```php
$repeat  = $argv[8] ?? 'off';   // on|off — repeat letterhead on every page
```

(b) In the `woi_pdf_visual_options_css( array( … ) )` call (~lines 35-39) add the key:

```php
        'borders' => $borders, 'stripes' => $stripes,
        'repeat_letterhead' => $repeat,
    ) );
```

(c) After `$body = preg_replace( '/\{\{[^}]*\}\}/', '', $body );` (~line 87) add — this mirrors production "move the banner into the running header" (the starter HTML carries the letterhead as literal markup, so we relocate it):

```php
// Repeat letterhead: relocate the body banner into a running page-header
// (mirrors TemplateTokens::running_header + the suppressed body token).
$running_header = '';
if ( 'on' === $repeat && preg_match( '/<table class="woi-letterhead">.*?<\/table>/s', $body, $m ) ) {
    $running_header = '<htmlpageheader name="woiHeader">' . $m[0] . '</htmlpageheader>';
    $body = preg_replace( '/<table class="woi-letterhead">.*?<\/table>/s', '', $body, 1 );
}
```

(d) In the `$html = …` assembly (~lines 99-101) insert `$running_header` before `$footer`:

```php
      . $running_header . $footer . $body . $force2 . '</body></html>';
```

- [ ] **Step 2: Render a 2-page sample with repeat on**

Run: `php tools/render-visual-sample.php navy center comfortable on on 2page off on`
(argv6=`2page` forces a second page; argv8=`on` enables repeat.)
Expected: `OK -> …/tmp-visual-sample.pdf (… bytes)`.

- [ ] **Step 3: Rasterize and inspect both pages**

Run: `python tools/rasterize.py tmp-visual-sample.pdf %TEMP%/repeat-lh`
Then open `%TEMP%/repeat-lh-1.png` and `%TEMP%/repeat-lh-2.png`.
Expected/confirm:
  - the letterhead banner appears at the **top of page 1 AND page 2**;
  - the banner is **not duplicated** on page 1 (not both in the top margin and in the body);
  - page-1 body content (contact strip / title) is **not clipped** under the banner — if it overlaps, increase `margin-top` in Task 2's rule (e.g. 34mm → 38mm) and re-render;
  - the running footer still renders on both pages.

  Contingency: if no banner appears on either page, mPDF did not honour the merged `@page { header: woiHeader }`. In that case change Task 2's rule to emit the full page box in one block — `@page { margin: 15mm; margin-bottom: 18mm; margin-top: 34mm; header: woiHeader; footer: woiFooter; }` — re-run the suite for VisualOptionsCssTest (update the assertion string accordingly) and re-render.

- [ ] **Step 4: Confirm repeat OFF is unchanged**

Run: `php tools/render-visual-sample.php navy center comfortable on on 2page off off`
Then: `python tools/rasterize.py tmp-visual-sample.pdf %TEMP%/repeat-off`
Expected: page 1 has the letterhead in the body (as today); page 2 has no letterhead. No `woiHeader` behaviour.

- [ ] **Step 5: Bump the version**

In `woocommerce-orders-invoice-pdf.php` line 6, change `Version:              1.5.58` to `Version:              1.5.59`.

Run: `grep -rn "1\.5\.58" woocommerce-orders-invoice-pdf.php`
Expected: no remaining matches (confirm there is no other hardcoded occurrence to update).

- [ ] **Step 6: Run the full visual test suite**

Run: `vendor/bin/phpunit --filter Visual`
Expected: VisualDocOptionsTest, VisualOptionsCssTest, TemplateTokensTest all PASS (no new failures vs. the pre-existing baseline).

- [ ] **Step 7: Commit**

```bash
git add tools/render-visual-sample.php woocommerce-orders-invoice-pdf.php
git commit -m "chore: harness repeat-letterhead support + bump to v1.5.59"
```

---

## Self-Review

**Spec coverage:**
- Doc-option `repeat_letterhead` (defaults + whitelist) → Task 1. ✓
- Block-editor toggle → Task 5. ✓
- Running header + body `{{letterhead}}` suppression → Task 3. ✓
- Wrapper emission → Task 4. ✓
- `@page` header + margin-top geometry → Task 2 (value tuned in Task 6). ✓
- Default-off byte-identical behavior → Task 6 Step 4 confirms. ✓
- Arabic-off / no-logo edges → covered by reusing `section_letterhead()` + existing flat-selector CSS (no new code); verified visually in Task 6. ✓
- Canvas-unaffected + GrapesJS-literal limitation → no code (documented in spec); toggle help text added in Task 5. ✓
- Version bump → Task 6 Step 5. ✓
- Unit + manual testing → Tasks 1-3 (unit), Task 6 (manual harness). ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code; the one tunable (margin-top) has a concrete starting value and an explicit adjustment rule.

**Type consistency:** `repeat_letterhead` (string `'on'`/`'off'`) used identically across `woi_pdf_visual_doc_options`, `woi_pdf_visual_options_css`, `repeat_letterhead_enabled()`, the wrapper, and the JS toggle. The running element name `woiHeader` matches between `running_header()` (Task 3), the `@page` rule (Task 2), and the harness (Task 6). `running_header()` signature matches its call site in the wrapper.
