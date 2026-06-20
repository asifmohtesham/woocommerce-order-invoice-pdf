# PDF Filename Nomenclature Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every generated PDF a standardized, admin-configurable filename that always includes the WooCommerce order number.

**Architecture:** Centralize the filename logic — currently copy-pasted across 7 `get_filename()` methods — into one `woi_pdf_build_filename()` helper that renders a template string (`{document_type}-{order_number}-{document_number}-{date}`) read from two new General-tab settings. Each document's `get_filename()` becomes a thin adapter that resolves its own labels/numbers and delegates to the helper. The existing `woi_pdf_filename` filter and final `sanitize_file_name()` move into the helper so the contract is defined once.

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce plugin APIs, PHPUnit 9.5 + Brain Monkey 2.6 for unit tests, Strauss-vendored deps.

## Global Constraints

- PHP floor: `>=7.4` (no `enum`, no `readonly`, no first-class callable syntax).
- Text domain for all user-facing strings: `woocommerce-orders-invoice-pdf`.
- Default filename template (verbatim): `{document_type}-{order_number}-{date}`.
- Default date format (verbatim): `Y-m-d`. `{date}` uses the current generation date.
- Supported placeholders, exact spelling: `{document_type}`, `{order_number}`, `{document_number}`, `{date}`.
- Bulk (multi-order) rule: `{order_number}` → `"{count}-orders"`; `{document_number}` resolves empty.
- The `woi_pdf_filename` filter signature must stay: `apply_filters( 'woi_pdf_filename', $filename, $type, $order_ids, $context, $args )`.
- `sanitize_file_name()` is always the last transform.
- Settings option name: `woi_pdf_settings_general`. New keys: `filename_template`, `filename_date_format`.
- Run PHPUnit with the ABSPATH prepend or it dies silently:
  `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
- Version bump target: `1.5.4` → `1.5.5` in `woocommerce-orders-invoice-pdf.php` (header `Version:` + the `$this->version` property that feeds `WOI_PDF_VERSION`).

---

## File Structure

- `woi-pdf-functions.php` — add `woi_pdf_get_filename_settings()` and `woi_pdf_build_filename()` (next to `woi_pdf_get_document_output_format_extension()`).
- `tests/Unit/FilenameBuilderTest.php` — new, unit tests for both helpers (Brain Monkey).
- `includes/Settings/SettingsGeneral.php` — add a "PDF Filename" section with two `text_element` fields.
- `includes/Documents/Invoice.php`, `PackingSlip.php`, `CreditNote.php`, `Receipt.php`, `Proforma.php`, `OrderDocument.php`, `Summary.php` — replace each `get_filename()` body with a thin call to the builder.
- `woocommerce-orders-invoice-pdf.php` — version bump.
- `readme.txt` (if a changelog section exists there) — changelog entry.

---

## Task 1: Filename builder + settings resolver

**Files:**
- Modify: `woi-pdf-functions.php` (add two functions after line 288, where `woi_pdf_get_document_output_format_extension()` ends)
- Test: `tests/Unit/FilenameBuilderTest.php` (create)

**Interfaces:**
- Produces:
  - `woi_pdf_get_filename_settings(): array` → `array( 'template' => string, 'date_format' => string )` with defaults applied.
  - `woi_pdf_build_filename( array $args ): string` where `$args` keys are:
    `type` (string), `document_type` (string, pre-pluralized), `order_ids` (int[]), `order_number` (string), `order_id` (int), `document_number` (string), `output_format` (string, default `pdf`), `context` (string, default `download`), `filter_args` (array, default `array()`).
  - Returns the sanitized filename including extension.
- Consumes: `woi_pdf_get_document_output_format_extension()` (existing).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/FilenameBuilderTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * woi_pdf_build_filename() renders the configurable filename template,
 * always including the order number, and centralizes the filter +
 * sanitize_file_name() contract that used to be duplicated per document.
 */
class FilenameBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default: no saved settings -> helper falls back to defaults.
		Functions\when( 'get_option' )->justReturn( array() );

		// date_i18n( $format ) -> deterministic fixed date for assertions.
		Functions\when( 'date_i18n' )->alias( function ( $format ) {
			return gmdate( $format, strtotime( '2026-06-20' ) );
		} );

		// Pass the filename through the filter unchanged.
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			return $value;
		} );

		// Minimal sanitize_file_name: strip WP special chars (incl. parens),
		// collapse whitespace to dashes. Enough to assert our behavior.
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			$name = preg_replace( '/[?\[\]\/\\\\=<>:;,\'"&$#*()|~`!{}%+]/', '', $name );
			$name = preg_replace( '/[\s]+/', '-', $name );
			return trim( $name, '.-_' );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function args( array $overrides = array() ): array {
		return array_merge( array(
			'type'            => 'invoice',
			'document_type'   => 'invoice',
			'order_ids'       => array( 55 ),
			'order_number'    => '1042',
			'order_id'        => 55,
			'document_number' => '',
			'output_format'   => 'pdf',
			'context'         => 'download',
			'filter_args'     => array(),
		), $overrides );
	}

	public function test_default_template_single_order(): void {
		$this->assertSame(
			'invoice-1042-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_document_number_token_present_when_in_template(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}-{order_number}-{document_number}-{date}',
		) );
		$this->assertSame(
			'invoice-1042-INV0007-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array( 'document_number' => 'INV0007' ) ) )
		);
	}

	public function test_empty_document_number_collapses_separators(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}-{order_number}-{document_number}-{date}',
		) );
		$this->assertSame(
			'invoice-1042-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array( 'document_number' => '' ) ) )
		);
	}

	public function test_bulk_order_number_becomes_count(): void {
		$this->assertSame(
			'invoices-3-orders-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array(
				'document_type' => 'invoices',
				'order_ids'     => array( 55, 56, 57 ),
			) ) )
		);
	}

	public function test_custom_date_format(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_date_format' => 'd-m-Y',
		) );
		$this->assertSame(
			'invoice-1042-20-06-2026.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_empty_template_falls_back_to_default(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '',
		) );
		$this->assertSame(
			'invoice-1042-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_empty_order_number_falls_back_to_order_id(): void {
		$this->assertSame(
			'invoice-55-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array( 'order_number' => '' ) ) )
		);
	}

	public function test_parentheses_are_stripped_by_sanitize(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}-({order_number})-{date}',
		) );
		$this->assertSame(
			'invoice-1042-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_settings_resolver_applies_defaults(): void {
		$settings = woi_pdf_get_filename_settings();
		$this->assertSame( '{document_type}-{order_number}-{date}', $settings['template'] );
		$this->assertSame( 'Y-m-d', $settings['date_format'] );
	}

	public function test_no_order_context_drops_order_number_token(): void {
		// Summary export with zero orders: no order id, no order number, no ids.
		$this->assertSame(
			'summary-2026-06-20.pdf',
			woi_pdf_build_filename( array(
				'type'          => 'summary',
				'document_type' => 'summary',
				'order_ids'     => array(),
				'order_number'  => '',
				'order_id'      => 0,
				'output_format' => 'pdf',
				'context'       => 'download',
				'filter_args'   => array(),
			) )
		);
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter FilenameBuilderTest`
Expected: FAIL — `Error: Call to undefined function WOI\PDF\Tests\Unit\woi_pdf_build_filename()` (and `..._get_filename_settings()`).

- [ ] **Step 3: Implement the two helpers**

In `woi-pdf-functions.php`, immediately after the closing `}` of `woi_pdf_get_document_output_format_extension()` (line 288), add:

```php
/**
 * Resolve the configurable filename settings, applying defaults.
 *
 * @return array{template:string, date_format:string}
 */
