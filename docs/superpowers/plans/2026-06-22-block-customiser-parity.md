# Block Invoice Template — Full Customiser Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Block Invoice Template sidebar a second, equally-complete editor of every Customiser field (per-column rich options, total rows, custom blocks, sort/bundle, global CSS), all writing the shared `woi_pdf_editor_settings` option so the rendered PDF needs no changes.

**Architecture:** The PHP already defines the full option schema for every column/total type (`EditorSettings::get_columns_field_options()` / `get_totals_field_options()`, both auto-injecting `width`/`label`/`style`/`style_target`). We expose that schema + saved values over a new `GET/POST /editor-config` REST pair, sanitize saves with a pure schema-aware sanitizer, and render the schema in the block sidebar with one generic React `OptionField` component plus per-section panels. Both editing surfaces write the same option, so they stay in sync and the render path (`TemplateTokens::render_line_items/render_totals` → `woi_pdf_templates_get_table_*`) is untouched.

**Tech Stack:** PHP 7.4+ (namespace `WOI\PDF`), PHPUnit 9.5 + Brain Monkey (no WP install; `tests/bootstrap.php`), `@wordpress/scripts` (webpack + Jest), `@wordpress/components` / `@wordpress/block-editor` React.

## Global Constraints

- PHP floor 7.4; WP REST namespace `woi-pdf/v1`; capability `manage_woocommerce` on every route.
- Single source of truth: option `woi_pdf_editor_settings`. No new option/post-type/meta. Only `invoice` document-type keys are touched on save; never disturb other doc-types' keys.
- Reuse the existing `update_option_woi_pdf_editor_settings` → `add_or_update_editor_totals_columns()` hook for 1..N position renumbering. Do not re-implement renumbering.
- Saves must round-trip the FULL block object (preserve unknown keys, including nested `requirements` arrays on custom blocks) so editing one surface never strips data set in the other.
- CSS in `style`/`custom_styles` goes through the existing `woi_pdf_templates_sanitize_column_style()` whitelist; widths through `woi_pdf_templates_normalize_column_width()`.
- Text domain: `woocommerce-orders-invoice-pdf` for new JS strings (matches existing block-editor JS).
- After JS changes: `npm run build` must succeed. Final task bumps `WOI_PDF_VERSION` (currently `1.5.55`, in `woocommerce-orders-invoice-pdf.php:6` and `:24`) for cache-bust.
- Tests run with: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php` (the ABSPATH gotcha — phpunit dies silently otherwise). JS tests: `npm run test:unit`.

---

### Task 1: Schema-aware sanitizer (pure, PHP)

A pure, WP-install-free class that turns a list of incoming block rows into a clean, schema-validated, 1-indexed list. This is the testable core of the save path.

**Files:**
- Create: `includes/Editor/EditorConfigSanitizer.php`
- Test: `tests/Unit/EditorConfigSanitizerTest.php`

**Interfaces:**
- Produces:
  - `EditorConfigSanitizer::sanitize_blocks(array $incoming, array $schema): array` — `$schema` is a `type => ['options' => [optKey => fieldDef]]` map (exactly what `get_columns_field_options()` / `get_totals_field_options()` return). Returns `1 => [...], 2 => [...]` clean rows.
  - `EditorConfigSanitizer::sanitize_option(string $widget, $value, array $field, string $opt_key = ''): ?string` — sanitize one value by widget; `null` means "omit this key".
- Consumes: globals `woi_pdf_templates_normalize_column_width()` and `woi_pdf_templates_sanitize_column_style()` (loaded by `tests/bootstrap.php`), and WP `sanitize_key()` / `sanitize_text_field()` / `sanitize_textarea_field()` (stubbed in tests).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Editor\EditorConfigSanitizer;

class EditorConfigSanitizerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => trim( (string) $v ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function schema(): array {
        return array(
            'price' => array( 'options' => array(
                'label'      => array( 'type' => 'text' ),
                'width'      => array( 'type' => 'number', 'min' => 0, 'max' => 100 ),
                'price_type' => array( 'type' => 'select', 'options' => array( 'single' => 'Single', 'total' => 'Total' ) ),
                'tax'        => array( 'type' => 'select', 'options' => array( 'incl' => 'Incl', 'excl' => 'Excl' ) ),
                'only_discounted' => array( 'type' => 'checkbox', 'description' => 'Only discounted' ),
                'style'      => array( 'type' => 'text' ),
            ) ),
            'sku' => array( 'options' => array( 'label' => array( 'type' => 'text' ) ) ),
        );
    }

    public function test_checkbox_truthy_becomes_one_and_falsey_is_omitted(): void {
        $on  = EditorConfigSanitizer::sanitize_blocks( array( array( 'type' => 'price', 'only_discounted' => true ) ), $this->schema() );
        $off = EditorConfigSanitizer::sanitize_blocks( array( array( 'type' => 'price', 'only_discounted' => '0' ) ), $this->schema() );
        $this->assertSame( '1', $on[1]['only_discounted'] );
        $this->assertArrayNotHasKey( 'only_discounted', $off[1] );
    }

    public function test_select_validates_against_allowed_values(): void {
        $out = EditorConfigSanitizer::sanitize_blocks(
            array( array( 'type' => 'price', 'price_type' => 'total', 'tax' => 'bogus' ) ),
            $this->schema()
        );
        $this->assertSame( 'total', $out[1]['price_type'] );
        $this->assertArrayNotHasKey( 'tax', $out[1] ); // invalid select dropped
    }

    public function test_number_is_clamped_to_min_max(): void {
        Functions\when( 'woi_pdf_templates_normalize_column_width' )->alias( static fn( $v ) => (string) ( 0 + $v ) );
        $out = EditorConfigSanitizer::sanitize_blocks( array( array( 'type' => 'price', 'width' => '250' ) ), $this->schema() );
        $this->assertSame( '100', $out[1]['width'] );
    }

    public function test_rows_are_renumbered_from_one_and_unknown_types_dropped(): void {
        $out = EditorConfigSanitizer::sanitize_blocks(
            array( array( 'type' => 'bogus' ), array( 'type' => 'sku' ) ),
            $this->schema()
        );
        $this->assertSame( array( 1 ), array_keys( $out ) );
        $this->assertSame( 'sku', $out[1]['type'] );
    }

    public function test_unknown_scalar_keys_are_preserved(): void {
        $out = EditorConfigSanitizer::sanitize_blocks(
            array( array( 'type' => 'sku', 'legacy_wiring' => 'keepme' ) ),
            $this->schema()
        );
        $this->assertSame( 'keepme', $out[1]['legacy_wiring'] );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/EditorConfigSanitizerTest.php`
Expected: FAIL — `Class "WOI\PDF\Editor\EditorConfigSanitizer" not found`.

- [ ] **Step 3: Write the implementation**

Create `includes/Editor/EditorConfigSanitizer.php`:

