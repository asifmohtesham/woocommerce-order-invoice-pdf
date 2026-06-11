# Settings UI Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the plugin settings experience as a left-nav app shell with a Home dashboard (status cards, setup checklist, quick actions) and ACF-style form patterns (sticky save, toggles, conditional fields), per the approved spec at `docs/superpowers/specs/2026-06-11-settings-ui-overhaul-design.md`.

**Architecture:** The PHP Settings API and the existing preview machinery stay untouched. A new `NavModel` builds the sidebar from the existing tabs filter + registered documents; `views/settings-page.php` is rebuilt as the shell; a new `SettingsHome` class registers the `home` tab and feeds a small React app (`wp.element`/`wp.components`). Conditional fields ride on the WP core `class` arg of `add_settings_field()`. The accordion already exists (`settingsAccordion()` in `assets/js/admin.js`, `.settings_category` wrappers from `Settings::create_section()`) — it only gets restyling and count badges, not a rebuild.

**Tech Stack:** PHP 8 / WP Settings API, jQuery (existing `admin.js` conventions), `@wordpress/scripts` + `wp.element`/`wp.components` for Home only, PHPUnit + Brain Monkey for unit tests.

**Test command (memorize this):** plain `vendor/bin/phpunit` dies silently in this repo. Always run:

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php
```

**Codebase facts you need (verified 2026-06-11):**

| Fact | Location |
|---|---|
| Tab list + `settings_page()` | `includes/Settings.php:174-200` (`woi_pdf_settings_tabs` filter; `debug` appended last as "Advanced") |
| Central field registration | `Settings::add_settings_fields()` at `includes/Settings.php:499` — every settings class funnels through it |
| Accordion wrappers | `Settings::create_section()` at `includes/Settings.php:1331` emits `<div class="settings_category" id="{category}">` |
| Accordion JS (existing) | `assets/js/admin.js:786-905` (`settingsAccordion()`, localStorage keys `wcpdf_{tab}_settings_accordion_state_{categoryId}`); search auto-open at `admin.js:1003-1030` |
| Checkbox render callback | `includes/Settings/SettingsCallbacks.php:165` — plain `<input type="checkbox">`, label after |
| Settings-page enqueues | `includes/Assets.php:94-294` (branch on `$hook` containing `woi_pdf_options_page`) |
| Dropdown picker to remove | `includes/Settings/SettingsDocuments.php:52-75` |
| Pointer that targets the dropdown | `includes/Assets.php:207-222` (`pointers` => `woi_pdf_document_settings_sections`) |
| Documents API | `WOI_PDF()->documents->get_documents( 'all' )`; each has `get_type()`, `get_title()`, `is_enabled()`; numbered docs have `get_sequential_number_store()->get_next()` (pattern at `includes/Admin.php:1076`) |
| Invoice setting ids | `enabled`, `attach_to_email_ids`, `display_number`, `next_invoice_number`, `number_format` (option `woi_pdf_documents_settings_invoice`) |
| General setting ids | `shop_name`, `shop_address_line_1`, `header_logo`, `checkout_field_enable`, `checkout_field_label`, `checkout_field_as_vat_number`, `checkout_field_enable_my_account` (option `woi_pdf_settings_general`) |
| Sync-address AJAX | `wp_ajax_woi_pdf_sync_address` → `Settings::sync_shop_address_with_woo()` at `includes/Settings.php:1446`; per-field, nonce `woi_pdf_admin_nonce`, POST `address_field` |
| Version | `woocommerce-orders-invoice-pdf.php:24` `public string $version = '1.0.5';` — bump to `1.1.0` in final task (LiteSpeed cache busting) |
| View entry | `views/settings-page.php`; form `id="woi-pdf-settings"`, wrapper `id="woi-pdf-preview-wrapper"` — preview JS depends on these ids/classes; do not rename them |
| Test conventions | PHPUnit + Brain Monkey: `Monkey\setUp()` in `setUp()`, `Functions\when('apply_filters')->returnArg(2)` etc. See `tests/Unit/Documents/DocumentNumberTest.php` |

**File structure (new/modified):**

- Create: `includes/Settings/NavModel.php` — pure nav-model builder (testable, no WP state)
- Create: `includes/Settings/SettingsHome.php` — home tab, checklist, enable-document AJAX, React mount
- Create: `assets/css/admin-shell.css` — shell layout, toggles, accordion restyle, responsive
- Create: `assets/js/admin-shell.js` — dirty tracking, sticky save, show_if engine, hash deep links, badges
- Create: `package.json`, `src/home/index.js`, `src/home/app.js` — Home React app (builds to `assets/js/home/`)
- Modify: `includes/Settings.php` — `show_if` support in `add_settings_fields()`, nav model in `settings_page()`, wire `SettingsHome`
- Modify: `includes/Settings/SettingsGeneral.php` — pilot `show_if` on checkout fields
- Modify: `includes/Settings/SettingsDocuments.php` — drop dropdown picker
- Modify: `includes/Assets.php` — enqueue shell assets, drop stale pointer
- Modify: `views/settings-page.php` — rebuilt as shell
- Test: `tests/Unit/Settings/ShowIfTest.php`, `tests/Unit/Settings/NavModelTest.php`, `tests/Unit/Settings/SettingsHomeChecklistTest.php`

---

### Task 1: `show_if` conditional-field plumbing

Dependent settings declare `'show_if' => array( 'field' => ..., 'value' => ... )` in their field definition. `add_settings_fields()` translates that into CSS classes on the `<tr>` (WP core outputs `$args['class']` on the row). JS (Task 6) reads the classes and toggles visibility. Hidden fields still submit — values are never dropped.

**Files:**
- Modify: `includes/Settings.php` (add static method + 5 lines in `add_settings_fields()` at line 499)
- Modify: `includes/Settings/SettingsGeneral.php` (pilot on 3 checkout fields)
- Test: `tests/Unit/Settings/ShowIfTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Settings/ShowIfTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings;

class ShowIfTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_builds_marker_classes_from_field_and_value(): void {
		$class = Settings::show_if_class( array( 'field' => 'checkout_field_enable', 'value' => 1 ) );
		$this->assertSame( 'woi-show-if woi-show-if--checkout_field_enable--1', $class );
	}

	public function test_returns_empty_string_when_field_missing(): void {
		$this->assertSame( '', Settings::show_if_class( array( 'value' => 1 ) ) );
	}

	public function test_value_defaults_to_1(): void {
		$class = Settings::show_if_class( array( 'field' => 'display_due_date' ) );
		$this->assertSame( 'woi-show-if woi-show-if--display_due_date--1', $class );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter ShowIfTest
```

Expected: FAIL — `Error: Call to undefined method WOI\PDF\Settings::show_if_class()`.

- [ ] **Step 3: Implement `show_if_class()` and wire it into `add_settings_fields()`**

In `includes/Settings.php`, add this method directly above `add_settings_fields()` (line 499):

```php
	/**
	 * Build the <tr> marker classes for a conditionally displayed field.
	 * admin-shell.js parses these to show/hide the row live.
	 *
	 * @param array $show_if array( 'field' => string, 'value' => scalar ) — value defaults to 1
	 *
	 * @return string
	 */
	public static function show_if_class( array $show_if ): string {
		if ( empty( $show_if['field'] ) ) {
			return '';
		}

		$field = sanitize_key( $show_if['field'] );
		$value = sanitize_key( (string) ( $show_if['value'] ?? 1 ) );

		return "woi-show-if woi-show-if--{$field}--{$value}";
	}
```

Then inside `add_settings_fields()`, in the `else` branch (non-section fields, currently line 519), insert before the `add_settings_field(` call:

```php
			} else {
				if ( ! empty( $settings_field['show_if'] ) && is_array( $settings_field['show_if'] ) ) {
					$show_if_class = self::show_if_class( $settings_field['show_if'] );
					if ( '' !== $show_if_class ) {
						$existing_class                  = $settings_field['args']['class'] ?? '';
						$settings_field['args']['class'] = trim( $existing_class . ' ' . $show_if_class );
					}
				}
				add_settings_field(
```

- [ ] **Step 4: Run the test to verify it passes**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter ShowIfTest
```

Expected: 3 tests PASS.

- [ ] **Step 5: Pilot `show_if` on the checkout fields in SettingsGeneral**

In `includes/Settings/SettingsGeneral.php`, find the three field definitions with ids `checkout_field_label`, `checkout_field_as_vat_number`, and `checkout_field_enable_my_account` (search for `'id'       => 'checkout_field_label'` etc.). Add to each field's **top-level** array (sibling of `'type'`, `'id'`, `'callback'` — NOT inside `'args'`):

```php
				'show_if'  => array( 'field' => 'checkout_field_enable', 'value' => 1 ),
```

- [ ] **Step 6: Run the full suite**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php
```

Expected: all tests PASS (including `ServiceWiringTest`).

- [ ] **Step 7: Commit**

```powershell
git add includes/Settings.php includes/Settings/SettingsGeneral.php tests/Unit/Settings/ShowIfTest.php
git commit -m "feat: show_if conditional-field plumbing via tr classes, pilot on checkout fields"
```

---

### Task 2: NavModel builder

Pure static class that turns the filtered tabs array + a documents summary into an ordered list of nav items. The `documents` tab entry expands into a heading plus one item per document. No WP functions inside — fully unit-testable.

**Files:**
- Create: `includes/Settings/NavModel.php`
- Test: `tests/Unit/Settings/NavModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Settings/NavModelTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings\NavModel;

class NavModelTest extends TestCase {

	private function tabs(): array {
		return array(
			'home'      => array( 'title' => 'Home', 'preview_states' => 1 ),
			'general'   => array( 'title' => 'General', 'preview_states' => 3 ),
			'documents' => array( 'title' => 'Documents', 'preview_states' => 3 ),
			'debug'     => array( 'title' => 'Advanced', 'preview_states' => 1 ),
		);
	}

	private function documents(): array {
		return array(
			array( 'type' => 'invoice', 'title' => 'Invoice', 'enabled' => true ),
			array( 'type' => 'packing-slip', 'title' => 'Packing Slip', 'enabled' => false ),
		);
	}

	public function test_documents_tab_expands_to_heading_plus_items(): void {
		$items = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$kinds = array_column( $items, 'kind' );
		$this->assertSame( array( 'tab', 'tab', 'heading', 'document', 'document', 'tab' ), $kinds );
	}

	public function test_active_document_requires_tab_and_section_match(): void {
		$items   = NavModel::build( $this->tabs(), $this->documents(), 'documents', 'invoice' );
		$invoice = $items[3];
		$packing = $items[4];
		$this->assertSame( 'invoice', $invoice['id'] );
		$this->assertTrue( $invoice['active'] );
		$this->assertFalse( $packing['active'] );
	}

	public function test_active_plain_tab(): void {
		$items = NavModel::build( $this->tabs(), $this->documents(), 'debug', '' );
		$debug = end( $items );
		$this->assertTrue( $debug['active'] );
	}

	public function test_document_enabled_flag_passes_through(): void {
		$items = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$this->assertTrue( $items[3]['enabled'] );
		$this->assertFalse( $items[4]['enabled'] );
	}

	public function test_string_tab_title_supported(): void {
		$tabs  = array( 'general' => 'General' );
		$items = NavModel::build( $tabs, array(), 'general', '' );
		$this->assertSame( 'General', $items[0]['label'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter NavModelTest
```

Expected: FAIL — `Error: Class "WOI\PDF\Settings\NavModel" not found`.

- [ ] **Step 3: Implement NavModel**

Create `includes/Settings/NavModel.php`:

```php
<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WOI\\PDF\\Settings\\NavModel' ) ) :

/**
 * Builds the sidebar nav model for the settings shell.
 * Pure data in, pure data out — no WP state, so it stays unit-testable.
 */
class NavModel {

	/**
	 * @param array  $settings_tabs   Filtered tabs array from Settings::settings_page()
	 * @param array  $documents       List of array( 'type' => string, 'title' => string, 'enabled' => bool )
	 * @param string $current_tab
	 * @param string $current_section Document type when on the documents tab
	 *
	 * @return array List of items: array( 'kind' => 'tab'|'heading'|'document', 'id', 'label', 'tab', 'section', 'enabled', 'active' )
	 */
	public static function build( array $settings_tabs, array $documents, string $current_tab, string $current_section ): array {
		$items = array();

		foreach ( $settings_tabs as $tab_key => $tab ) {
			$label = is_array( $tab ) ? (string) ( $tab['title'] ?? $tab_key ) : (string) $tab;

			if ( 'documents' === $tab_key ) {
				$items[] = array(
					'kind'    => 'heading',
					'id'      => 'documents',
					'label'   => $label,
					'tab'     => '',
					'section' => '',
					'enabled' => null,
					'active'  => false,
				);

				foreach ( $documents as $document ) {
					$items[] = array(
						'kind'    => 'document',
						'id'      => (string) $document['type'],
						'label'   => (string) $document['title'],
						'tab'     => 'documents',
						'section' => (string) $document['type'],
						'enabled' => ! empty( $document['enabled'] ),
						'active'  => ( 'documents' === $current_tab && $current_section === $document['type'] ),
					);
				}

				continue;
			}

			$items[] = array(
				'kind'    => 'tab',
				'id'      => (string) $tab_key,
				'label'   => $label,
				'tab'     => (string) $tab_key,
				'section' => '',
				'enabled' => null,
				'active'  => ( $current_tab === $tab_key ),
			);
		}

		return $items;
	}
}

endif; // class_exists
```

- [ ] **Step 4: Run the test to verify it passes**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter NavModelTest
```

Expected: 5 tests PASS.

- [ ] **Step 5: Commit**

```powershell
git add includes/Settings/NavModel.php tests/Unit/Settings/NavModelTest.php
git commit -m "feat: NavModel builder for settings shell sidebar"
```

---

### Task 3: SettingsHome — tab registration, checklist, enable-document AJAX

New settings service following the existing singleton pattern (`SettingsGeneral`, `SettingsDocuments`...). Registers `home` as the first tab and the default, computes the setup checklist (pure static method, TDD), summarizes documents for the cards, handles the one-click Enable AJAX, and renders the React mount div with no-JS fallback links. React enqueue comes in Task 7.

**Files:**
- Create: `includes/Settings/SettingsHome.php`
- Modify: `includes/Settings.php` (wire instance + property)
- Test: `tests/Unit/Settings/SettingsHomeChecklistTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Settings/SettingsHomeChecklistTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings\SettingsHome;

class SettingsHomeChecklistTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_all_items_undone_on_fresh_install(): void {
		$items = SettingsHome::compute_checklist( array(), array(), 1 );
		$this->assertCount( 5, $items );
		foreach ( $items as $item ) {
			$this->assertFalse( $item['done'], "item {$item['id']} should be undone" );
		}
	}

	public function test_shop_address_requires_name_and_line_1(): void {
		$items = SettingsHome::compute_checklist( array( 'shop_name' => 'Milano' ), array(), 1 );
		$this->assertFalse( $items['shop_address']['done'] );

		$items = SettingsHome::compute_checklist(
			array( 'shop_name' => 'Milano', 'shop_address_line_1' => 'Street 1' ),
			array(),
			1
		);
		$this->assertTrue( $items['shop_address']['done'] );
	}

	public function test_multilingual_array_values_count_as_filled(): void {
		$items = SettingsHome::compute_checklist(
			array(
				'shop_name'           => array( 'default' => 'Milano' ),
				'shop_address_line_1' => array( 'default' => 'Street 1' ),
			),
			array(),
			1
		);
		$this->assertTrue( $items['shop_address']['done'] );
	}

	public function test_numbering_done_when_format_set_or_sequence_started(): void {
		$items = SettingsHome::compute_checklist( array(), array( 'number_format' => array( 'prefix' => 'INV-' ) ), 1 );
		$this->assertTrue( $items['numbering']['done'] );

		$items = SettingsHome::compute_checklist( array(), array(), 482 );
		$this->assertTrue( $items['numbering']['done'] );
	}

	public function test_invoice_enabled_logo_and_attachment(): void {
		$items = SettingsHome::compute_checklist(
			array( 'header_logo' => '123' ),
			array( 'enabled' => 1, 'attach_to_email_ids' => array( 'customer_invoice' ) ),
			1
		);
		$this->assertTrue( $items['invoice_enabled']['done'] );
		$this->assertTrue( $items['logo']['done'] );
		$this->assertTrue( $items['email_attachment']['done'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter SettingsHomeChecklistTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement SettingsHome**

Create `includes/Settings/SettingsHome.php`:

```php
<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WOI\\PDF\\Settings\\SettingsHome' ) ) :

class SettingsHome {

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_filter( 'woi_pdf_settings_tabs', array( $this, 'add_home_tab' ), 1 );
		add_filter( 'woi_pdf_settings_tabs_default', array( $this, 'default_tab' ) );
		add_action( 'woi_pdf_settings_output_home', array( $this, 'output' ), 10, 2 );
		add_action( 'wp_ajax_woi_pdf_enable_document', array( $this, 'ajax_enable_document' ) );
	}

	public function add_home_tab( array $tabs ): array {
		return array(
			'home' => array(
				'title'          => __( 'Home', 'woocommerce-orders-invoice-pdf' ),
				'preview_states' => 1,
			),
		) + $tabs;
	}

	public function default_tab(): string {
		return 'home';
	}

	/**
	 * Pure checklist computation. Inputs are raw option arrays so tests need no WP state.
	 *
	 * @param array $general             woi_pdf_settings_general option
	 * @param array $invoice             woi_pdf_documents_settings_invoice option
	 * @param int   $next_invoice_number next number from the invoice sequence store
	 *
	 * @return array id => array( 'id', 'label', 'done', 'tab', 'section', 'anchor' )
	 */
	public static function compute_checklist( array $general, array $invoice, int $next_invoice_number ): array {
		$number_format = isset( $invoice['number_format'] ) && is_array( $invoice['number_format'] )
			? $invoice['number_format']
			: array();

		$items = array(
			'shop_address'     => array(
				'label'   => __( 'Set your shop name & address', 'woocommerce-orders-invoice-pdf' ),
				'done'    => self::setting_filled( $general['shop_name'] ?? '' ) && self::setting_filled( $general['shop_address_line_1'] ?? '' ),
				'tab'     => 'general',
				'section' => '',
				'anchor'  => 'shop_name',
			),
			'invoice_enabled'  => array(
				'label'   => __( 'Enable the invoice', 'woocommerce-orders-invoice-pdf' ),
				'done'    => ! empty( $invoice['enabled'] ),
				'tab'     => 'documents',
				'section' => 'invoice',
				'anchor'  => 'enabled',
			),
			'numbering'        => array(
				'label'   => __( 'Configure invoice numbering', 'woocommerce-orders-invoice-pdf' ),
				'done'    => self::setting_filled( $number_format['prefix'] ?? '' )
					|| self::setting_filled( $number_format['suffix'] ?? '' )
					|| ! empty( $number_format['padding'] )
					|| $next_invoice_number > 1,
				'tab'     => 'documents',
				'section' => 'invoice',
				'anchor'  => 'number_format',
			),
			'logo'             => array(
				'label'   => __( 'Upload a header logo', 'woocommerce-orders-invoice-pdf' ),
				'done'    => ! empty( $general['header_logo'] ),
				'tab'     => 'general',
				'section' => '',
				'anchor'  => 'header_logo',
			),
			'email_attachment' => array(
				'label'   => __( 'Attach the invoice to order emails', 'woocommerce-orders-invoice-pdf' ),
				'done'    => ! empty( $invoice['attach_to_email_ids'] ),
				'tab'     => 'documents',
				'section' => 'invoice',
				'anchor'  => 'attach_to_email_ids',
			),
		);

		foreach ( $items as $id => &$item ) {
			$item['id'] = $id;
		}

		return $items;
	}

	/**
	 * A setting counts as filled when it is a non-empty string, or an array
	 * containing at least one non-empty string (multilingual values).
	 *
	 * @param mixed $value
	 */
	private static function setting_filled( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $entry ) {
				if ( is_string( $entry ) && '' !== trim( $entry ) ) {
					return true;
				}
			}
			return false;
		}

		return is_string( $value ) ? '' !== trim( $value ) : ! empty( $value );
	}

	/**
	 * Gather live checklist state from WP options + number store.
	 */
	public function get_checklist(): array {
		$general = get_option( 'woi_pdf_settings_general', array() );
		$invoice = get_option( 'woi_pdf_documents_settings_invoice', array() );
		$next    = 1;

		$invoice_document = woi_pdf_get_document( 'invoice', null );
		if ( $invoice_document && is_callable( array( $invoice_document, 'get_sequential_number_store' ) ) ) {
			$next = (int) $invoice_document->get_sequential_number_store()->get_next();
		}

		return self::compute_checklist(
			is_array( $general ) ? $general : array(),
			is_array( $invoice ) ? $invoice : array(),
			$next
		);
	}

	/**
	 * Per-document summary for the Home status cards.
	 */
	public function get_documents_summary(): array {
		$summary = array();

		foreach ( WOI_PDF()->documents->get_documents( 'all' ) as $document ) {
			$type     = $document->get_type();
			$settings = get_option( 'woi_pdf_documents_settings_' . $type, array() );
			$settings = is_array( $settings ) ? $settings : array();

			$next_number = null;
			if ( $document->is_enabled() && is_callable( array( $document, 'get_sequential_number_store' ) ) ) {
				$store = $document->get_sequential_number_store();
				if ( $store ) {
					$next_number = (int) $store->get_next();
				}
			}

			$summary[] = array(
				'type'          => $type,
				'title'         => wp_strip_all_tags( $document->get_title() ),
				'enabled'       => $document->is_enabled(),
				'next_number'   => $next_number,
				'email_count'   => is_array( $settings['attach_to_email_ids'] ?? null ) ? count( $settings['attach_to_email_ids'] ) : 0,
				'settings_url'  => add_query_arg(
					array( 'page' => 'woi_pdf_options_page', 'tab' => 'documents', 'section' => $type ),
					admin_url( 'admin.php' )
				),
				'customise_url' => add_query_arg(
					array( 'page' => 'woi_pdf_options_page', 'tab' => 'editor', 'section' => $type ),
					admin_url( 'admin.php' )
				),
			);
		}

		return $summary;
	}

	/**
	 * One-click enable from a Home status card.
	 */
	public function ajax_enable_document(): void {
		check_ajax_referer( 'woi_pdf_admin_nonce', 'security' );

		if ( ! WOI_PDF()->settings->user_can_manage_settings() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change settings.', 'woocommerce-orders-invoice-pdf' ) ) );
		}

		$type      = isset( $_POST['document_type'] ) ? sanitize_key( wp_unslash( $_POST['document_type'] ) ) : '';
		$documents = wp_list_pluck(
			array_map(
				fn( $document ) => array( 'type' => $document->get_type() ),
				WOI_PDF()->documents->get_documents( 'all' )
			),
			'type'
		);

		if ( empty( $type ) || ! in_array( $type, $documents, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown document type.', 'woocommerce-orders-invoice-pdf' ) ) );
		}

		$option_name           = 'woi_pdf_documents_settings_' . $type;
		$settings              = get_option( $option_name, array() );
		$settings              = is_array( $settings ) ? $settings : array();
		$settings['enabled']   = 1;
		update_option( $option_name, $settings );

		wp_send_json_success( array( 'type' => $type, 'enabled' => true ) );
	}

	/**
	 * Render the Home mount point with no-JS fallback links.
	 * Hooked to woi_pdf_settings_output_home (rendered outside the form by the shell view).
	 *
	 * @param string $section unused
	 * @param string $nonce   settings-page nonce
	 */
	public function output( string $section, string $nonce ): void {
		if ( ! wp_verify_nonce( $nonce, 'wp_woi_pdf_settings_page_nonce' ) ) {
			return;
		}
		?>
		<div id="woi-pdf-home-root">
			<p><?php esc_html_e( 'Loading the PDF Invoices dashboard…', 'woocommerce-orders-invoice-pdf' ); ?></p>
			<ul class="woi-home-fallback">
				<li><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'woi_pdf_options_page', 'tab' => 'general' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'General settings', 'woocommerce-orders-invoice-pdf' ); ?></a></li>
				<li><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'woi_pdf_options_page', 'tab' => 'documents', 'section' => 'invoice' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Invoice settings', 'woocommerce-orders-invoice-pdf' ); ?></a></li>
			</ul>
		</div>
		<?php
	}
}

endif; // class_exists
```

- [ ] **Step 4: Run the test to verify it passes**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter SettingsHomeChecklistTest
```

Expected: 5 tests PASS.

- [ ] **Step 5: Wire the instance into Settings**

In `includes/Settings.php`:

1. Add to the `use` block at the top: `use WOI\PDF\Settings\SettingsHome;`
2. Add the typed property next to the others (after `public SettingsEDI $edi;` at line 27): `public SettingsHome $home;`
3. In `__construct()` after `$this->upgrade   = SettingsUpgrade::instance();` (line 51): `$this->home      = SettingsHome::instance();`

- [ ] **Step 6: Run the full suite (ServiceWiringTest guards this wiring)**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php
```

Expected: all PASS.

- [ ] **Step 7: Commit**

```powershell
git add includes/Settings/SettingsHome.php includes/Settings.php tests/Unit/Settings/SettingsHomeChecklistTest.php
git commit -m "feat: SettingsHome service — home tab, setup checklist, enable-document AJAX"
```

---

### Task 4: Shell view rebuild

Rebuild `views/settings-page.php` as the app shell (sticky header / left nav / content) and feed it the nav model from `settings_page()`. The form, dispatch hooks, preview wrapper, and all ids the preview JS depends on stay identical. The Home tab renders **outside** the form (a form around React buttons would hijack clicks as submits). Also remove the now-redundant document dropdown and its pointer.

**Files:**
- Modify: `includes/Settings.php:174-200` (`settings_page()`)
- Modify: `views/settings-page.php` (full rewrite below)
- Modify: `includes/Settings/SettingsDocuments.php:51-75` (remove dropdown)
- Modify: `includes/Assets.php:207-222` (remove stale pointer)

- [ ] **Step 1: Pass the nav model from `settings_page()`**

In `includes/Settings.php`, `settings_page()` (line 174), after the `$settings_tabs = $this->maybe_disable_preview_on_settings_tabs( ... )` line and before the `include`, add:

```php
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		if ( ! array_key_exists( $current_tab, $settings_tabs ) ) {
			$current_tab = apply_filters( 'woi_pdf_settings_tabs_default', ! empty( $settings_tabs['home'] ) ? 'home' : key( $settings_tabs ) );
		}
		$current_section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( 'documents' === $current_tab && '' === $current_section ) {
			$current_section = 'invoice';
		}

		$nav_documents = array_map(
			fn( $document ) => array(
				'type'    => $document->get_type(),
				'title'   => wp_strip_all_tags( $document->get_title() ),
				'enabled' => $document->is_enabled(),
			),
			array_values( WOI_PDF()->documents->get_documents( 'all' ) )
		);

		$nav_items = \WOI\PDF\Settings\NavModel::build( $settings_tabs, $nav_documents, $current_tab, $current_section );
```

Note the existing `$default_tab` line stays (the view still uses it as a fallback); the `woi_pdf_settings_tabs_default` filter now returns `home` via SettingsHome.

- [ ] **Step 2: Rewrite the view**

Replace the entire contents of `views/settings-page.php` with:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// $settings_tabs, $default_tab, $nonce, $current_tab, $current_section, $nav_items are set by Settings::settings_page()

// Map tab key to WP Settings API page/option_group.
// The Documents tab fires woi_pdf_settings_output_documents and handles its own option page internally.
// All other tabs map to 'woi_pdf_settings_{tab}' (or an override in $tab_option_page_map).
$tab_option_page_map = array(
	'editor' => 'woi_pdf_editor_settings',
);

if ( isset( $tab_option_page_map[ $current_tab ] ) ) {
	$option_page = $tab_option_page_map[ $current_tab ];
} else {
	$option_page = 'woi_pdf_settings_' . $current_tab;
}

$preview_states      = isset( $settings_tabs[ $current_tab ]['preview_states'] ) ? $settings_tabs[ $current_tab ]['preview_states'] : 1;
$preview_states_lock = ( 3 === (int) $preview_states ) ? false : true;
$preview_document_type = ( 'documents' === $current_tab && ! empty( $current_section ) ) ? $current_section : 'invoice';

$nav_icons = array(
	'home'    => 'dashicons-admin-home',
	'general' => 'dashicons-admin-settings',
	'editor'  => 'dashicons-admin-customizer',
	'debug'   => 'dashicons-admin-tools',
);

// Breadcrumb: active nav item label (documents get the group label prefix).
$breadcrumb = array();
foreach ( $nav_items as $item ) {
	if ( ! empty( $item['active'] ) ) {
		if ( 'document' === $item['kind'] ) {
			$breadcrumb[] = __( 'Documents', 'woocommerce-orders-invoice-pdf' );
		}
		$breadcrumb[] = $item['label'];
		break;
	}
}
?>
<div class="wrap woi-pdf-settings-page woi-pdf-shell">
	<h1 class="screen-reader-text"><?php esc_html_e( 'PDF Invoices & Packing Slips', 'woocommerce-orders-invoice-pdf' ); ?></h1>

	<header class="woi-shell-header">
		<div class="woi-shell-title">
			<strong><?php esc_html_e( 'PDF Invoices', 'woocommerce-orders-invoice-pdf' ); ?></strong>
			<?php foreach ( $breadcrumb as $crumb ) : ?>
				<span class="woi-shell-crumb">&rsaquo; <?php echo esc_html( $crumb ); ?></span>
			<?php endforeach; ?>
			<span class="woi-shell-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'woocommerce-orders-invoice-pdf' ); ?></span>
		</div>
		<div class="woi-shell-actions">
			<?php if ( in_array( $current_tab, apply_filters( 'woi_pdf_searchable_tabs', array( 'general', 'documents', 'debug' ) ), true ) ) : ?>
				<div class="settings-search">
					<input type="text" name="settings-search" id="wpo-settings-search" placeholder="<?php esc_attr_e( 'Search settings', 'woocommerce-orders-invoice-pdf' ); ?>">
				</div>
			<?php endif; ?>
			<?php if ( 'home' !== $current_tab ) : ?>
				<button type="button" class="button button-primary woi-shell-save" hidden><?php esc_html_e( 'Save', 'woocommerce-orders-invoice-pdf' ); ?></button>
				<button type="button" class="button woi-shell-preview-toggle" hidden><?php esc_html_e( 'Preview', 'woocommerce-orders-invoice-pdf' ); ?></button>
			<?php endif; ?>
		</div>
	</header>

	<?php do_action( 'woi_pdf_before_settings_page', $current_tab, $nonce ); ?>

	<div class="woi-shell-body">
		<nav class="woi-shell-nav" aria-label="<?php esc_attr_e( 'PDF Invoices settings', 'woocommerce-orders-invoice-pdf' ); ?>">
			<ul>
				<?php foreach ( $nav_items as $item ) : ?>
					<?php if ( 'heading' === $item['kind'] ) : ?>
						<li class="woi-nav-heading"><?php echo esc_html( $item['label'] ); ?></li>
					<?php else :
						$url = add_query_arg(
							array_filter( array(
								'page'    => 'woi_pdf_options_page',
								'tab'     => $item['tab'],
								'section' => $item['section'],
							) ),
							admin_url( 'admin.php' )
						);
						$classes = array( 'woi-nav-item', 'woi-nav-' . $item['kind'] );
						if ( $item['active'] ) {
							$classes[] = 'active';
						}
						if ( 'document' === $item['kind'] && empty( $item['enabled'] ) ) {
							$classes[] = 'woi-nav-disabled-doc';
						}
					?>
						<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
							<a href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $item['label'] ); ?>">
								<?php if ( 'tab' === $item['kind'] ) : ?>
									<span class="dashicons <?php echo esc_attr( $nav_icons[ $item['id'] ] ?? 'dashicons-media-document' ); ?>"></span>
								<?php else : ?>
									<span class="woi-nav-dot" aria-hidden="true"></span>
								<?php endif; ?>
								<span class="woi-nav-label"><?php echo esc_html( $item['label'] ); ?></span>
							</a>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</nav>

		<main class="woi-shell-content">
		<?php if ( 'home' === $current_tab ) : ?>

			<?php do_action( 'woi_pdf_settings_output_home', $current_section, $nonce ); ?>

		<?php else : ?>

			<div id="woi-pdf-preview-wrapper"
				class="<?php echo esc_attr( $current_tab ); ?>"
				data-preview-states="<?php echo esc_attr( $preview_states ); ?>"
				data-preview-state="closed"
				data-from-preview-state=""
				data-preview-states-lock="<?php echo esc_attr( $preview_states_lock ); ?>">

				<div class="sidebar">
					<form method="post" action="options.php" id="woi-pdf-settings" class="<?php echo esc_attr( $current_tab ); ?>">
						<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
						<?php
						do_action( 'woi_pdf_before_settings', $current_tab, $nonce );
						if ( has_action( "woi_pdf_settings_output_{$current_tab}" ) ) {
							do_action( "woi_pdf_settings_output_{$current_tab}", $current_section, $nonce );
						} else {
							settings_fields( $option_page );
							do_settings_sections( $option_page );
							submit_button();
						}
						?>
					</form>
				</div>

				<div class="gutter">
					<div class="slider slide-left"><span class="gutter-arrow arrow-left"></span></div>
					<div class="slider slide-right"><span class="gutter-arrow arrow-right"></span></div>
				</div>

				<div class="preview-document">
					<div class="preview-data-wrapper">
						<div class="save-settings"><?php submit_button(); ?></div>
						<div class="preview-data preview-order-data">
							<div class="preview-order-search-wrapper">
								<input type="text" name="preview-order-search" id="preview-order-search"
									placeholder="<?php esc_attr_e( 'ID, email or name', 'woocommerce-orders-invoice-pdf' ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'woi_pdf_preview' ) ); ?>">
							</div>
							<p class="last-order"><?php esc_html_e( 'Currently showing last order', 'woocommerce-orders-invoice-pdf' ); ?><span class="arrow-down">&#9660;</span></p>
							<p class="order-search"><span class="order-search-label"><?php esc_html_e( 'Search for an order', 'woocommerce-orders-invoice-pdf' ); ?></span><span class="arrow-down">&#9660;</span></p>
							<ul>
								<li class="last-order"><?php esc_html_e( 'Show last order', 'woocommerce-orders-invoice-pdf' ); ?></li>
								<li class="order-search"><?php esc_html_e( 'Search for an order', 'woocommerce-orders-invoice-pdf' ); ?></li>
							</ul>
							<div id="preview-order-search-results"></div>
						</div>
						<?php $picker_documents = WOI_PDF()->documents->get_documents( 'enabled', 'any' ); ?>
						<div class="preview-data preview-document-type">
							<p class="current"><span class="current-label"><?php esc_html_e( 'Invoice', 'woocommerce-orders-invoice-pdf' ); ?></span><span class="arrow-down">&#9660;</span></p>
							<ul class="preview-data-option-list" data-input-name="document_type">
								<?php foreach ( $picker_documents as $doc ) : ?>
									<li data-value="<?php echo esc_attr( $doc->get_type() ); ?>"><?php echo esc_html( $doc->get_title() ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
					<input type="hidden" name="document_type" data-default="<?php echo esc_attr( $preview_document_type ); ?>" value="<?php echo esc_attr( $preview_document_type ); ?>">
					<input type="hidden" name="output_format" value="pdf">
					<input type="hidden" name="order_id" value="">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'woi_pdf_preview' ) ); ?>">
					<div class="preview"></div>
				</div>

			</div>

		<?php endif; ?>
		</main>
	</div>

	<?php do_action( 'woi_pdf_after_settings_page', $current_tab, $nonce ); ?>
</div>
```

Changes vs the old view, for the reviewer: the `<nav class="nav-tab-wrapper">` horizontal tabs are gone (replaced by the sidebar); the search box moved from `.sidebar` into the header (same `#wpo-settings-search` id so `admin.js` search keeps working); `$current_tab`/`$current_section` now come from `settings_page()` instead of being computed in the view; everything inside `#woi-pdf-preview-wrapper` is byte-identical to before.

- [ ] **Step 3: Remove the dropdown picker from SettingsDocuments**

In `includes/Settings/SettingsDocuments.php`, `output()`: delete the `$active_title` computation (lines 47-50) and the whole `<div class="wcpdf_document_settings_sections">…</div>` block with its PHP loop (lines 51-76), leaving:

```php
		$option_name = 'woi_pdf_documents_settings_' . $section;

		settings_fields( $option_name );
		do_settings_sections( $option_name );
		submit_button();
	}
```

- [ ] **Step 4: Remove the stale pointer in Assets.php**

In `includes/Assets.php` (lines 207-222), the pointer targets the dropdown that no longer exists. Replace the `'pointers' => array( ... )` value with an empty array:

```php
					'pointers'                  => array(),
```

(`admin.js` iterates the pointers object; empty is safe.)

- [ ] **Step 5: Run the suite + smoke-check PHP syntax**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php
php -l views/settings-page.php && php -l includes/Settings.php && php -l includes/Settings/SettingsDocuments.php
```

Expected: tests PASS; `No syntax errors detected` ×3.

- [ ] **Step 6: Commit**

```powershell
git add views/settings-page.php includes/Settings.php includes/Settings/SettingsDocuments.php includes/Assets.php
git commit -m "feat: rebuild settings page as left-nav app shell, drop document dropdown picker"
```

> Note: until Task 5-6 land, the page will look unstyled and the header Save button stays hidden — the bottom `submit_button()` still saves. That's the intended no-JS fallback, so the plugin remains usable between commits.

---

### Task 5: Shell stylesheet (`admin-shell.css`)

All shell visuals: layout grid, dark sticky header, sidebar, CSS-only toggle switches over the existing checkboxes, accordion card restyle, conditional-row styling, responsive breakpoints.

**Files:**
- Create: `assets/css/admin-shell.css`
- Modify: `includes/Assets.php` (enqueue, after the `woi-pdf-settings-styles` enqueue at line 98)

- [ ] **Step 1: Create the stylesheet**

Create `assets/css/admin-shell.css`:

```css
/* ===== PDF Invoices settings shell ===== */

.woi-pdf-shell { margin: 0 20px 0 0; }

/* --- Sticky header --- */
.woi-shell-header {
	position: sticky;
	top: 32px; /* WP admin bar */
	z-index: 100;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-left: -20px;
	padding: 10px 16px 10px 36px;
	background: #1d2327;
	color: #fff;
}
.woi-shell-title { font-size: 14px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.woi-shell-crumb { color: #a7aaad; }
.woi-shell-dirty {
	background: #996800;
	color: #fff;
	border-radius: 10px;
	padding: 1px 10px;
	font-size: 11px;
}
.woi-shell-actions { display: flex; align-items: center; gap: 8px; }
.woi-shell-actions .settings-search input {
	background: #2c3338;
	border: 1px solid #50575e;
	border-radius: 4px;
	color: #f0f0f1;
	min-width: 180px;
}
.woi-shell-actions .settings-search input::placeholder { color: #a7aaad; }

/* --- Body: nav + content --- */
.woi-shell-body { display: flex; align-items: stretch; min-height: 70vh; }

.woi-shell-nav {
	flex: 0 0 190px;
	background: #fff;
	border-right: 1px solid #dcdcde;
}
.woi-shell-nav ul { margin: 0; padding: 8px 0; list-style: none; }
.woi-nav-heading {
	padding: 12px 16px 4px;
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: .5px;
	color: #787c82;
}
.woi-nav-item a {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	text-decoration: none;
	color: #1d2327;
	border-left: 3px solid transparent;
}
.woi-nav-item a:hover { background: #f6f7f7; color: #135e96; }
.woi-nav-item.active a {
	background: #f0f6fc;
	border-left-color: #2271b1;
	font-weight: 600;
	color: #135e96;
}
.woi-nav-document a { padding-left: 24px; }
.woi-nav-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: #00a32a;
	flex: 0 0 8px;
}
.woi-nav-disabled-doc a { color: #787c82; }
.woi-nav-disabled-doc .woi-nav-dot { background: transparent; border: 1px solid #a7aaad; }

.woi-shell-content { flex: 1; min-width: 0; padding: 16px; }

/* --- Toggle switches (CSS-only, over native checkboxes) --- */
.woi-pdf-shell .form-table input[type="checkbox"] {
	appearance: none;
	-webkit-appearance: none;
	width: 36px;
	height: 20px;
	margin: 0 6px 0 0;
	border: none;
	border-radius: 10px;
	background: #c3c4c7;
	position: relative;
	cursor: pointer;
	transition: background .15s ease;
	vertical-align: middle;
}
.woi-pdf-shell .form-table input[type="checkbox"]::before {
	content: "";
	position: absolute;
	top: 2px;
	left: 2px;
	width: 16px;
	height: 16px;
	border-radius: 50%;
	background: #fff;
	transition: left .15s ease;
}
.woi-pdf-shell .form-table input[type="checkbox"]:checked { background: #2271b1; }
.woi-pdf-shell .form-table input[type="checkbox"]:checked::before { left: 18px; }
.woi-pdf-shell .form-table input[type="checkbox"]:focus-visible {
	outline: 2px solid #2271b1;
	outline-offset: 2px;
}

/* --- Accordion restyle (.settings_category markup already exists) --- */
.woi-pdf-shell .settings_category {
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 6px;
	margin-bottom: 10px;
	overflow: hidden;
}
.woi-pdf-shell .settings_category > h2 {
	margin: 0;
	padding: 12px 16px;
	font-size: 14px;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: space-between;
}
.woi-pdf-shell .settings_category > h2:hover { background: #f6f7f7; }
.woi-count {
	background: #f0f0f1;
	color: #50575e;
	border-radius: 10px;
	padding: 0 8px;
	font-size: 11px;
	font-weight: 400;
}

/* --- Conditional rows --- */
.woi-pdf-shell tr.woi-show-if.woi-hidden { display: none; }
.woi-pdf-shell tr.woi-show-if > th { padding-left: 24px; }

/* --- JS-booted fallbacks --- */
.woi-pdf-shell .woi-js-hidden { display: none; }

/* --- Responsive --- */
@media screen and (max-width: 1280px) {
	.woi-shell-nav { flex-basis: 48px; }
	.woi-shell-nav .woi-nav-label,
	.woi-shell-nav .woi-nav-heading { display: none; }
	.woi-nav-document a { padding-left: 16px; }
}

@media screen and (max-width: 1100px) {
	/* Preview becomes a toggled overlay (admin-shell.js toggles the class) */
	.woi-pdf-shell #woi-pdf-preview-wrapper .gutter { display: none; }
	.woi-pdf-shell #woi-pdf-preview-wrapper .preview-document { display: none; }
	.woi-pdf-shell.woi-preview-overlay-open #woi-pdf-preview-wrapper .preview-document {
		display: block;
		position: fixed;
		top: 88px;
		right: 16px;
		bottom: 16px;
		width: min(480px, 90vw);
		background: #fff;
		border: 1px solid #dcdcde;
		border-radius: 6px;
		box-shadow: 0 4px 20px rgba(0,0,0,.18);
		z-index: 99;
		overflow: auto;
	}
}
```

- [ ] **Step 2: Enqueue it**

In `includes/Assets.php`, directly after the `woi-pdf-settings-styles` `wp_enqueue_style` call (after line 103), add:

```php
			wp_enqueue_style(
				'woi-pdf-admin-shell',
				WOI_PDF()->plugin_url() . '/assets/css/admin-shell.css',
				array( 'woi-pdf-settings-styles' ),
				WOI_PDF_VERSION
			);
```

- [ ] **Step 3: Lint check + commit**

```powershell
php -l includes/Assets.php
git add assets/css/admin-shell.css includes/Assets.php
git commit -m "feat: shell stylesheet — layout, dark header, nav, toggles, accordion cards, responsive"
```

---

### Task 6: Shell behavior (`admin-shell.js`)

Dirty tracking + sticky Save, the `show_if` engine, accordion count badges, hash deep links (`#field_id` opens the right accordion group and scrolls to the row), and the small-screen preview overlay toggle. jQuery, matching `admin.js` conventions.

**Files:**
- Create: `assets/js/admin-shell.js`
- Modify: `includes/Assets.php` (enqueue after `woi-pdf-admin` at line 163)

- [ ] **Step 1: Create the script**

Create `assets/js/admin-shell.js`:

```javascript
jQuery( function( $ ) {
	const $shell = $( '.woi-pdf-shell' );

	if ( ! $shell.length ) {
		return;
	}

	const $form = $( '#woi-pdf-settings' );

	//----------> Sticky save + dirty tracking <----------//
	if ( $form.length ) {
		// JS booted: reveal header buttons, hide the in-form fallback submit
		$( '.woi-shell-save' ).prop( 'hidden', false );
		$form.find( 'p.submit' ).addClass( 'woi-js-hidden' );

		$form.on( 'change input', ':input', function() {
			$( '.woi-shell-dirty' ).prop( 'hidden', false );
			window.onbeforeunload = function() { return true; };
		} );

		$form.on( 'submit', function() {
			window.onbeforeunload = null;
		} );

		$( '.woi-shell-save' ).on( 'click', function() {
			if ( $form[0].requestSubmit ) {
				$form[0].requestSubmit();
			} else {
				$form.trigger( 'submit' );
			}
		} );
	}

	//----------> Conditional fields (show_if) <----------//
	function showIfRules() {
		const rules = [];

		$form.find( 'tr.woi-show-if' ).each( function() {
			const $row     = $( this );
			const classes  = ( $row.attr( 'class' ) || '' ).split( /\s+/ );
			const ruleData = classes.find( ( c ) => c.indexOf( 'woi-show-if--' ) === 0 );

			if ( ! ruleData ) {
				return;
			}

			const parts = ruleData.replace( 'woi-show-if--', '' ).split( '--' );

			if ( parts.length !== 2 ) {
				return;
			}

			rules.push( { $row: $row, field: parts[0], value: parts[1] } );
		} );

		return rules;
	}

	function controllerFor( field ) {
		// Field names look like option_name[field_id]
		return $form.find( ':input[name$="[' + field + ']"]' ).first();
	}

	function applyShowIf( rules ) {
		rules.forEach( function( rule ) {
			const $controller = controllerFor( rule.field );

			if ( ! $controller.length ) {
				return;
			}

			let current;

			if ( $controller.is( ':checkbox' ) ) {
				current = $controller.is( ':checked' ) ? '1' : '0';
			} else {
				current = String( $controller.val() );
			}

			rule.$row.toggleClass( 'woi-hidden', current !== rule.value );
		} );
	}

	const rules = showIfRules();

	if ( rules.length ) {
		applyShowIf( rules );

		const fields = [ ...new Set( rules.map( ( r ) => r.field ) ) ];

		fields.forEach( function( field ) {
			controllerFor( field ).on( 'change', function() {
				applyShowIf( rules );
			} );
		} );
	}

	//----------> Accordion count badges <----------//
	$shell.find( '.settings_category' ).each( function() {
		const count = $( this ).find( 'tbody tr' ).length;

		if ( count > 0 ) {
			$( this ).children( 'h2' ).append( ' <span class="woi-count">' + count + '</span>' );
		}
	} );

	//----------> Hash deep links: #field_id opens its group and scrolls to the row <----------//
	function openHashTarget() {
		const id = window.location.hash.replace( '#', '' );

		if ( ! id || ! $form.length ) {
			return;
		}

		const $row = $form.find( ':input[name$="[' + id + ']"]' ).first().closest( 'tr' );

		if ( ! $row.length ) {
			return;
		}

		const $category = $row.closest( '.settings_category' );
		const $header   = $category.children( 'h2' );

		// settingsAccordion() (admin.js) collapses the table; clicking the header opens it
		if ( $header.length && $header.attr( 'aria-expanded' ) === 'false' ) {
			$header.trigger( 'click' );
		}

		$row[0].scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	openHashTarget();
	$( window ).on( 'hashchange', openHashTarget );

	//----------> Small-screen preview overlay <----------//
	if ( $( '#woi-pdf-preview-wrapper' ).length ) {
		$( '.woi-shell-preview-toggle' ).prop( 'hidden', false ).on( 'click', function() {
			$shell.toggleClass( 'woi-preview-overlay-open' );
		} );
	}
} );
```

- [ ] **Step 2: Enqueue it**

In `includes/Assets.php`, directly after the `woi-pdf-admin` `wp_enqueue_script` call (after line 169), add:

```php
			wp_enqueue_script(
				'woi-pdf-admin-shell',
				WOI_PDF()->plugin_url() . '/assets/js/admin-shell.js',
				array( 'woi-pdf-admin' ),
				WOI_PDF_VERSION,
				true
			);
```

- [ ] **Step 3: Lint + commit**

```powershell
php -l includes/Assets.php
git add assets/js/admin-shell.js includes/Assets.php
git commit -m "feat: shell behavior — sticky save, dirty badge, show_if engine, hash deep links"
```

---

### Task 7: Home dashboard React app

`@wordpress/scripts` build, React app on `wp.element`/`wp.components`, mounted on `#woi-pdf-home-root`. Data is injected via `wp_localize_script` — no REST round-trip. The built bundle (`assets/js/home/`) is committed since there is no CI build.

**Files:**
- Create: `package.json`
- Create: `src/home/index.js`, `src/home/app.js`
- Modify: `.gitignore` (node_modules)
- Modify: `includes/Settings/SettingsHome.php` (enqueue + localize)

- [ ] **Step 1: Create package.json**

```json
{
	"name": "woocommerce-orders-invoice-pdf",
	"version": "1.1.0",
	"private": true,
	"scripts": {
		"build": "wp-scripts build src/home/index.js --output-path=assets/js/home",
		"start": "wp-scripts start src/home/index.js --output-path=assets/js/home"
	},
	"devDependencies": {
		"@wordpress/scripts": "^30.0.0"
	}
}
```

Add to `.gitignore` under the `# Brainstorming companion sessions` block:

```
# Node
node_modules/
```

- [ ] **Step 2: Create the app entry**

Create `src/home/index.js`:

```javascript
import { createRoot } from '@wordpress/element';
import App from './app';

const mount = document.getElementById( 'woi-pdf-home-root' );

if ( mount && window.woiPdfHome ) {
	createRoot( mount ).render( <App data={ window.woiPdfHome } /> );
}
```

- [ ] **Step 3: Create the app component**

Create `src/home/app.js`:

```javascript
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
} from '@wordpress/components';

function checklistUrl( data, item ) {
	const params = new URLSearchParams( {
		page: 'woi_pdf_options_page',
		tab: item.tab,
	} );

	if ( item.section ) {
		params.set( 'section', item.section );
	}

	return data.adminUrl + 'admin.php?' + params.toString() + ( item.anchor ? '#' + item.anchor : '' );
}

function SetupChecklist( { data } ) {
	const storageKey = 'woiPdfHomeChecklistDismissed';
	const [ dismissed, setDismissed ] = useState( window.localStorage.getItem( storageKey ) === '1' );
	const items = Object.values( data.checklist );
	const done = items.filter( ( item ) => item.done ).length;

	if ( dismissed || done === items.length ) {
		return null;
	}

	return (
		<Card className="woi-home-checklist">
			<CardHeader>
				<strong>{ __( 'Finish setting up PDF Invoices', 'woocommerce-orders-invoice-pdf' ) }</strong>
				<Flex justify="flex-end" gap={ 2 }>
					<FlexItem>{ done }/{ items.length }</FlexItem>
					<Button
						size="small"
						variant="tertiary"
						onClick={ () => {
							window.localStorage.setItem( storageKey, '1' );
							setDismissed( true );
						} }
					>
						{ __( 'Dismiss', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
				</Flex>
			</CardHeader>
			<CardBody>
				<div className="woi-home-progress">
					<div className="woi-home-progress-bar" style={ { width: `${ ( done / items.length ) * 100 }%` } } />
				</div>
				<ul>
					{ items.map( ( item ) => (
						<li key={ item.id } className={ item.done ? 'done' : 'todo' }>
							{ item.done ? '✓ ' : '○ ' }
							{ item.done ? item.label : <a href={ checklistUrl( data, item ) }>{ item.label } ›</a> }
						</li>
					) ) }
				</ul>
			</CardBody>
		</Card>
	);
}

function DocumentCard( { doc, data } ) {
	const [ enabled, setEnabled ] = useState( doc.enabled );
	const [ busy, setBusy ] = useState( false );

	const enable = async () => {
		setBusy( true );

		const body = new FormData();
		body.set( 'action', 'woi_pdf_enable_document' );
		body.set( 'security', data.nonce );
		body.set( 'document_type', doc.type );

		const response = await window.fetch( data.ajaxUrl, { method: 'POST', body } );
		const json = await response.json();

		setBusy( false );

		if ( json.success ) {
			setEnabled( true );
		}
	};

	return (
		<Card className={ 'woi-home-doc-card' + ( enabled ? '' : ' is-off' ) }>
			<CardHeader>
				<strong>{ doc.title }</strong>
				<span className={ 'woi-pill ' + ( enabled ? 'on' : 'off' ) }>
					{ enabled ? __( 'Enabled', 'woocommerce-orders-invoice-pdf' ) : __( 'Off', 'woocommerce-orders-invoice-pdf' ) }
				</span>
			</CardHeader>
			<CardBody>
				{ enabled && doc.next_number !== null && (
					<p>{ __( 'Next number:', 'woocommerce-orders-invoice-pdf' ) } <strong>{ doc.next_number }</strong></p>
				) }
				{ enabled && (
					<p>
						{ doc.email_count > 0
							? __( 'Attached to', 'woocommerce-orders-invoice-pdf' ) + ' ' + doc.email_count + ' ' + __( 'email(s)', 'woocommerce-orders-invoice-pdf' )
							: __( 'Manual download only', 'woocommerce-orders-invoice-pdf' ) }
					</p>
				) }
				<Flex justify="flex-start" gap={ 2 }>
					{ enabled ? (
						<>
							<Button variant="link" href={ doc.settings_url }>{ __( 'Settings ›', 'woocommerce-orders-invoice-pdf' ) }</Button>
							<Button variant="link" href={ doc.customise_url }>{ __( 'Customise ›', 'woocommerce-orders-invoice-pdf' ) }</Button>
						</>
					) : (
						<Button variant="secondary" size="small" isBusy={ busy } onClick={ enable }>
							{ __( 'Enable', 'woocommerce-orders-invoice-pdf' ) }
						</Button>
					) }
				</Flex>
			</CardBody>
		</Card>
	);
}

function QuickActions( { data } ) {
	const [ syncing, setSyncing ] = useState( false );
	const [ syncDone, setSyncDone ] = useState( false );

	const syncAddress = async () => {
		setSyncing( true );

		const fields = [
			'shop_address_line_1',
			'shop_address_line_2',
			'shop_address_country',
			'shop_address_state',
			'shop_address_city',
			'shop_address_postcode',
		];

		await Promise.all( fields.map( ( field ) => {
			const body = new FormData();
			body.set( 'action', 'woi_pdf_sync_address' );
			body.set( 'security', data.nonce );
			body.set( 'address_field', field );
			return window.fetch( data.ajaxUrl, { method: 'POST', body } );
		} ) );

		setSyncing( false );
		setSyncDone( true );
	};

	return (
		<Card className="woi-home-quick-actions">
			<CardBody>
				<Flex justify="flex-start" gap={ 2 } wrap>
					<strong>{ __( 'Quick actions', 'woocommerce-orders-invoice-pdf' ) }</strong>
					<Button variant="secondary" href={ data.urls.previewInvoice }>
						{ __( 'Preview last invoice', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
					<Button variant="secondary" href={ data.urls.setNextNumber }>
						{ __( 'Set next number', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
					<Button variant="secondary" isBusy={ syncing } onClick={ syncAddress }>
						{ syncDone
							? __( 'Address synced ✓', 'woocommerce-orders-invoice-pdf' )
							: __( 'Sync shop address', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
					<Button variant="secondary" href={ data.urls.customiser }>
						{ __( 'Open Customiser', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
				</Flex>
			</CardBody>
		</Card>
	);
}

export default function App( { data } ) {
	return (
		<div className="woi-home">
			<SetupChecklist data={ data } />
			<div className="woi-home-cards">
				{ data.documents.map( ( doc ) => (
					<DocumentCard key={ doc.type } doc={ doc } data={ data } />
				) ) }
			</div>
			<QuickActions data={ data } />
		</div>
	);
}
```

- [ ] **Step 4: Install and build**

```powershell
npm install
npm run build
```

Expected: `assets/js/home/index.js` and `assets/js/home/index.asset.php` exist after the build. (First `npm install` takes a few minutes.)

- [ ] **Step 5: Enqueue + localize in SettingsHome**

In `includes/Settings/SettingsHome.php`, add to `__construct()`:

```php
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
```

And add these methods to the class:

```php
	/**
	 * Enqueue the Home app on our settings page when the home tab is active.
	 *
	 * @param string $hook
	 */
	public function enqueue( $hook ): void {
		if ( empty( $hook ) || false === strpos( $hook, 'woi_pdf_options_page' ) ) {
			return;
		}

		$tab = sanitize_text_field( (string) filter_input( INPUT_GET, 'tab', FILTER_DEFAULT ) );
		if ( ! in_array( $tab, array( '', 'home' ), true ) ) {
			return;
		}

		$asset_file = WOI_PDF()->plugin_path() . '/assets/js/home/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return; // bundle not built — the PHP fallback links render instead
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'woi-pdf-home',
			WOI_PDF()->plugin_url() . '/assets/js/home/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style( 'wp-components' );

		wp_localize_script( 'woi-pdf-home', 'woiPdfHome', $this->get_home_data() );
	}

	/**
	 * Everything the React app needs, injected at page load.
	 */
	public function get_home_data(): array {
		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'adminUrl'  => admin_url(),
			'nonce'     => wp_create_nonce( 'woi_pdf_admin_nonce' ),
			'checklist' => $this->get_checklist(),
			'documents' => $this->get_documents_summary(),
			'urls'      => array(
				'previewInvoice' => add_query_arg(
					array( 'page' => 'woi_pdf_options_page', 'tab' => 'documents', 'section' => 'invoice' ),
					admin_url( 'admin.php' )
				),
				'setNextNumber'  => add_query_arg(
					array( 'page' => 'woi_pdf_options_page', 'tab' => 'documents', 'section' => 'invoice' ),
					admin_url( 'admin.php' )
				) . '#next_invoice_number',
				'customiser'     => add_query_arg(
					array( 'page' => 'woi_pdf_options_page', 'tab' => 'editor' ),
					admin_url( 'admin.php' )
				),
			),
		);
	}
```

- [ ] **Step 6: Home layout CSS**

Append to `assets/css/admin-shell.css`:

```css
/* --- Home dashboard --- */
.woi-home { max-width: 1080px; }
.woi-home .components-card { margin-bottom: 12px; }
.woi-home-progress {
	height: 6px;
	background: #f0f0f1;
	border-radius: 3px;
	margin-bottom: 10px;
}
.woi-home-progress-bar { height: 6px; background: #2271b1; border-radius: 3px; }
.woi-home-checklist ul { margin: 0; }
.woi-home-checklist li.done { color: #00a32a; }
.woi-home-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 12px;
	margin-bottom: 12px;
}
.woi-home-doc-card.is-off .components-card__header strong { color: #787c82; }
.woi-pill { border-radius: 10px; padding: 1px 10px; font-size: 11px; }
.woi-pill.on { background: #c6e1c6; color: #5b841b; }
.woi-pill.off { background: #f0f0f1; color: #787c82; }
```

- [ ] **Step 7: Run the suite, rebuild, commit**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php
npm run build
git add package.json package-lock.json src/ assets/js/home/ assets/css/admin-shell.css includes/Settings/SettingsHome.php .gitignore
git commit -m "feat: Home dashboard React app — checklist, document cards, quick actions"
```

---

### Task 8: Version bump + full verification

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php:24` and the `Version:` plugin header at line 6

- [ ] **Step 1: Bump the version (LiteSpeed cache busting — required whenever CSS/JS changes)**

In `woocommerce-orders-invoice-pdf.php`:
- Line 6: `* Version:              1.1.0`
- Line 24: `public string $version     = '1.1.0';`

- [ ] **Step 2: Run the complete test suite**

```powershell
vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php
```

Expected: all PASS, zero warnings about ServiceWiring.

- [ ] **Step 3: Manual verification pass (use Playwright/browser against the dev site)**

Walk this checklist and record results:

1. `WooCommerce → PDF Invoices` lands on **Home**: checklist shows correct done/undone state, document cards match enabled documents, quick actions render.
2. Click **Enable** on a disabled document card → pill flips to Enabled without reload; reload → nav dot for that document is now green.
3. Checklist link (e.g. "Upload a header logo") → General tab opens with the right accordion group expanded and the row scrolled into view.
4. **General** tab: sidebar nav highlights General; toggles render as switches; checking "Enable checkout field" reveals the three dependent checkout rows live; unchecking hides them.
5. Edit any field → "Unsaved changes" badge appears in header; header **Save** persists the change; navigating away with unsaved changes triggers the browser warning.
6. **Documents → Invoice**: settings + live PDF preview side by side; preview still reflects live form edits; document switching via the sidebar (no dropdown anywhere).
7. **Advanced** tab renders with accordion cards and no preview pane.
8. **Customiser** opens and behaves exactly as before this project.
9. Narrow the window below 1280px → nav collapses to icons; below 1100px → preview hidden, header Preview button toggles the overlay.
10. Disable JS (DevTools) → settings tabs render all groups expanded with a visible bottom Save button that saves; Home shows fallback links.

- [ ] **Step 4: Commit the bump**

```powershell
git add woocommerce-orders-invoice-pdf.php
git commit -m "chore: bump to 1.1.0 for settings shell UI overhaul"
```

---

## Plan self-review notes (done at write time)

- **Spec coverage:** shell+nav (Task 4), Home dashboard with all three blocks (Tasks 3, 7), accordion/toggles/conditionals/sticky save (Tasks 1, 5, 6), preview preservation + responsive (Tasks 4-6), dropdown removal (Task 4), graceful degradation (Tasks 4, 6, 7), tests incl. ServiceWiringTest (every task), version bump (Task 8). The spec's "field-count badge" lands in Task 6 via JS.
- **Known simplification vs spec:** the spec's per-screen `localStorage` accordion memory and search auto-expand already exist in `admin.js` — no task needed, verified at `admin.js:786-905` and `admin.js:1003-1030`.
- **Type consistency check:** `NavModel::build()` consumed in Task 4 Step 1 matches Task 2 signature; `SettingsHome::compute_checklist( array, array, int )` matches test usage; `woiPdfHome` keys (`ajaxUrl`, `adminUrl`, `nonce`, `checklist`, `documents`, `urls.previewInvoice/setNextNumber/customiser`) match `app.js` usage; `enable` AJAX posts `security` + `document_type`, matching `ajax_enable_document()`.