function woi_pdf_get_filename_settings(): array {
	$settings = get_option( 'woi_pdf_settings_general', array() );

	$template = isset( $settings['filename_template'] ) ? trim( (string) $settings['filename_template'] ) : '';
	if ( '' === $template ) {
		$template = '{document_type}-{order_number}-{date}';
	}

	$date_format = isset( $settings['filename_date_format'] ) ? trim( (string) $settings['filename_date_format'] ) : '';
	if ( '' === $date_format ) {
		$date_format = 'Y-m-d';
	}

	return array(
		'template'    => $template,
		'date_format' => $date_format,
	);
}

/**
 * Build a standardized PDF filename from the configurable template.
 *
 * The order number is always represented: for a single order it is the
 * caller-resolved order number (falling back to order id / uniqid); for
 * multiple orders it collapses to "{count}-orders".
 *
 * @param array $args type, document_type, order_ids, order_number, order_id,
 *                    document_number, output_format, context, filter_args.
 * @return string Sanitized filename including extension.
 */
function woi_pdf_build_filename( array $args ): string {
	$args = array_merge( array(
		'type'            => '',
		'document_type'   => '',
		'order_ids'       => array(),
		'order_number'    => '',
		'order_id'        => 0,
		'document_number' => '',
		'output_format'   => 'pdf',
		'context'         => 'download',
		'filter_args'     => array(),
	), $args );

	$settings    = woi_pdf_get_filename_settings();
	$order_ids   = array_values( (array) $args['order_ids'] );
	$order_count = max( 1, count( $order_ids ) );
	$is_bulk     = $order_count > 1;
	$has_order   = ! empty( $order_ids ) || ! empty( $args['order_id'] ) || '' !== (string) $args['order_number'];

	if ( $is_bulk ) {
		$order_number = $order_count . '-orders';
	} elseif ( ! $has_order ) {
		// No order context at all (e.g. Summary export): drop the token.
		$order_number = '';
	} else {
		$order_number = (string) $args['order_number'];
		if ( '' === $order_number ) {
			if ( ! empty( $args['order_id'] ) ) {
				$order_number = (string) $args['order_id'];
			} elseif ( ! empty( $order_ids ) ) {
				$order_number = (string) reset( $order_ids );
			} else {
				$order_number = uniqid();
			}
		}
	}

	$replacements = array(
		'{document_type}'   => (string) $args['document_type'],
		'{order_number}'    => $order_number,
		'{document_number}' => $is_bulk ? '' : (string) $args['document_number'],
		'{date}'            => date_i18n( $settings['date_format'] ),
	);

	$filename = strtr( $settings['template'], $replacements );

	// Collapse separators left by empty tokens, then trim stray separators.
	$filename = preg_replace( '/-{2,}/', '-', $filename );
	$filename = trim( $filename, '-' );

	$filename .= woi_pdf_get_document_output_format_extension( (string) $args['output_format'] );

	// Preserve the existing developer filter contract.
	$filter_order_ids = ! empty( $order_ids ) ? $order_ids : array( $args['order_id'] );
	$filename         = apply_filters( 'woi_pdf_filename', $filename, $args['type'], $filter_order_ids, $args['context'], $args['filter_args'] );

	return sanitize_file_name( $filename );
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter FilenameBuilderTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Lint the modified file**

Run: `php -l woi-pdf-functions.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add woi-pdf-functions.php tests/Unit/FilenameBuilderTest.php
git commit -m "feat: add woi_pdf_build_filename() template helper + tests"
```

---

## Task 2: Add the "PDF Filename" settings fields

**Files:**
- Modify: `includes/Settings/SettingsGeneral.php` (insert into the `$settings_fields` array, after the `download_display` setting block that ends at line 75)

**Interfaces:**
- Consumes: nothing new.
- Produces: two persisted option keys under `woi_pdf_settings_general` — `filename_template`, `filename_date_format` — read by `woi_pdf_get_filename_settings()` (Task 1).

- [ ] **Step 1: Insert the new section and fields**

In `includes/Settings/SettingsGeneral.php`, directly after the `download_display` setting array (the block whose `'id' => 'download_display'`, ending with `),` on line 75) and before the `template_path` setting block, insert:

```php
			array(
				'type'     => 'section',
				'id'       => 'general_filename',
				'title'    => __( 'PDF filename', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'filename_template',
				'title'    => __( 'Filename template', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_filename',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'filename_template',
					'size'        => 'large',
					'default'     => '{document_type}-{order_number}-{date}',
					'description' => sprintf(
						/* translators: %1$s lists the available placeholders, %2$s is a filename example. */
						__( 'Template for generated PDF filenames. Available placeholders: %1$s. The file extension is added automatically. Example: %2$s', 'woocommerce-orders-invoice-pdf' ),
						'<code>{document_type}</code>, <code>{order_number}</code>, <code>{document_number}</code>, <code>{date}</code>',
						'<code>Invoice-1042-2026-06-20.pdf</code>'
					),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'filename_date_format',
				'title'    => __( 'Filename date format', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_filename',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'filename_date_format',
					'default'     => 'Y-m-d',
					'description' => __( 'PHP date format for the {date} placeholder, using the date the PDF is generated (e.g. Y-m-d, d-m-Y, Ymd).', 'woocommerce-orders-invoice-pdf' ),
				),
			),
```

- [ ] **Step 2: Lint the modified file**

Run: `php -l includes/Settings/SettingsGeneral.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual admin verification**

In a WordPress admin with the plugin active, open **PDF Invoice → Settings → General**. Confirm a **PDF filename** section shows the two fields, the template field pre-fills `{document_type}-{order_number}-{date}`, saving persists the values, and reloading shows the saved values. (No automated test — pure WP settings-array wiring; behavior is covered by Task 1 defaults and Task 3 output.)

- [ ] **Step 4: Commit**

```bash
git add includes/Settings/SettingsGeneral.php
git commit -m "feat: add PDF filename template settings (General tab)"
```

---

## Task 3: Refactor document `get_filename()` methods to use the builder

Each method below loses its bespoke suffix/extension/filter/sanitize logic and instead resolves its own labels + numbers and calls `woi_pdf_build_filename()`. Replace the **entire** method body between `{` and the final `}` with the code shown.

**Files:**
- Modify: `includes/Documents/Invoice.php:167-207`
- Modify: `includes/Documents/PackingSlip.php:85-115`
- Modify: `includes/Documents/CreditNote.php:135-165`
- Modify: `includes/Documents/Receipt.php:124-161`
- Modify: `includes/Documents/Proforma.php:124-152`
- Modify: `includes/Documents/OrderDocument.php:1934-1961`
- Modify: `includes/Documents/Summary.php:95-106`

**Interfaces:**
- Consumes: `woi_pdf_build_filename()` (Task 1).
- Produces: unchanged public method signatures — `get_filename( $context = 'download', $args = array() ): string`.

- [ ] **Step 1: Refactor `Invoice::get_filename()`**

Replace the body of `Invoice.php` `get_filename()` (lines 167–207) with:

```php
	public function get_filename( $context = 'download', $args = array() ) {
		$order_ids   = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$order_count = count( $order_ids );
		$name        = _n( 'invoice', 'invoices', $order_count, 'woocommerce-orders-invoice-pdf' );

		if ( empty( $this->order ) && isset( $order_ids[0] ) ) {
			$order = wc_get_order( $order_ids[0] );
		} else {
			$order = $this->order;
		}
		$order_number = is_callable( array( $order, 'get_order_number' ) ) ? $order->get_order_number() : '';

		return woi_pdf_build_filename( array(
			'type'            => $this->get_type(),
			'document_type'   => $name,
			'order_ids'       => $order_ids,
			'order_number'    => $order_number,
			'order_id'        => $this->order_id,
			'document_number' => (string) $this->get_number(),
			'output_format'   => ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf',
			'context'         => $context,
			'filter_args'     => $args,
		) );
	}
```

- [ ] **Step 2: Refactor `PackingSlip::get_filename()`**

Replace the body of `PackingSlip.php` `get_filename()` (lines 85–115) with:

```php
	public function get_filename( $context = 'download', $args = array() ) {
		$order_ids   = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$order_count = count( $order_ids );
		$name        = _n( 'packing-slip', 'packing-slips', $order_count, 'woocommerce-orders-invoice-pdf' );

		if ( empty( $this->order ) && isset( $order_ids[0] ) ) {
			$order = wc_get_order( $order_ids[0] );
		} else {
			$order = $this->order;
		}
		$order_number = is_callable( array( $order, 'get_order_number' ) ) ? $order->get_order_number() : '';

		return woi_pdf_build_filename( array(
			'type'            => $this->get_type(),
			'document_type'   => $name,
			'order_ids'       => $order_ids,
			'order_number'    => $order_number,
			'order_id'        => $this->order_id,
			'document_number' => (string) $this->get_number(),
			'output_format'   => ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf',
			'context'         => $context,
			'filter_args'     => $args,
		) );
	}
```

- [ ] **Step 3: Refactor `CreditNote::get_filename()`**

Replace the body of `CreditNote.php` `get_filename()` (lines 135–165) with:

```php
	public function get_filename( $context = 'download', $args = array() ): string {
		$order_ids   = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$order_count = count( $order_ids );
		$name        = _n( 'credit-note', 'credit-notes', $order_count, 'woocommerce-orders-invoice-pdf' );

		if ( empty( $this->order ) && isset( $order_ids[0] ) ) {
			$order = wc_get_order( $order_ids[0] );
		} else {
			$order = $this->order;
		}
		$order_number = is_callable( array( $order, 'get_order_number' ) ) ? $order->get_order_number() : '';

		return woi_pdf_build_filename( array(
			'type'            => $this->get_type(),
			'document_type'   => $name,
			'order_ids'       => $order_ids,
			'order_number'    => $order_number,
			'order_id'        => $this->order_id,
			'document_number' => (string) $this->get_number(),
			'output_format'   => ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf',
			'context'         => $context,
			'filter_args'     => $args,
		) );
	}
```

- [ ] **Step 4: Refactor `Receipt::get_filename()`**

Replace the body of `Receipt.php` `get_filename()` (lines 124–161) with:

```php
	public function get_filename( $context = 'download', $args = array() ): string {
		$order_ids   = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$order_count = count( $order_ids );
		$name        = _n( 'receipt', 'receipts', $order_count, 'woocommerce-orders-invoice-pdf' );

		if ( empty( $this->order ) && isset( $order_ids[0] ) ) {
			$order = wc_get_order( $order_ids[0] );
		} else {
			$order = $this->order;
		}
		$order_number = is_callable( array( $order, 'get_order_number' ) ) ? $order->get_order_number() : '';

		return woi_pdf_build_filename( array(
			'type'            => $this->get_type(),
			'document_type'   => $name,
			'order_ids'       => $order_ids,
			'order_number'    => $order_number,
			'order_id'        => $this->order_id,
			'document_number' => (string) $this->get_number(),
			'output_format'   => ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf',
			'context'         => $context,
			'filter_args'     => $args,
		) );
	}
```

- [ ] **Step 5: Refactor `Proforma::get_filename()`**

Replace the body of `Proforma.php` `get_filename()` (lines 124–152) with:

```php
	public function get_filename( $context = 'download', $args = array() ): string {
		$order_ids   = $args['order_ids'] ?? array( $this->order_id );
		$order_count = count( $order_ids );
		$name        = _n( 'proforma-invoice', 'proforma-invoices', $order_count, 'woocommerce-orders-invoice-pdf' );

		if ( empty( $this->order ) && isset( $order_ids[0] ) ) {
			$order = wc_get_order( $order_ids[0] );
		} else {
			$order = $this->order;
		}
		$order_number = is_callable( array( $order, 'get_order_number' ) ) ? $order->get_order_number() : '';

		return woi_pdf_build_filename( array(
			'type'            => $this->get_type(),
			'document_type'   => $name,
			'order_ids'       => $order_ids,
			'order_number'    => $order_number,
			'order_id'        => $this->order_id,
			'document_number' => (string) $this->get_number(),
			'output_format'   => ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf',
			'context'         => $context,
			'filter_args'     => $args,
		) );
	}
```

- [ ] **Step 6: Refactor `OrderDocument::get_filename()` (generic, handles refunds)**

Replace the body of `OrderDocument.php` `get_filename()` (lines 1934–1961) with:

```php
	public function get_filename( $context = 'download', $args = array() ) {
		$order_ids   = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$order_count = count( $order_ids );
		$name        = $this->get_type();

		if ( is_callable( array( $this->order, 'get_type' ) ) && $this->order->get_type() == 'shop_order_refund' ) {
			$order_number = (string) $this->order_id;
		} else {
			$order_number = is_callable( array( $this->order, 'get_order_number' ) ) ? $this->order->get_order_number() : '';
		}

		return woi_pdf_build_filename( array(
			'type'            => $this->get_type(),
			'document_type'   => $name,
			'order_ids'       => $order_ids,
			'order_number'    => $order_number,
			'order_id'        => $this->order_id,
			'document_number' => '',
			'output_format'   => ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf',
			'context'         => $context,
			'filter_args'     => $args,
		) );
	}
```

- [ ] **Step 7: Refactor `Summary::get_filename()`**

`Summary` is an aggregate export over many orders. It passes its **real**
`order_ids` so the builder's bulk rule applies (`summary-{count}-orders-{date}`)
and the `woi_pdf_filename` filter still receives the real order IDs. The
builder's no-order guard (Task 1) handles the degenerate empty-selection case as
`summary-{date}`. Replace the body of `Summary.php` `get_filename()`
(lines 95–106) with:

```php
	public function get_filename( $context = 'download', $args = array() ): string {
		$name      = __( 'summary', 'woocommerce-orders-invoice-pdf' );
		$order_ids = isset( $args['order_ids'] ) ? $args['order_ids'] : $this->order_ids;

		return woi_pdf_build_filename( array(
			'type'          => $this->get_type(),
			'document_type' => $name,
			'order_ids'     => $order_ids,
			'order_number'  => '',
			'order_id'      => 0,
			'output_format' => ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf',
			'context'       => $context,
			'filter_args'   => $args,
		) );
	}
```

- [ ] **Step 8: Lint all modified document files**

Run:
```bash
php -l includes/Documents/Invoice.php && \
php -l includes/Documents/PackingSlip.php && \
php -l includes/Documents/CreditNote.php && \
php -l includes/Documents/Receipt.php && \
php -l includes/Documents/Proforma.php && \
php -l includes/Documents/OrderDocument.php && \
php -l includes/Documents/Summary.php
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 9: Run the full unit suite to confirm no regressions**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: PASS — same pass count as the clean baseline plus the `FilenameBuilderTest` from Task 1.

- [ ] **Step 10: Commit**

```bash
git add includes/Documents/Invoice.php includes/Documents/PackingSlip.php \
  includes/Documents/CreditNote.php includes/Documents/Receipt.php \
  includes/Documents/Proforma.php includes/Documents/OrderDocument.php \
  includes/Documents/Summary.php
git commit -m "refactor: route all get_filename() methods through woi_pdf_build_filename()"
```

---

## Task 4: Version bump + changelog

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php` (header `Version:` line 6, and the `$this->version` assignment that defines `WOI_PDF_VERSION`)
- Modify: `readme.txt` (changelog section, if present)

**Interfaces:** none.

- [ ] **Step 1: Find the version definitions**

Run: `grep -n "1.5.4\|version *=" woocommerce-orders-invoice-pdf.php`
Expected: the `* Version: 1.5.4` header line plus a `$this->version = '1.5.4';` (or similar) assignment.

- [ ] **Step 2: Bump both to 1.5.5**

Edit `woocommerce-orders-invoice-pdf.php`: change `Version: 1.5.4` → `Version: 1.5.5`, and the matching `$this->version = '1.5.4'` → `'1.5.5'`.

- [ ] **Step 3: Add a changelog entry**

If `readme.txt` has a `== Changelog ==` section, add at the top:

```
= 1.5.5 =
* New: Configurable PDF filename template (General settings) with {document_type}, {order_number}, {document_number} and {date} placeholders.
* Improved: Generated PDF filenames now always include the order number. Note: default filenames change to include the order number and date.
```

- [ ] **Step 4: Verify the bump**

Run: `grep -n "1.5.5" woocommerce-orders-invoice-pdf.php`
Expected: both the header and the version property show `1.5.5`.

- [ ] **Step 5: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php readme.txt
git commit -m "chore: bump version to 1.5.5 — configurable PDF filename nomenclature"
```

---

## Self-Review

**Spec coverage:**
- Configurable template setting → Task 2 (fields) + Task 1 (`woi_pdf_get_filename_settings`). ✓
- Default `{document_type}-{order_number}-{date}` → Task 1 default + Task 2 field default. ✓
- All four placeholders → Task 1 `$replacements`. ✓
- Single global template applied to all docs → Task 3 routes every `get_filename()` through the builder. ✓
- `{date}` = current date, configurable format → Task 1 `date_i18n( $date_format )` + Task 2 `filename_date_format`. ✓
- Bulk `{count}-orders`, `{document_number}` empty → Task 1 `$is_bulk` branch. ✓
- Order number always present (incl. when `display_number` set) → Task 3 drops the old `display_number` branch and always supplies the order number; `document_number` is now a separate optional token. ✓
- Filter + `sanitize_file_name()` preserved/centralized → Task 1. ✓
- Empty-token separator cleanup, empty template fallback, empty order number fallback, no-order (Summary) path → Task 1 (builder + tests). ✓
- Refund uses `order_id` → Task 3 Step 6. ✓
- Version bump 1.5.4 → 1.5.5 → Task 4. ✓
- PHPUnit tests for builder → Task 1 + Task 3 Step 9. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code. The Step 7 note intentionally surfaces a problem that Step 8 fixes — not a placeholder.

**Type consistency:** `woi_pdf_build_filename( array $args ): string` and `woi_pdf_get_filename_settings(): array` (keys `template`, `date_format`) are used identically in Tasks 1, 3, and tests. All call sites pass the same arg keys. ✓