```php
<?php

namespace WOI\PDF\Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, schema-aware sanitizer for Customiser block rows (columns / totals).
 * No WordPress install required — only relies on sanitize_* + the two global
 * CSS/width helpers (all loaded by tests/bootstrap.php).
 */
class EditorConfigSanitizer {

	/**
	 * @param array $incoming List of assoc rows, each with a 'type'.
	 * @param array $schema   type => array{ options: array<string, array> }.
	 * @return array 1-indexed list of clean rows.
	 */
	public static function sanitize_blocks( array $incoming, array $schema ): array {
		$clean = array();
		$i     = 1;
		foreach ( $incoming as $row ) {
			$row  = (array) $row;
			$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';
			if ( '' === $type || ! isset( $schema[ $type ] ) ) {
				continue;
			}
			$c       = array( 'type' => $type );
			$options = ( isset( $schema[ $type ]['options'] ) && is_array( $schema[ $type ]['options'] ) )
				? $schema[ $type ]['options'] : array();

			foreach ( $options as $opt_key => $field ) {
				if ( ! is_array( $field ) || ! isset( $field['type'] ) || ! array_key_exists( $opt_key, $row ) ) {
					continue;
				}
				$val = self::sanitize_option( (string) $field['type'], $row[ $opt_key ], $field, (string) $opt_key );
				if ( null !== $val ) {
					$c[ $opt_key ] = $val;
				}
			}

			// Preserve unknown scalar keys (e.g. filter-added wiring) untouched.
			foreach ( $row as $k => $v ) {
				if ( 'type' === $k || isset( $c[ $k ] ) || isset( $options[ $k ] ) || ! is_scalar( $v ) ) {
					continue;
				}
				$c[ sanitize_key( (string) $k ) ] = sanitize_text_field( (string) $v );
			}

			$clean[ $i++ ] = $c;
		}
		return $clean;
	}

	/**
	 * @return string|null Sanitized value, or null to omit the key.
	 */
	public static function sanitize_option( string $widget, $value, array $field, string $opt_key = '' ) {
		switch ( $widget ) {
			case 'checkbox':
				return ( $value && '0' !== (string) $value ) ? '1' : null;

			case 'select':
				$allowed = ( isset( $field['options'] ) && is_array( $field['options'] ) )
					? array_map( 'strval', array_keys( $field['options'] ) ) : array();
				$v = (string) $value;
				return in_array( $v, $allowed, true ) ? $v : null;

			case 'number':
				if ( '' === $value || null === $value ) {
					return null;
				}
				if ( 'width' === $opt_key && function_exists( 'woi_pdf_templates_normalize_column_width' ) ) {
					$w = woi_pdf_templates_normalize_column_width( $value );
					return ( '' !== $w ) ? $w : null;
				}
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				$n = 0 + $value;
				if ( isset( $field['min'] ) && $n < $field['min'] ) {
					$n = $field['min'];
				}
				if ( isset( $field['max'] ) && $n > $field['max'] ) {
					$n = $field['max'];
				}
				return (string) $n;

			case 'text':
				if ( 'style' === $opt_key && function_exists( 'woi_pdf_templates_sanitize_column_style' ) ) {
					return woi_pdf_templates_sanitize_column_style( (string) $value );
				}
				return sanitize_text_field( (string) $value );

			case 'textarea':
				return function_exists( 'sanitize_textarea_field' )
					? sanitize_textarea_field( (string) $value )
					: sanitize_text_field( (string) $value );

			default: // documentation, separator, unknown
				return null;
		}
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/EditorConfigSanitizerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Editor/EditorConfigSanitizer.php tests/Unit/EditorConfigSanitizerTest.php
git commit -m "feat(editor): schema-aware sanitizer for block customiser config"
```

---

### Task 2: REST endpoints + schema/value accessors (PHP)

Expose the full schema + saved values, and save all sections back into the shared option using the Task-1 sanitizer. Per-section merge so each sidebar panel can save independently.

**Files:**
- Modify: `includes/Rest.php` (add routes in `rest_api_init()` after the `/visual-columns` block `:103-117`; add handlers + seam methods after `handle_save_columns` `:207`)
- Modify: `includes/Editor/EditorSettings.php` (add two public accessors after `get_product_bundle_options()` `:416`)
- Test: `tests/Unit/EditorConfigRestTest.php`

