# Letterhead Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a global "letterhead mode" that replaces the PDF header row (logo + shop details) with a single full-width letterhead image across all document types and template designs.

**Architecture:** A new settings section registers a `letterhead_mode` toggle plus a dedicated `letterhead_logo` upload (with `letterhead_logo_height`). The document object gains `is_letterhead_mode()` / `has_letterhead()` predicates and a `letterhead()` renderer that reuses a helper extracted from `header_logo()`. Each template branches on `has_letterhead()` to render a full-width banner table instead of the normal header. CSS makes the banner full-width.

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce plugin, mPDF templates, PHPUnit 9 + Brain Monkey.

**Spec:** `docs/superpowers/specs/2026-06-16-letterhead-mode-design.md`

**Branch:** `feature/letterhead-mode` (already checked out; spec already committed there).

---

## File Structure

- `includes/Settings/SettingsGeneral.php` — register the Letterhead section + two fields.
- `includes/Settings.php` — expose the three letterhead keys to documents.
- `includes/Documents/OrderDocument.php` — predicates, getters, `letterhead()`, and a shared image-render helper.
- `includes/Main.php` — inject the letterhead max-height CSS.
- `templates/{Simple,Modern,Business,Simple Premium}/*.php` — 24 template files: header branch (all) + label guard (Simple & Simple Premium only).
- `templates/{Simple,Modern,Business,Simple Premium}/style.css` — full-width banner CSS.
- `tests/Unit/Documents/LetterheadTest.php` — predicate unit tests.

---

## Task 1: Document predicates (TDD)

**Files:**
- Create: `tests/Unit/Documents/LetterheadTest.php`
- Modify: `includes/Documents/OrderDocument.php` (add methods after `has_header_logo()`, around line 1284)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Documents/LetterheadTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Documents\OrderDocument;

/**
 * Letterhead mode is active only when the toggle is on AND an image is set.
 * These predicates read $this->settings only, so no WP stubs are needed.
 */
class LetterheadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_document( array $settings ): OrderDocument {
		$doc = $this->getMockForAbstractClass( OrderDocument::class, array(), '', false );
		$doc->settings = $settings;
		return $doc;
	}

	public function test_is_letterhead_mode_reflects_setting(): void {
		$this->assertTrue( $this->make_document( array( 'letterhead_mode' => 1 ) )->is_letterhead_mode() );
		$this->assertFalse( $this->make_document( array() )->is_letterhead_mode() );
	}

	public function test_has_letterhead_requires_mode_and_image(): void {
		$this->assertTrue(
			$this->make_document( array( 'letterhead_mode' => 1, 'letterhead_logo' => 123 ) )->has_letterhead()
		);
		$this->assertFalse(
			$this->make_document( array( 'letterhead_mode' => 1 ) )->has_letterhead(),
			'mode on but no image'
		);
		$this->assertFalse(
			$this->make_document( array( 'letterhead_logo' => 123 ) )->has_letterhead(),
			'image set but mode off'
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/bin/phpunit --filter LetterheadTest --testdox 2>&1 > test-out.txt; cat test-out.txt`
Expected: FAIL — `Error: Call to undefined method ...::is_letterhead_mode()`.

(Note: this project's phpunit prints nothing to the terminal directly — always redirect to a file and read it, per the repo's PHPUnit gotcha.)

- [ ] **Step 3: Add the predicates**

In `includes/Documents/OrderDocument.php`, immediately after `has_header_logo()` (which ends at line ~1284), add:

```php
	/**
	 * Whether letterhead mode is enabled in settings.
	 */
	public function is_letterhead_mode(): bool {
		return ! empty( $this->settings['letterhead_mode'] );
	}

	/**
	 * Whether a letterhead should be rendered: mode on AND an image is set.
	 */
	public function has_letterhead(): bool {
		return $this->is_letterhead_mode() && ! empty( $this->settings['letterhead_logo'] );
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/bin/phpunit --filter LetterheadTest --testdox 2>&1 > test-out.txt; cat test-out.txt`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
rm -f test-out.txt
git add tests/Unit/Documents/LetterheadTest.php includes/Documents/OrderDocument.php
git commit -m "feat: add letterhead mode document predicates"
```

---

## Task 2: Document getters + render helper + letterhead() renderer

**Files:**
- Modify: `includes/Documents/OrderDocument.php` (refactor `header_logo()` at lines ~1312-1355; add getters near the header-logo getters ~1291-1305)

- [ ] **Step 1: Add the letterhead getters**

In `includes/Documents/OrderDocument.php`, after `get_header_logo_height()` (ends ~line 1305), add:

```php
	/**
	 * Return letterhead attachment id
	 */
	public function get_letterhead_id(): int {
		$letterhead_id = ! empty( $this->settings['letterhead_logo'] ) ? $this->get_settings_text( 'letterhead_logo', 0, false ) : 0;
		$letterhead_id = apply_filters( 'woi_pdf_letterhead_id', $letterhead_id, $this );

		return $letterhead_id && is_numeric( $letterhead_id ) ? absint( $letterhead_id ) : 0;
	}

	/**
	 * Return letterhead max-height
	 */
	public function get_letterhead_height() {
		if ( ! empty( $this->settings['letterhead_logo_height'] ) ) {
			return apply_filters( 'woi_pdf_letterhead_height', str_replace( ' ', '', $this->settings['letterhead_logo_height'] ), $this );
		}
	}
```

- [ ] **Step 2: Refactor `header_logo()` into a shared helper and add `letterhead()`**

Replace the entire current `header_logo()` method (lines ~1307-1355, from the `/** Show logo HTML */` docblock through its closing brace) with:

```php
	/**
	 * Show header logo HTML
	 *
	 * @return void
	 */
	public function header_logo(): void {
		$this->render_settings_image(
			$this->get_header_logo_id(),
			$this->get_shop_name(),
			'woi_pdf_header_logo_img_element'
		);
	}

	/**
	 * Show letterhead HTML
	 *
	 * @return void
	 */
	public function letterhead(): void {
		$this->render_settings_image(
			$this->get_letterhead_id(),
			$this->get_shop_name(),
			'woi_pdf_letterhead_img_element'
		);
	}

	/**
	 * Render a settings image (header logo or letterhead) as an <img> element.
	 *
	 * @param int    $attachment_id Attachment ID to render.
	 * @param string $alt           Alt text (shop name).
	 * @param string $filter        Filter applied to the final <img> markup.
	 * @return void
	 */
	private function render_settings_image( int $attachment_id, string $alt, string $filter ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		$attachment_src  = wp_get_attachment_image_url( $attachment_id, 'full' );
		$attachment_file = get_attached_file( $attachment_id );
		$attachment_path = $attachment_file ? wp_normalize_path( realpath( $attachment_file ) ) : '';

		$use_path = apply_filters( 'woi_pdf_use_path', true );

		$src = ( $use_path && ! empty( $attachment_path ) ) ? $attachment_path : $attachment_src;

		if ( empty( $src ) ) {
			woi_pdf_log_error( 'Settings image file not found.', 'critical' );
			return;
		}

		// fix URLs using path
		if ( ! $use_path && false !== strpos( $src, 'http' ) && false !== strpos( $src, WP_CONTENT_DIR ) ) {
			$path = preg_replace( '/^https?:\/\//', '', $src ); // removes http(s)://
			$src  = str_replace( trailingslashit( WP_CONTENT_DIR ), trailingslashit( WP_CONTENT_URL ), $path ); // replaces path with URL
		}

		if ( ! woi_pdf_is_file_readable( $src ) ) {
			woi_pdf_log_error( 'Settings image file not readable: ' . $src, 'critical' );
			return;
		}

		$img_src = isset( WOI_PDF()->settings->debug_settings['embed_images'] )
			? woi_pdf_get_image_src_in_base64( $src )
			: $src;

		$img_element = sprintf(
			'<img src="%1$s" alt="%2$s"/>',
			woi_pdf_escape_url_path_or_base64( $img_src ),
			esc_attr( $alt )
		);

		$img_element = apply_filters( $filter, $img_element, $attachment_id, $this );

		echo $img_element; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
```

This preserves `header_logo()`'s output exactly: same `woi_pdf_header_logo_img_element` filter with the same `($img_element, $attachment_id, $this)` arguments. Only the two `woi_pdf_log_error` strings change wording (now generic), which is acceptable.

- [ ] **Step 3: Run the full suite to confirm nothing regressed**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/bin/phpunit --testdox 2>&1 > test-out.txt; tail -20 test-out.txt`
Expected: PASS (all existing tests + the 2 letterhead tests, OK).

- [ ] **Step 4: Commit**

```bash
rm -f test-out.txt
git add includes/Documents/OrderDocument.php
git commit -m "feat: add letterhead renderer and share image-render helper with header logo"
```

---

## Task 3: Register settings

**Files:**
- Modify: `includes/Settings/SettingsGeneral.php` (insert after the `header_logo` field block, which ends at line ~168, before the `general_shop_details` section at line ~169)
- Modify: `includes/Settings.php` (add keys in `get_common_document_settings()` after `header_logo_height`, line ~602)

- [ ] **Step 1: Add the Letterhead section and fields**

In `includes/Settings/SettingsGeneral.php`, insert this block between the `header_logo` field array (closes at line ~168) and the `general_shop_details` section array (opens at line ~169):

```php
			array(
				'type'     => 'section',
				'id'       => 'general_letterhead',
				'title'    => __( 'Letterhead', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'letterhead_mode',
				'title'    => __( 'Letterhead mode', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'general_letterhead',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'letterhead_mode',
					'description' => __( 'Replace the shop logo and shop details with a single full-width letterhead image.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'letterhead_logo',
				'title'    => __( 'Letterhead image', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'media_upload',
				'section'  => 'general_letterhead',
				'show_if'  => array( 'field' => 'letterhead_mode', 'value' => 1 ),
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'letterhead_logo',
					'height_id'   => 'letterhead_logo_height',
					'description' => __( 'Upload your letterhead. Replaces the header in the PDF.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
```

- [ ] **Step 2: Expose the keys to documents**

In `includes/Settings.php`, inside `get_common_document_settings()` (the returned array starting at line ~598), add these three lines immediately after the `'header_logo_height' => ...` entry (line ~602):

```php
			'letterhead_mode'         => $this->general_settings['letterhead_mode'] ?? '',
			'letterhead_logo'         => $this->general_settings['letterhead_logo'] ?? '',
			'letterhead_logo_height'  => $this->general_settings['letterhead_logo_height'] ?? '',
```

- [ ] **Step 3: Lint the two files**

Run: `php -l includes/Settings/SettingsGeneral.php && php -l includes/Settings.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Run the full suite (settings-fields show_if test exercises field parsing)**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/bin/phpunit --testdox 2>&1 > test-out.txt; tail -20 test-out.txt`
Expected: PASS (OK).

- [ ] **Step 5: Commit**

```bash
rm -f test-out.txt
git add includes/Settings/SettingsGeneral.php includes/Settings.php
git commit -m "feat: register letterhead mode settings fields"
```

---

## Task 4: Template header branch (all 24 files)

**Files (modify all):**
`templates/Simple/{invoice,credit-note,packing-slip,proforma,receipt,delivery-note}.php`
`templates/Modern/{invoice,credit-note,packing-slip,proforma,receipt,delivery-note}.php`
`templates/Business/{invoice,credit-note,packing-slip,proforma,receipt,delivery-note}.php`
`templates/Simple Premium/{invoice,credit-note,packing-slip,proforma,receipt,delivery-note}.php`

Each file contains exactly one header table that opens with `<table class="head container">` (line ~5) and closes with the matching `</table>` just before the next `<?php do_action( ... )` or next `<table ...>`.

- [ ] **Step 1: Wrap the header table in every file**

Transform the existing block:

```php
<table class="head container">
	... existing rows (unchanged, varies per design) ...
</table>
```

into:

```php
<?php if ( $this->has_letterhead() ) : ?>
<table class="head container letterhead">
	<tr><td class="header letterhead"><?php $this->letterhead(); ?></td></tr>
</table>
<?php else : ?>
<table class="head container">
	... existing rows (unchanged) ...
</table>
<?php endif; ?>
```

Keep each design's existing rows byte-for-byte inside the `else` branch. The only addition is the `if/else/endif` wrapper and the new letterhead table. Do this for all 24 files.

- [ ] **Step 2: Verify every template still parses**

Run:
```bash
for f in templates/*/invoice.php templates/*/credit-note.php templates/*/packing-slip.php templates/*/proforma.php templates/*/receipt.php templates/*/delivery-note.php; do php -l "$f" || echo "SYNTAX ERROR: $f"; done
```
Expected: `No syntax errors detected` for all; no `SYNTAX ERROR` lines.

- [ ] **Step 3: Confirm the branch is present in all 24 files**

Run: `grep -rl "has_letterhead()" templates/ | wc -l`
Expected: `24`.

- [ ] **Step 4: Commit**

```bash
git add templates/
git commit -m "feat: render full-width letterhead header in all templates"
```

---

## Task 5: Document-title guard for Simple & Simple Premium (12 files)

**Why:** In `Simple` and `Simple Premium`, the lower document-type-label is gated on `has_header_logo()` — when the header table is replaced by the letterhead, that guard is false and the title ("INVOICE") would vanish. `Business` and `Modern` always render the title, so they need no change.

**Files (modify):**
`templates/Simple/{invoice,credit-note,packing-slip,proforma,receipt,delivery-note}.php`
`templates/Simple Premium/{invoice,credit-note,packing-slip,proforma,receipt,delivery-note}.php`

- [ ] **Step 1: Update the label guard in each of the 12 files**

Change every occurrence of:

```php
<?php if ( $this->has_header_logo() ) : ?>
	<h1 class="document-type-label"><?php $this->title(); ?></h1>
<?php endif; ?>
```

to:

```php
<?php if ( $this->has_header_logo() || $this->has_letterhead() ) : ?>
	<h1 class="document-type-label"><?php $this->title(); ?></h1>
<?php endif; ?>
```

Note: the header-cell `if ( $this->has_header_logo() ) : ... else : title() endif` block higher up in these files needs NO change — it lives inside the header table, which is entirely skipped (via Task 4's `else` branch) when letterhead mode is active.

- [ ] **Step 2: Verify the guard updated in exactly 12 files**

Run: `grep -rl "has_header_logo() || \$this->has_letterhead()" "templates/Simple" "templates/Simple Premium" | wc -l`
Expected: `12`.

- [ ] **Step 3: Confirm Business & Modern were NOT changed for the label**

Run: `grep -rn "document-type-label" templates/Business templates/Modern | grep -c "has_letterhead"`
Expected: `0` (their titles are unguarded; no letterhead reference near the label).

- [ ] **Step 4: Lint the 12 files**

Run: `for f in templates/Simple/*.php "templates/Simple Premium"/*.php; do php -l "$f" || echo "ERR $f"; done`
Expected: all `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add "templates/Simple" "templates/Simple Premium"
git commit -m "feat: keep document title visible under letterhead in Simple templates"
```

---

## Task 6: Styling — full-width banner + height injection

**Files:**
- Modify: `templates/Simple/style.css`, `templates/Modern/style.css`, `templates/Business/style.css`, `templates/Simple Premium/style.css`
- Modify: `includes/Main.php` (`set_header_logo_height()`, lines ~1223-1231)

- [ ] **Step 1: Add the full-width banner rule to each style.css**

In each of the four `style.css` files, add immediately after the existing `td.header img { ... }` rule:

```css
td.header.letterhead img {
	width: 100%;
	height: auto;
	max-height: none;
}
```

(The two-class selector `td.header.letterhead img` outranks `td.header img`, so the banner is not capped at the logo's default `max-height`.)

- [ ] **Step 2: Inject the optional letterhead max-height**

In `includes/Main.php`, replace the current `set_header_logo_height()` method (lines ~1223-1231):

```php
	public function set_header_logo_height( $document_type, $document = null ) {
		if ( !empty($document) && $header_logo_height = $document->get_header_logo_height() ) {
			?>
			td.header img {
				max-height: <?php echo esc_html( $header_logo_height ); ?>;
			}
			<?php
		}
	}
```

with:

```php
	public function set_header_logo_height( $document_type, $document = null ) {
		if ( empty( $document ) ) {
			return;
		}

		if ( $header_logo_height = $document->get_header_logo_height() ) {
			?>
			td.header img {
				max-height: <?php echo esc_html( $header_logo_height ); ?>;
			}
			<?php
		}

		if ( method_exists( $document, 'get_letterhead_height' ) && $letterhead_height = $document->get_letterhead_height() ) {
			?>
			td.header.letterhead img {
				max-height: <?php echo esc_html( $letterhead_height ); ?>;
			}
			<?php
		}
	}
```

- [ ] **Step 3: Lint Main.php**

Run: `php -l includes/Main.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add templates/*/style.css "templates/Simple Premium/style.css" includes/Main.php
git commit -m "feat: style full-width letterhead and inject its max-height"
```

---

## Task 7: Full verification

- [ ] **Step 1: Run the complete test suite**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/bin/phpunit --testdox 2>&1 > test-out.txt; tail -30 test-out.txt; rm -f test-out.txt`
Expected: `OK` — all tests pass including the new `LetterheadTest`.

- [ ] **Step 2: Manual preview check (WordPress admin)**

In WP admin → PDF Invoices → General:
1. Tick **Letterhead mode** → confirm the **Letterhead image** field appears (show_if).
2. Click **Use image**, pick a wide banner, **Use image** → confirm the field thumbnail shows it and the live preview replaces the whole header row with the full-width banner, with the "INVOICE" title appearing below it.
3. Switch the preview document type to a couple of others (e.g. Packing Slip) → banner persists.
4. Untick **Letterhead mode** → preview returns to the normal logo + shop details.
5. Spot-check at least two designs by temporarily switching the active template (Simple and Business).

- [ ] **Step 3: Push the branch (only when the user asks)**

Do not push or open a PR unless the user requests it.

---

## Notes for the implementer

- **PHPUnit prints nothing to the terminal in this repo.** Always run with `-d auto_prepend_file=tests/bootstrap.php`, redirect to a file, and read the file (e.g. `... > test-out.txt; cat test-out.txt`). Clean up `test-out.txt` before committing.
- The media-upload field retaining its value before save is already fixed (the `$args['current']` handling in `SettingsCallbacks::media_upload`), so the letterhead image flows into the live preview without extra work.
- Do not refactor unrelated code. Keep each design's normal header markup byte-for-byte inside the new `else` branch.