**Interfaces:**
- Consumes: `EditorConfigSanitizer::sanitize_blocks()` (Task 1).
- Produces:
  - REST `GET /editor-config` → `handle_get_editor_config($request): array`
  - REST `POST /editor-config` → `handle_save_editor_config($request): array`
  - Protected seams (overridable in tests): `editor_schema_columns(): array`, `editor_schema_totals(): array`, `read_invoice_totals(): array`, `editor_custom_positions(): array`, `editor_custom_types(): array`.
  - `EditorSettings::get_custom_block_positions(): array`, `EditorSettings::get_custom_block_types(): array`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class EditorConfigRestTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Rest subclass that overrides the EditorSettings-backed seams so the
     *  handler is testable without a WP install / the singleton. */
    private function rest(): Rest {
        return new class extends Rest {
            public array $saved = array();
            protected function editor_schema_columns(): array {
                return array( 'price' => array( 'options' => array(
                    'price_type' => array( 'type' => 'select', 'options' => array( 'single' => 'S', 'total' => 'T' ) ),
                    'tax'        => array( 'type' => 'select', 'options' => array( 'incl' => 'I', 'excl' => 'E' ) ),
                ) ) );
            }
            protected function editor_schema_totals(): array {
                return array( 'total' => array( 'options' => array(
                    'tax' => array( 'type' => 'select', 'options' => array( 'incl' => 'I', 'excl' => 'E' ) ),
                ) ) );
            }
            protected function read_invoice_totals(): array { return array(); }
            protected function read_invoice_columns(): array { return array(); }
            protected function render_line_items_token( string $d, int $o ): array { return array(); }
            protected function render_totals_token( string $d, int $o ): array { return array(); }
            protected function persist_editor_option( array $option ): void { $this->saved = $option; }
        };
    }

    private function request( array $json ) {
        return new class( $json ) {
            public function __construct( private array $json ) {}
            public function get_json_params() { return $this->json; }
            public function get_param( $k ) { return $this->json[ $k ] ?? null; }
        };
    }

    public function test_save_persists_only_provided_sections_with_sanitized_values(): void {
        $rest = $this->rest();
        $req  = $this->request( array(
            'columns' => array( array( 'type' => 'price', 'price_type' => 'total', 'tax' => 'bogus' ) ),
        ) );
        $res = $rest->handle_save_editor_config( $req );
        $this->assertTrue( $res['saved'] );
        $this->assertSame( 'total', $rest->saved['fields_invoice_columns'][1]['price_type'] );
        $this->assertArrayNotHasKey( 'tax', $rest->saved['fields_invoice_columns'][1] ); // invalid dropped
        $this->assertArrayNotHasKey( 'fields_invoice_totals', $rest->saved );            // not provided => untouched
        $this->assertSame( '1', $rest->saved['settings_saved'] );
    }

    public function test_save_totals_and_custom_styles_sections(): void {
        $rest = $this->rest();
        $req  = $this->request( array(
            'totals'        => array( array( 'type' => 'total', 'tax' => 'incl' ) ),
            'custom_styles' => '.x{color:red}',
        ) );
        $rest->handle_save_editor_config( $req );
        $this->assertSame( 'incl', $rest->saved['fields_invoice_totals'][1]['tax'] );
        $this->assertSame( '.x{color:red}', $rest->saved['custom_styles'] );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/EditorConfigRestTest.php`
Expected: FAIL — `Call to undefined method WOI\PDF\Rest::handle_save_editor_config()`.

- [ ] **Step 3a: Add EditorSettings accessors**

In `includes/Editor/EditorSettings.php`, immediately after `get_product_bundle_options()` (ends `:416`), add:

```php
	/** Custom-block insertion positions (hook name => label). Mirror of display_custom_block(). */
	public function get_custom_block_positions(): array {
		return array(
			'woi_pdf_before_document'         => __( 'Before document', 'woi_pdf_templates' ),
			'woi_pdf_before_shop_logo'        => __( 'Before the shop logo', 'woi_pdf_templates' ),
			'woi_pdf_after_shop_logo'         => __( 'After the shop logo', 'woi_pdf_templates' ),
			'woi_pdf_before_shop_name'        => __( 'Before the shop name', 'woi_pdf_templates' ),
			'woi_pdf_after_shop_name'         => __( 'After the shop name', 'woi_pdf_templates' ),
			'woi_pdf_before_shop_address'     => __( 'Before the shop address', 'woi_pdf_templates' ),
			'woi_pdf_after_shop_address'      => __( 'After the shop address', 'woi_pdf_templates' ),
			'woi_pdf_before_document_label'   => __( 'Before the document label', 'woi_pdf_templates' ),
			'woi_pdf_after_document_label'    => __( 'After the document label', 'woi_pdf_templates' ),
			'woi_pdf_before_billing_address'  => __( 'Before the billing address', 'woi_pdf_templates' ),
			'woi_pdf_after_billing_address'   => __( 'After the billing address', 'woi_pdf_templates' ),
			'woi_pdf_before_shipping_address' => __( 'Before the shipping address', 'woi_pdf_templates' ),
			'woi_pdf_after_shipping_address'  => __( 'After the shipping address', 'woi_pdf_templates' ),
			'woi_pdf_before_order_data'       => __( 'Before the order data (invoice number, order date, etc.)', 'woi_pdf_templates' ),
			'woi_pdf_after_order_data'        => __( 'After the order data', 'woi_pdf_templates' ),
			'woi_pdf_before_customer_notes'   => __( 'Before the customer notes', 'woi_pdf_templates' ),
			'woi_pdf_after_customer_notes'    => __( 'After the customer notes', 'woi_pdf_templates' ),
			'woi_pdf_before_order_details'    => __( 'Before the order details table with all items', 'woi_pdf_templates' ),
			'woi_pdf_after_order_details'     => __( 'After the order details table', 'woi_pdf_templates' ),
			'woi_pdf_before_footer'           => __( 'Before the footer', 'woi_pdf_templates' ),
			'woi_pdf_after_footer'            => __( 'After the footer', 'woi_pdf_templates' ),
			'woi_pdf_after_document'          => __( 'After document', 'woi_pdf_templates' ),
		);
	}

	/** Custom-block content types (key => label). */
	public function get_custom_block_types(): array {
		return array(
			'text'         => __( 'Text', 'woi_pdf_templates' ),
			'custom_field' => __( 'Custom Field', 'woi_pdf_templates' ),
			'user_meta'    => __( 'User Meta', 'woi_pdf_templates' ),
		);
	}
```

- [ ] **Step 3b: Register the routes**

In `includes/Rest.php`, inside `rest_api_init()`, immediately after the `/visual-columns` registration closes (`:117`), add:

```php
		register_rest_route( 'woi-pdf/v1', '/editor-config', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_editor_config' ),
				'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_editor_config' ),
				'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			),
		) );
```

- [ ] **Step 3c: Add handlers + seams**

In `includes/Rest.php`, after `handle_save_columns()` (`:207`), add:

```php
	/** GET /editor-config — full schema + saved values for every Customiser section. */
	public function handle_get_editor_config( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
		}
		$es     = \WOI\PDF\Editor\EditorSettings::instance();
		$sort   = $es->get_sorting_options();
		$option = get_option( 'woi_pdf_editor_settings', array() );
		$config = array(
			'columns' => array( 'schema' => $this->editor_schema_columns(), 'values' => $this->read_invoice_columns() ),
			'totals'  => array( 'schema' => $this->editor_schema_totals(),  'values' => $this->read_invoice_totals() ),
			'custom'  => array(
				'positions' => $this->editor_custom_positions(),
				'types'     => $this->editor_custom_types(),
				'values'    => array_values( (array) ( $option['fields_invoice_custom'] ?? array() ) ),
			),
			'sort'  => array( 'options' => $sort['options'], 'value' => (string) ( $option['sort_items']['invoice'] ?? 'default' ) ),
			'custom_styles' => (string) ( $option['custom_styles'] ?? '' ),
		);
		if ( class_exists( '\\WC_Product_Bundle' ) || function_exists( 'wc_pb_get_bundled_order_items' ) ) {
			$config['bundle'] = array(
				'options' => $es->get_product_bundle_options(),
				'value'   => (string) ( $option['product_bundle_display']['invoice'] ?? 'all' ),
			);
		}
		return $config;
	}

	/** POST /editor-config — sanitize + save only the sections present in the body. */
	public function handle_save_editor_config( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
		}
		$json = $request->get_json_params();
		if ( ! is_array( $json ) ) {
			$json = array();
		}
		$option = get_option( 'woi_pdf_editor_settings', array() );
		if ( ! is_array( $option ) ) {
			$option = array();
		}

		if ( array_key_exists( 'columns', $json ) ) {
			$option['fields_invoice_columns'] = \WOI\PDF\Editor\EditorConfigSanitizer::sanitize_blocks(
				(array) $json['columns'], $this->editor_schema_columns()
			);
		}
		if ( array_key_exists( 'totals', $json ) ) {
			$option['fields_invoice_totals'] = \WOI\PDF\Editor\EditorConfigSanitizer::sanitize_blocks(
				(array) $json['totals'], $this->editor_schema_totals()
			);
		}
		if ( array_key_exists( 'custom', $json ) ) {
			$option['fields_invoice_custom'] = $this->sanitize_custom_blocks( (array) $json['custom'] );
		}
		if ( array_key_exists( 'sort', $json ) ) {
			$sort = sanitize_key( (string) $json['sort'] );
			$option['sort_items']            = (array) ( $option['sort_items'] ?? array() );
			$option['sort_items']['invoice'] = $sort ?: 'default';
		}
		if ( array_key_exists( 'bundle', $json ) ) {
			$bundle = sanitize_key( (string) $json['bundle'] );
			$option['product_bundle_display']            = (array) ( $option['product_bundle_display'] ?? array() );
			$option['product_bundle_display']['invoice'] = $bundle ?: 'all';
		}
		if ( array_key_exists( 'custom_styles', $json ) ) {
			$css = (string) $json['custom_styles'];
			$option['custom_styles'] = function_exists( 'woi_pdf_templates_sanitize_column_style' )
				? woi_pdf_templates_sanitize_column_style( $css, true )
				: $css;
		}

		$option['settings_saved'] = '1';
		$this->persist_editor_option( $option ); // triggers position-renumber hook

		$response  = array(
			'saved'         => true,
			'columns'       => $this->read_invoice_columns(),
			'totals'        => $this->read_invoice_totals(),
			'custom_styles' => (string) ( get_option( 'woi_pdf_editor_settings', array() )['custom_styles'] ?? '' ),
		);
		$order_id = absint( $request->get_param( 'order_id' ) );
		if ( $order_id ) {
			$response['tokens'] = array_merge(
				$this->render_line_items_token( 'invoice', $order_id ),
				$this->render_totals_token( 'invoice', $order_id )
			);
		}
		return $response;
	}

	/** Sanitize custom blocks, preserving the advanced `requirements` subtree. */
	protected function sanitize_custom_blocks( array $incoming ): array {
		$types     = array_map( 'strval', array_keys( $this->editor_custom_types() ) );
		$positions = array_map( 'strval', array_keys( $this->editor_custom_positions() ) );
		$clean = array();
		$i     = 1;
		foreach ( $incoming as $row ) {
			$row = (array) $row;
			$c   = array();
			if ( isset( $row['type'] ) && in_array( (string) $row['type'], $types, true ) ) {
				$c['type'] = (string) $row['type'];
			}
			if ( isset( $row['position'] ) && in_array( (string) $row['position'], $positions, true ) ) {
				$c['position'] = (string) $row['position'];
			}
			if ( isset( $row['label'] ) )    { $c['label']    = sanitize_text_field( (string) $row['label'] ); }
			if ( isset( $row['meta_key'] ) ) { $c['meta_key'] = sanitize_text_field( (string) $row['meta_key'] ); }
			if ( isset( $row['text'] ) )     { $c['text']     = sanitize_textarea_field( (string) $row['text'] ); }
			// Preserve the classic editor's advanced "requirements" subtree untouched.
			if ( isset( $row['requirements'] ) && is_array( $row['requirements'] ) ) {
				$c['requirements'] = map_deep( $row['requirements'], 'sanitize_text_field' );
			}
			if ( ! empty( $c ) ) {
				$clean[ $i++ ] = $c;
			}
		}
		return $clean;
	}

	protected function editor_schema_columns(): array {
		return \WOI\PDF\Editor\EditorSettings::instance()->get_columns_field_options();
	}

	protected function editor_schema_totals(): array {
		return \WOI\PDF\Editor\EditorSettings::instance()->get_totals_field_options();
	}

	protected function editor_custom_positions(): array {
		return \WOI\PDF\Editor\EditorSettings::instance()->get_custom_block_positions();
	}

	protected function editor_custom_types(): array {
		return \WOI\PDF\Editor\EditorSettings::instance()->get_custom_block_types();
	}

	/** Invoice totals config as a plain 0-indexed list (full rows preserved). */
	protected function read_invoice_totals(): array {
		$totals = array();
		if ( class_exists( '\\WOI\\PDF\\Editor\\EditorSettings' ) ) {
			$totals = \WOI\PDF\Editor\EditorSettings::instance()->get_settings( 'invoice', 'totals' );
		}
		$out = array();
		foreach ( (array) $totals as $row ) {
			$row = (array) $row;
			if ( ! empty( $row['type'] ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/** Render ONLY the {{totals}} token for an order (live canvas partial). */
	protected function render_totals_token( string $doc_type, int $order_id ): array {
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$order = wc_get_order( $order_id );
		if ( empty( $order ) ) {
			return array();
		}
		add_filter( 'woi_pdf_document_use_historical_settings', '__return_false', 99 );
		add_filter( 'woi_pdf_document_is_enabled', '__return_true', 99 );
		$document = Order_Document_Methods::get_document( $doc_type, $order );
		$tokens   = ( new \WOI\PDF\Visual\TemplateTokens() )->map( $document );
		return isset( $tokens['{{totals}}'] ) ? array( '{{totals}}' => $tokens['{{totals}}'] ) : array();
	}

	/** Persist the option (separate seam so tests can capture without WP). */
	protected function persist_editor_option( array $option ): void {
		update_option( 'woi_pdf_editor_settings', $option );
	}
```

Note: confirm `render_line_items_token()` and `TemplateTokens::map()`/`get_document` signatures match the existing `render_line_items_token()` body (`Rest.php:253-`); mirror exactly how it builds `$document` and `$tokens` so `render_totals_token()` is consistent.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/EditorConfigRestTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full PHP suite (no regressions)**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: PASS (all suites, including the existing `RestTest`).

- [ ] **Step 6: Commit**

```bash
git add includes/Rest.php includes/Editor/EditorSettings.php tests/Unit/EditorConfigRestTest.php
git commit -m "feat(rest): /editor-config endpoints with per-section save + accessors"
```

---

### Task 3: JS data layer + generic OptionField

Pure style/align helpers (Jest-tested), the store wrappers, and the generic schema → control renderer.

**Files:**
- Create: `src/block-editor/optionSchema.js`
- Create: `src/block-editor/OptionField.js`
- Modify: `src/block-editor/store.js` (append wrappers)
- Test: `src/block-editor/test/optionSchema.test.js`

**Interfaces:**
- Produces:
  - `optionSchema.js`: `setTextAlign(style: string, align: string): string`, `getTextAlign(style: string): string`, `renderableOptions(schema, { exclude?: string[] }): Array<{key, field}>`.
  - `store.js`: `getEditorConfig(): Promise<object>`, `saveEditorConfig(payload: object, orderId?: number): Promise<object>`.
  - `OptionField.js`: default `OptionField({ optionKey, field, value, onChange })`.
- Consumes: nothing from later tasks.

- [ ] **Step 1: Write the failing test**

```js
import { setTextAlign, getTextAlign, renderableOptions } from '../optionSchema';

describe( 'setTextAlign', () => {
	it( 'adds text-align to an empty style', () => {
		expect( setTextAlign( '', 'center' ) ).toBe( 'text-align: center;' );
	} );
	it( 'replaces an existing text-align, preserving other declarations', () => {
		expect( setTextAlign( 'color:#000; text-align:left; font-size:12px', 'right' ) )
			.toBe( 'color:#000; font-size:12px; text-align: right;' );
	} );
	it( 'removes text-align when align is empty', () => {
		expect( setTextAlign( 'color:#000; text-align:left;', '' ) ).toBe( 'color:#000;' );
	} );
} );

describe( 'getTextAlign', () => {
	it( 'reads the declaration', () => {
		expect( getTextAlign( 'text-align: center; color:#000' ) ).toBe( 'center' );
	} );
	it( 'returns empty when absent', () => {
		expect( getTextAlign( 'color:#000' ) ).toBe( '' );
	} );
} );

describe( 'renderableOptions', () => {
	const schema = { options: {
		label: { type: 'text' },
		width: { type: 'number' },
		price_type: { type: 'select', options: { single: 'S' } },
		note: { type: 'documentation' },
	} };
	it( 'keeps input widgets and drops documentation', () => {
		const keys = renderableOptions( schema, { exclude: [ 'label', 'width' ] } ).map( ( o ) => o.key );
		expect( keys ).toEqual( [ 'price_type' ] );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:unit -- optionSchema`
Expected: FAIL — cannot find module `../optionSchema`.

- [ ] **Step 3: Implement the helpers**

Create `src/block-editor/optionSchema.js`:

```js
// Pure helpers shared by the schema-driven Customiser panels.

/** Parse "a:b; c:d" into [[prop, value], ...] (lowercased prop, trimmed). */
function parseDecls( style ) {
	return String( style || '' )
		.split( ';' )
		.map( ( d ) => d.trim() )
		.filter( Boolean )
		.map( ( d ) => {
			const i = d.indexOf( ':' );
			return i === -1 ? [ d.trim().toLowerCase(), '' ] : [ d.slice( 0, i ).trim().toLowerCase(), d.slice( i + 1 ).trim() ];
		} );
}

function joinDecls( decls ) {
	return decls.map( ( [ p, v ] ) => `${ p }: ${ v };` ).join( ' ' ).trim();
}

/** Set/replace/remove the text-align declaration without touching others. */
export function setTextAlign( style, align ) {
	const decls = parseDecls( style ).filter( ( [ p ] ) => p !== 'text-align' );
	if ( align ) {
		decls.push( [ 'text-align', align ] );
	}
	return joinDecls( decls );
}

/** Read the current text-align value (or ''). */
export function getTextAlign( style ) {
	const found = parseDecls( style ).find( ( [ p ] ) => p === 'text-align' );
	return found ? found[ 1 ] : '';
}

/** Ordered list of {key, field} for renderable option widgets. */
export function renderableOptions( typeSchema, { exclude = [] } = {} ) {
	const options = ( typeSchema && typeSchema.options ) || {};
	const renderable = [ 'checkbox', 'select', 'text', 'number', 'textarea' ];
	return Object.keys( options )
		.filter( ( key ) => ! exclude.includes( key ) && renderable.includes( options[ key ] && options[ key ].type ) )
		.map( ( key ) => ( { key, field: options[ key ] } ) );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:unit -- optionSchema`
Expected: PASS.

- [ ] **Step 5: Implement OptionField + store wrappers**

Create `src/block-editor/OptionField.js`:

```js
import { CheckboxControl, SelectControl, TextControl, TextareaControl } from '@wordpress/components';

// Generic renderer mirroring PHP EditorSettings::display_table_field_options().
export default function OptionField( { optionKey, field, value, onChange } ) {
	const desc = field.description || optionKey;
	switch ( field.type ) {
		case 'checkbox':
			return (
				<CheckboxControl
					label={ desc }
					checked={ '1' === String( value ) || true === value }
					onChange={ ( v ) => onChange( v ? '1' : '' ) }
					__nextHasNoMarginBottom
				/>
			);
		case 'select':
			return (
				<SelectControl
					label={ desc }
					value={ value || '' }
					options={ Object.keys( field.options || {} ).map( ( k ) => ( { value: k, label: field.options[ k ] } ) ) }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'number':
			return (
				<TextControl
					label={ desc }
					type="number"
					value={ value || '' }
					placeholder={ field.placeholder || '' }
					min={ field.min }
					max={ field.max }
					step={ field.step }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'textarea':
			return (
				<TextareaControl
					label={ desc }
					value={ value || '' }
					rows={ field.rows || 4 }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'text':
		default:
			return (
				<TextControl
					label={ desc }
					value={ value || '' }
					placeholder={ field.placeholder || '' }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
	}
}
```

Append to `src/block-editor/store.js`:

```js
export function getEditorConfig() {
	return get( 'editor-config' );
}

export function saveEditorConfig( payload, orderId ) {
	return post( 'editor-config', orderId ? { ...payload, order_id: orderId } : payload );
}
```

- [ ] **Step 6: Verify the build compiles**

Run: `npm run build`
Expected: builds without errors; `assets/js/block-editor/index.js` regenerated.

- [ ] **Step 7: Commit**

```bash
git add src/block-editor/optionSchema.js src/block-editor/OptionField.js src/block-editor/store.js src/block-editor/test/optionSchema.test.js assets/js/block-editor
git commit -m "feat(block-editor): schema helpers, OptionField, editor-config store wrappers"
```

---

### Task 4: Schema-driven ColumnEditor

Replace the 5-field ColumnEditor with the full per-type editor: Title, Width, all schema options, Style, Style target, plus an Align convenience that edits `text-align` inside `style`.

**Files:**
- Modify (rewrite): `src/block-editor/ColumnEditor.js`

**Interfaces:**
- Consumes: `getEditorConfig`, `saveEditorConfig` (Task 3 store), `OptionField` (Task 3), `setTextAlign`/`getTextAlign`/`renderableOptions` (Task 3).
- Produces: default `ColumnEditor({ onTokens, onSaved, onLiveEdit, orderId })` (unchanged props — `src/block-editor/index.js:427` keeps working).

- [ ] **Step 1: Replace the component**

Overwrite `src/block-editor/ColumnEditor.js` with:

```js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SelectControl, TextControl, TextareaControl, Spinner } from '@wordpress/components';
import { chevronUp, chevronDown, trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { getEditorConfig, saveEditorConfig } from './store';
import { setTextAlign, getTextAlign, renderableOptions } from './optionSchema';
import OptionField from './OptionField';

const ALIGN_OPTS = [
	{ label: __( 'Default', 'woocommerce-orders-invoice-pdf' ), value: '' },
	{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
	{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
	{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
];
const STYLE_TARGET_OPTS = [
	{ label: __( 'Apply style to entire column', 'woocommerce-orders-invoice-pdf' ), value: 'both' },
	{ label: __( 'Apply style to column header', 'woocommerce-orders-invoice-pdf' ), value: 'header' },
	{ label: __( 'Apply style to column cells', 'woocommerce-orders-invoice-pdf' ), value: 'cells' },
];

export default function ColumnEditor( { onTokens, onSaved, onLiveEdit, orderId } ) {
	const [ columns, setColumns ] = useState( null );
	const [ schema, setSchema ] = useState( {} );
	const debounceRef = useRef( null );

	useEffect( () => {
		getEditorConfig()
			.then( ( r ) => {
				setColumns( Array.isArray( r.columns?.values ) ? r.columns.values : [] );
				setSchema( r.columns?.schema || {} );
			} )
			.catch( () => setColumns( [] ) );
	}, [] );

	const persist = useCallback( ( next ) => {
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveEditorConfig( { columns: next }, orderId ).then( ( res ) => {
				if ( res && res.tokens && onTokens ) { onTokens( res.tokens ); }
				else if ( onSaved ) { onSaved(); }
			} ).catch( () => {} );
		}, 250 );
	}, [ onTokens, onSaved, orderId ] );

	const update = ( next ) => { setColumns( next ); persist( next ); };
	const editField = ( next, instant ) => {
		setColumns( next );
		if ( instant && onLiveEdit ) { onLiveEdit( next ); }
		persist( next );
	};

	if ( null === columns ) {
		return <div className="woi-col-editor"><Spinner /></div>;
	}

	const typeTitle = ( t ) => ( schema[ t ] && schema[ t ].title ) || t;
	const move = ( i, d ) => {
		const j = i + d;
		if ( j < 0 || j >= columns.length ) { return; }
		const n = columns.slice();
		const tmp = n[ i ]; n[ i ] = n[ j ]; n[ j ] = tmp;
		update( n );
	};
	const setKey = ( i, k, v, instant ) => {
		const next = columns.map( ( c, idx ) => ( idx === i ? { ...c, [ k ]: v } : c ) );
		editField( next, !! instant );
	};
	const setAlign = ( i, align ) => {
		const next = columns.map( ( c, idx ) =>
			( idx === i ? { ...c, style: setTextAlign( c.style || '', align ), style_target: c.style_target || 'both' } : c ) );
		editField( next, true );
	};
	const remove = ( i ) => update( columns.filter( ( _, idx ) => idx !== i ) );
	const add = ( t ) => { if ( t ) { update( [ ...columns, { type: t } ] ); } };

	const hasOption = ( type, key ) => !! ( schema[ type ] && schema[ type ].options && schema[ type ].options[ key ] );
	const addOptions = [ { label: __( 'Add column…', 'woocommerce-orders-invoice-pdf' ), value: '' } ]
		.concat( Object.keys( schema ).filter( ( t ) => 'position' !== t ).map( ( t ) => ( { label: typeTitle( t ), value: t } ) ) );

	return (
		<div className="woi-col-editor">
			{ columns.map( ( c, i ) => {
				const opts = renderableOptions( schema[ c.type ], { exclude: [ 'label', 'width', 'style', 'style_target' ] } );
				return (
					<div className="woi-col-row" key={ i }>
						<div className="woi-col-head">
							<span className="woi-col-type">{ typeTitle( c.type ) }</span>
							<span className="woi-col-actions">
								<Button icon={ chevronUp } label={ __( 'Move up', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, -1 ) } disabled={ 0 === i } />
								<Button icon={ chevronDown } label={ __( 'Move down', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, 1 ) } disabled={ i === columns.length - 1 } />
								<Button icon={ trash } label={ __( 'Remove', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => remove( i ) } isDestructive />
							</span>
						</div>
						{ hasOption( c.type, 'label' ) && (
							<TextControl
								label={ __( 'Title', 'woocommerce-orders-invoice-pdf' ) }
								value={ c.label || '' }
								placeholder={ typeTitle( c.type ) }
								onChange={ ( v ) => setKey( i, 'label', v, true ) }
								__nextHasNoMarginBottom
							/>
						) }
						<div className="woi-col-grid">
							{ hasOption( c.type, 'width' ) && (
								<TextControl
									label={ __( 'Width %', 'woocommerce-orders-invoice-pdf' ) }
									type="number"
									value={ c.width || '' }
									min={ 0 } max={ 100 }
									onChange={ ( v ) => setKey( i, 'width', v, true ) }
									__nextHasNoMarginBottom
								/>
							) }
							{ hasOption( c.type, 'style' ) && (
								<SelectControl
									label={ __( 'Align', 'woocommerce-orders-invoice-pdf' ) }
									value={ getTextAlign( c.style || '' ) }
									options={ ALIGN_OPTS }
									onChange={ ( v ) => setAlign( i, v ) }
									__nextHasNoMarginBottom
								/>
							) }
						</div>
						{ opts.map( ( { key, field } ) => (
							<OptionField
								key={ key }
								optionKey={ key }
								field={ field }
								value={ c[ key ] }
								onChange={ ( v ) => setKey( i, key, v, false ) }
							/>
						) ) }
						{ hasOption( c.type, 'style' ) && (
							<TextareaControl
								label={ __( 'Style (inline CSS)', 'woocommerce-orders-invoice-pdf' ) }
								value={ c.style || '' }
								rows={ 2 }
								help={ __( 'e.g. color:#000; font-size:12px;', 'woocommerce-orders-invoice-pdf' ) }
								onChange={ ( v ) => setKey( i, 'style', v, false ) }
								__nextHasNoMarginBottom
							/>
						) }
						{ hasOption( c.type, 'style_target' ) && (
							<SelectControl
								label={ __( 'Style target', 'woocommerce-orders-invoice-pdf' ) }
								value={ c.style_target || 'both' }
								options={ STYLE_TARGET_OPTS }
								onChange={ ( v ) => setKey( i, 'style_target', v, false ) }
								__nextHasNoMarginBottom
							/>
						) }
					</div>
				);
			} ) }
			<div className="woi-col-add">
				<SelectControl value="" options={ addOptions } onChange={ add } __nextHasNoMarginBottom />
			</div>
		</div>
	);
}
```

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: compiles without errors.

- [ ] **Step 3: Manual acceptance (live harness)**

Open the Block Editor (PDF Invoices → Block Editor). For a Price column, confirm the new selects appear (Total/Single, Incl/Excl, Before/After, Only-discounted checkbox) and that changing them persists (reload; values stay) and updates the canvas. Confirm the **classic Customiser** tab now shows the same Price settings (shared option).

- [ ] **Step 4: Commit**

```bash
git add src/block-editor/ColumnEditor.js assets/js/block-editor
git commit -m "feat(block-editor): full per-type column options in sidebar ColumnEditor"
```

---

### Task 5: Totals, Custom blocks, Sort/Bundle, Custom CSS panels

Four new sidebar panels + wiring under the Document tab.

**Files:**
- Create: `src/block-editor/TotalsEditor.js`
- Create: `src/block-editor/CustomBlocksEditor.js`
- Create: `src/block-editor/SortBundlePanel.js`
- Create: `src/block-editor/CustomCssPanel.js`
- Modify: `src/block-editor/index.js` (import the four + render after the `Line items columns` section, before the closing `</div>` at `:431`)

**Interfaces:**
- Consumes: `getEditorConfig`/`saveEditorConfig` (Task 3), `OptionField`/`renderableOptions` (Task 3).
- Produces: four default-export React components, each `({ onTokens, onSaved, orderId })` except `CustomCssPanel`/`SortBundlePanel` which take `({ onSaved })`.

- [ ] **Step 1: TotalsEditor**

Create `src/block-editor/TotalsEditor.js`:

```js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SelectControl, TextControl, Spinner } from '@wordpress/components';
import { chevronUp, chevronDown, trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { getEditorConfig, saveEditorConfig } from './store';
import { renderableOptions } from './optionSchema';
import OptionField from './OptionField';

export default function TotalsEditor( { onTokens, onSaved, orderId } ) {
	const [ rows, setRows ] = useState( null );
	const [ schema, setSchema ] = useState( {} );
	const debounceRef = useRef( null );

	useEffect( () => {
		getEditorConfig()
			.then( ( r ) => { setRows( Array.isArray( r.totals?.values ) ? r.totals.values : [] ); setSchema( r.totals?.schema || {} ); } )
			.catch( () => setRows( [] ) );
	}, [] );

	const persist = useCallback( ( next ) => {
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveEditorConfig( { totals: next }, orderId ).then( ( res ) => {
				if ( res && res.tokens && onTokens ) { onTokens( res.tokens ); }
				else if ( onSaved ) { onSaved(); }
			} ).catch( () => {} );
		}, 250 );
	}, [ onTokens, onSaved, orderId ] );

	const update = ( next ) => { setRows( next ); persist( next ); };
	if ( null === rows ) { return <div className="woi-col-editor"><Spinner /></div>; }

	const typeTitle = ( t ) => ( schema[ t ] && schema[ t ].title ) || t;
	const move = ( i, d ) => {
		const j = i + d;
		if ( j < 0 || j >= rows.length ) { return; }
		const n = rows.slice(); const tmp = n[ i ]; n[ i ] = n[ j ]; n[ j ] = tmp; update( n );
	};
	const setKey = ( i, k, v ) => update( rows.map( ( c, idx ) => ( idx === i ? { ...c, [ k ]: v } : c ) ) );
	const remove = ( i ) => update( rows.filter( ( _, idx ) => idx !== i ) );
	const add = ( t ) => { if ( t ) { update( [ ...rows, { type: t } ] ); } };
	const addOptions = [ { label: __( 'Add total row…', 'woocommerce-orders-invoice-pdf' ), value: '' } ]
		.concat( Object.keys( schema ).map( ( t ) => ( { label: typeTitle( t ), value: t } ) ) );
	const hasOption = ( t, k ) => !! ( schema[ t ] && schema[ t ].options && schema[ t ].options[ k ] );

	return (
		<div className="woi-col-editor">
			{ rows.map( ( c, i ) => (
				<div className="woi-col-row" key={ i }>
					<div className="woi-col-head">
						<span className="woi-col-type">{ typeTitle( c.type ) }</span>
						<span className="woi-col-actions">
							<Button icon={ chevronUp } label={ __( 'Move up', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, -1 ) } disabled={ 0 === i } />
							<Button icon={ chevronDown } label={ __( 'Move down', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, 1 ) } disabled={ i === rows.length - 1 } />
							<Button icon={ trash } label={ __( 'Remove', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => remove( i ) } isDestructive />
						</span>
					</div>
					{ hasOption( c.type, 'label' ) && (
						<TextControl
							label={ __( 'Label', 'woocommerce-orders-invoice-pdf' ) }
							value={ c.label || '' }
							placeholder={ typeTitle( c.type ) }
							onChange={ ( v ) => setKey( i, 'label', v ) }
							__nextHasNoMarginBottom
						/>
					) }
					{ renderableOptions( schema[ c.type ], { exclude: [ 'label' ] } ).map( ( { key, field } ) => (
						<OptionField key={ key } optionKey={ key } field={ field } value={ c[ key ] } onChange={ ( v ) => setKey( i, key, v ) } />
					) ) }
				</div>
			) ) }
			<div className="woi-col-add">
				<SelectControl value="" options={ addOptions } onChange={ add } __nextHasNoMarginBottom />
			</div>
		</div>
	);
}
```

- [ ] **Step 2: CustomBlocksEditor**

Create `src/block-editor/CustomBlocksEditor.js`:

```js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SelectControl, TextControl, TextareaControl, Spinner } from '@wordpress/components';
import { trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { getEditorConfig, saveEditorConfig } from './store';

export default function CustomBlocksEditor( { onSaved } ) {
	const [ rows, setRows ] = useState( null );
	const [ positions, setPositions ] = useState( {} );
	const [ types, setTypes ] = useState( {} );
	const debounceRef = useRef( null );

	useEffect( () => {
		getEditorConfig()
			.then( ( r ) => { setRows( Array.isArray( r.custom?.values ) ? r.custom.values : [] ); setPositions( r.custom?.positions || {} ); setTypes( r.custom?.types || {} ); } )
			.catch( () => setRows( [] ) );
	}, [] );

	const persist = useCallback( ( next ) => {
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveEditorConfig( { custom: next } ).then( () => { if ( onSaved ) { onSaved(); } } ).catch( () => {} );
		}, 300 );
	}, [ onSaved ] );

	const update = ( next ) => { setRows( next ); persist( next ); };
	if ( null === rows ) { return <div className="woi-col-editor"><Spinner /></div>; }

	const opts = ( map, head ) => [ { label: head, value: '' } ].concat( Object.keys( map ).map( ( k ) => ( { label: map[ k ], value: k } ) ) );
	const setKey = ( i, k, v ) => update( rows.map( ( c, idx ) => ( idx === i ? { ...c, [ k ]: v } : c ) ) );
	const remove = ( i ) => update( rows.filter( ( _, idx ) => idx !== i ) );
	const add = () => update( [ ...rows, { type: 'text', position: '', label: '', meta_key: '', text: '' } ] );

	return (
		<div className="woi-col-editor">
			{ rows.map( ( c, i ) => (
				<div className="woi-col-row" key={ i }>
					<div className="woi-col-head">
						<span className="woi-col-type">{ __( 'Custom block', 'woocommerce-orders-invoice-pdf' ) }</span>
						<span className="woi-col-actions">
							<Button icon={ trash } label={ __( 'Remove', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => remove( i ) } isDestructive />
						</span>
					</div>
					<SelectControl label={ __( 'Type', 'woocommerce-orders-invoice-pdf' ) } value={ c.type || 'text' }
						options={ opts( types, __( 'Type…', 'woocommerce-orders-invoice-pdf' ) ) } onChange={ ( v ) => setKey( i, 'type', v ) } __nextHasNoMarginBottom />
					<SelectControl label={ __( 'Position', 'woocommerce-orders-invoice-pdf' ) } value={ c.position || '' }
						options={ opts( positions, __( 'Position…', 'woocommerce-orders-invoice-pdf' ) ) } onChange={ ( v ) => setKey( i, 'position', v ) } __nextHasNoMarginBottom />
					<TextControl label={ __( 'Label / header', 'woocommerce-orders-invoice-pdf' ) } value={ c.label || '' } onChange={ ( v ) => setKey( i, 'label', v ) } __nextHasNoMarginBottom />
					{ ( 'custom_field' === c.type || 'user_meta' === c.type ) && (
						<TextControl label={ __( 'Field name / meta key', 'woocommerce-orders-invoice-pdf' ) } value={ c.meta_key || '' } onChange={ ( v ) => setKey( i, 'meta_key', v ) } __nextHasNoMarginBottom />
					) }
					{ 'text' === c.type && (
						<TextareaControl label={ __( 'Text', 'woocommerce-orders-invoice-pdf' ) } value={ c.text || '' } rows={ 4 } onChange={ ( v ) => setKey( i, 'text', v ) } __nextHasNoMarginBottom />
					) }
				</div>
			) ) }
			<div className="woi-col-add">
				<Button variant="secondary" onClick={ add }>{ __( 'Add custom block', 'woocommerce-orders-invoice-pdf' ) }</Button>
			</div>
		</div>
	);
}
```

- [ ] **Step 3: SortBundlePanel + CustomCssPanel**

Create `src/block-editor/SortBundlePanel.js`:

```js
import { useState, useEffect } from '@wordpress/element';
import { SelectControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getEditorConfig, saveEditorConfig } from './store';

export default function SortBundlePanel( { onSaved } ) {
	const [ cfg, setCfg ] = useState( null );

	useEffect( () => { getEditorConfig().then( setCfg ).catch( () => setCfg( {} ) ); }, [] );
	if ( null === cfg ) { return <Spinner />; }

	const toOptions = ( map ) => Object.keys( map || {} ).map( ( k ) => ( { value: k, label: map[ k ] } ) );
	const save = ( payload ) => saveEditorConfig( payload ).then( () => { if ( onSaved ) { onSaved(); } } ).catch( () => {} );

	return (
		<div className="woi-col-editor">
			<SelectControl
				label={ __( 'Sort items by', 'woocommerce-orders-invoice-pdf' ) }
				value={ cfg.sort?.value || 'default' }
				options={ toOptions( cfg.sort?.options ) }
				onChange={ ( v ) => { setCfg( { ...cfg, sort: { ...cfg.sort, value: v } } ); save( { sort: v } ); } }
				__nextHasNoMarginBottom
			/>
			{ cfg.bundle && (
				<SelectControl
					label={ __( 'Product bundle display', 'woocommerce-orders-invoice-pdf' ) }
					value={ cfg.bundle.value || 'all' }
					options={ toOptions( cfg.bundle.options ) }
					onChange={ ( v ) => { setCfg( { ...cfg, bundle: { ...cfg.bundle, value: v } } ); save( { bundle: v } ); } }
					__nextHasNoMarginBottom
				/>
			) }
		</div>
	);
}
```

Create `src/block-editor/CustomCssPanel.js`:

```js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { TextareaControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getEditorConfig, saveEditorConfig } from './store';

export default function CustomCssPanel( { onSaved } ) {
	const [ css, setCss ] = useState( null );
	const debounceRef = useRef( null );

	useEffect( () => { getEditorConfig().then( ( r ) => setCss( r.custom_styles || '' ) ).catch( () => setCss( '' ) ); }, [] );

	const onChange = useCallback( ( v ) => {
		setCss( v );
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveEditorConfig( { custom_styles: v } ).then( () => { if ( onSaved ) { onSaved(); } } ).catch( () => {} );
		}, 400 );
	}, [ onSaved ] );

	if ( null === css ) { return <Spinner />; }
	return (
		<TextareaControl
			label={ __( 'Custom CSS', 'woocommerce-orders-invoice-pdf' ) }
			help={ __( 'Global CSS added to the document (applies to the rendered PDF).', 'woocommerce-orders-invoice-pdf' ) }
			value={ css }
			rows={ 8 }
			onChange={ onChange }
			__nextHasNoMarginBottom
		/>
	);
}
```

- [ ] **Step 4: Wire the panels into the Document sidebar tab**

In `src/block-editor/index.js`, add to the existing imports near `ColumnEditor`:

```js
import TotalsEditor from './TotalsEditor';
import CustomBlocksEditor from './CustomBlocksEditor';
import SortBundlePanel from './SortBundlePanel';
import CustomCssPanel from './CustomCssPanel';
```

Then immediately after the `Line items columns` block (after the `</p>` closing at `:430`, before the `</div>` at `:431`), insert:

```js
							<div className="insp-sec">{ __( 'Total rows', 'woocommerce-orders-invoice-pdf' ) }</div>
							<TotalsEditor onTokens={ applyTokens } onSaved={ refreshTokens } orderId={ orderId } />

							<div className="insp-sec">{ __( 'Custom blocks', 'woocommerce-orders-invoice-pdf' ) }</div>
							<CustomBlocksEditor onSaved={ refreshTokens } />

							<div className="insp-sec">{ __( 'Sorting & bundle', 'woocommerce-orders-invoice-pdf' ) }</div>
							<SortBundlePanel onSaved={ refreshTokens } />

							<div className="insp-sec">{ __( 'Custom CSS', 'woocommerce-orders-invoice-pdf' ) }</div>
							<CustomCssPanel onSaved={ refreshTokens } />
```

(`applyTokens`, `refreshTokens`, and `orderId` are already in scope at `:427`.)

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: compiles without errors.

- [ ] **Step 6: Manual acceptance**

In the Block Editor sidebar, add a Total row (e.g. Grand total → Excl tax), a Custom block (Text → After order details), set Sort to SKU, and add Custom CSS. Reload — all persist. Open the classic Customiser — the same totals / custom block / sort / CSS appear (shared option).

- [ ] **Step 7: Commit**

```bash
git add src/block-editor/TotalsEditor.js src/block-editor/CustomBlocksEditor.js src/block-editor/SortBundlePanel.js src/block-editor/CustomCssPanel.js src/block-editor/index.js assets/js/block-editor
git commit -m "feat(block-editor): totals, custom blocks, sort/bundle, custom CSS panels"
```

---

### Task 6: Render verification + version bump

Confirm a block-configured column lands in a real mPDF render, then bump the cache-bust version.

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php` (`:6` header `Version:`, `:24` `$version`)

- [ ] **Step 1: Render check via the local mPDF harness**

Set a non-default column config in the option (e.g. add a `weight` column with `show_unit=1`) — either through the live Block Editor on the test site, or by seeding `woi_pdf_editor_settings` in the harness fixture used by `tools/render-visual-sample.php`. Then:

Run: `php tools/render-visual-sample.php` then `python tools/rasterize.py <output.pdf> /tmp/parity 150`
Read `/tmp/parity-1.png`.
Expected: the configured Weight column appears in the line-item table header/cells.

- [ ] **Step 2: Full test suites**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Run: `npm run test:unit`
Run: `npm run build`
Expected: all green.

- [ ] **Step 3: Bump the version**

Edit `woocommerce-orders-invoice-pdf.php`:
- Line 6: `* Version:              1.5.56`
- Line 24: `public string $version     = '1.5.56';`

- [ ] **Step 4: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php
git commit -m "chore: bump to v1.5.56 (block customiser parity)"
```

---

## Self-Review

**Spec coverage:**
- Per-column rich options → Task 4 (OptionField + ColumnEditor). ✅
- Total rows → Task 5 TotalsEditor. ✅
- Custom blocks / Sort / Bundle → Task 5 CustomBlocksEditor + SortBundlePanel. ✅
- Global Custom CSS → Task 5 CustomCssPanel. ✅
- Schema exposure + schema-aware save + per-section merge → Task 2. ✅
- Pure sanitizer (checkbox/select/number/style/width, unknown-key preservation) → Task 1. ✅
- Render path untouched; shared option → enforced by design (no render-side task). ✅
- Reuse renumber hook → Task 2 (`persist_editor_option` → `update_option` fires it). ✅
- Round-trip preserves unknown keys incl. custom-block `requirements` → Task 1 + Task 2 `sanitize_custom_blocks`. ✅
- Tests (PHPUnit round-trip + sanitization, render check) → Tasks 1, 2, 6. ✅
- Version bump → Task 6. ✅

**Placeholder scan:** No TBD/TODO; every code step shows complete code; every command shows expected output.

**Type consistency:** `getEditorConfig`/`saveEditorConfig` signatures consistent across Tasks 3–5. `EditorConfigSanitizer::sanitize_blocks` signature consistent Tasks 1–2. REST GET payload shape (`columns.schema`/`columns.values`, `totals.*`, `custom.*`, `sort`, `bundle`, `custom_styles`) consistent between Task 2 (producer) and Tasks 4–5 (consumers).

**Known follow-ups (out of scope, noted in spec):** custom-block advanced "requirements" editing UI (data is preserved, not editable from blocks yet); non-invoice document types.

## Open verification note for the implementer

Before Task 2 Step 3c, open `includes/Rest.php:253-` and copy the EXACT body shape of the existing `render_line_items_token()` (how it resolves `$document` and calls `TemplateTokens`) so `render_totals_token()` mirrors it precisely (method name `map()` vs a `line_items()`-style accessor, and `Order_Document_Methods::get_document()` usage). Adjust the `render_totals_token()` body to match if the existing helper differs from the sketch above.
