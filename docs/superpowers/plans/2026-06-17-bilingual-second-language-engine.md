# Bilingual Second-Language Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a general, user-configurable second-language engine (with an Arabic preset) that renders a second language alongside the primary in three patterns — mirror blocks, stacked label pairs, inline label pairs — plus a scaffolded "Standard UAE Tax Invoice" preset template.

**Architecture:** A `BilingualEngine` service owns enablement, the seed dictionary + user-override resolution, content-value resolvers, and font/RTL CSS. Label pairs are rendered by a centralized `OrderDocument::render_label()` helper and by the engine hooking the `woi_pdf_templates_table_headers` / `woi_pdf_templates_totals` filters to add a `secondary` value. Mirror blocks are produced by new `OrderDocument` helper methods that each template calls, delegating to today's output when disabled. A Customiser "Second language" section drives it; a preset template pre-wires it.

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce, Dompdf (bundled, strauss-namespaced `WOI\PDF\Vendor\Dompdf`), PHPUnit 9.5 + Brain\Monkey for unit tests.

## Global Constraints

- Naming: all new PHP/JS/CSS identifiers use the `woi_pdf` / `WOI\PDF` prefix.
- When disabled, output must be byte-for-byte today's output; engine assets (`@font-face`, dictionary) must NOT load.
- Secondary text always falls back to primary — never render blank.
- Bump `WOI_PDF_VERSION` in `woocommerce-orders-invoice-pdf.php` whenever CSS/JS selectors change (LiteSpeed cache on live site).
- Tests run with: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter <name>` (phpunit dies silently without the `auto_prepend_file` flag — known gotcha).
- Test style: namespace `WOI\PDF\Tests\Unit`, `Brain\Monkey` setUp/tearDown, stub WP functions with `Functions\when(...)`.
- After any container wiring change, run `tests/Unit/ServiceWiringTest.php`.
- Arabic font is **Noto Naskh Arabic** (Regular + Bold, SIL OFL), bundled per-template under `fonts/`.

---

### Task 1: BilingualEngine core — enablement + label resolution

**Files:**
- Create: `includes/Bilingual/BilingualEngine.php`
- Create: `includes/Bilingual/dictionary/ar.php`
- Test: `tests/Unit/Bilingual/BilingualEngineTest.php`

**Interfaces:**
- Produces:
  - `WOI\PDF\Bilingual\BilingualEngine::instance(): BilingualEngine`
  - `is_enabled( $document ): bool`
  - `secondary_language( $document ): string`
  - `is_rtl( $document ): bool`
  - `dictionary( string $language = 'ar' ): array`
  - `secondary_label( string $key, $document ): string`
- Consumes: `$document` exposes `get_setting( string $key )` returning the saved value or null (existing `OrderDocument` method).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Bilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Bilingual\BilingualEngine;

class BilingualEngineTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg( 1 );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function doc( array $settings ) {
		return new class( $settings ) {
			private $s;
			public function __construct( $s ) { $this->s = $s; }
			public function get_setting( $k ) { return $this->s[ $k ] ?? null; }
		};
	}

	public function test_is_enabled_false_by_default(): void {
		$this->assertFalse( BilingualEngine::instance()->is_enabled( $this->doc( array() ) ) );
	}

	public function test_is_enabled_true_when_set(): void {
		$doc = $this->doc( array( 'enable_second_language' => 1 ) );
		$this->assertTrue( BilingualEngine::instance()->is_enabled( $doc ) );
	}

	public function test_secondary_language_defaults_to_ar(): void {
		$this->assertSame( 'ar', BilingualEngine::instance()->secondary_language( $this->doc( array() ) ) );
	}

	public function test_is_rtl_true_for_arabic(): void {
		$this->assertTrue( BilingualEngine::instance()->is_rtl( $this->doc( array( 'second_language' => 'ar' ) ) ) );
	}

	public function test_label_falls_back_to_dictionary(): void {
		$doc = $this->doc( array() );
		$this->assertSame( 'رقم الفاتورة', BilingualEngine::instance()->secondary_label( 'document_number', $doc ) );
	}

	public function test_user_override_wins_over_dictionary(): void {
		$doc = $this->doc( array( 'second_language_labels' => array( 'document_number' => 'CUSTOM AR' ) ) );
		$this->assertSame( 'CUSTOM AR', BilingualEngine::instance()->secondary_label( 'document_number', $doc ) );
	}

	public function test_unknown_label_returns_empty(): void {
		$this->assertSame( '', BilingualEngine::instance()->secondary_label( 'nope', $this->doc( array() ) ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualEngineTest`
Expected: FAIL — class `WOI\PDF\Bilingual\BilingualEngine` not found.

- [ ] **Step 3: Create the seed dictionary**

`includes/Bilingual/dictionary/ar.php`:

```php
<?php
// Seed Arabic translations for fixed labels. User overrides (saved settings)
// take precedence at runtime; blanks fall back to these values.
if ( ! defined( 'ABSPATH' ) ) exit;

return array(
	// document labels (keys mirror OrderDocument::get_title_for slugs)
	'document'          => 'فاتورة ضريبية',
	'document_number'   => 'رقم الفاتورة',
	'document_date'     => 'التاريخ',
	'document_due_date' => 'تاريخ الاستحقاق',
	'billing_address'   => 'المشترى',
	'shipping_address'  => 'عنوان الشحن',
	'order_number'      => 'رقم المرجع',
	'order_date'        => 'تاريخ الطلب',
	// item-table column types
	'sku'               => 'رقم القطعة',
	'description'       => 'البيان الصنف',
	'quantity'          => 'الكمية',
	'price'             => 'المبلغ',
	'tax_rate'          => 'معدل الضريبة %',
	'weight'            => 'الوزن',
	// totals types
	'subtotal'          => 'المجموع الفرعي',
	'discount'          => 'الخصم',
	'shipping'          => 'الشحن',
	'fee'               => 'رسوم',
	'vat'               => 'ضريبة القيمة المضافة',
	'total'             => 'المجموع',
);
```

- [ ] **Step 4: Create the engine**

`includes/Bilingual/BilingualEngine.php`:

```php
<?php
namespace WOI\PDF\Bilingual;

if ( ! defined( 'ABSPATH' ) ) exit;

class BilingualEngine {

	protected static $instance = null;

	/** RTL language codes. */
	protected $rtl_languages = array( 'ar', 'he', 'fa', 'ur' );

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_enabled( $document ): bool {
		return ! empty( $document->get_setting( 'enable_second_language' ) );
	}

	public function secondary_language( $document ): string {
		$lang = $document->get_setting( 'second_language' );
		return ! empty( $lang ) ? (string) $lang : 'ar';
	}

	public function is_rtl( $document ): bool {
		$override = $document->get_setting( 'second_language_rtl' );
		if ( null !== $override ) {
			return ! empty( $override );
		}
		return in_array( $this->secondary_language( $document ), $this->rtl_languages, true );
	}

	public function dictionary( string $language = 'ar' ): array {
		$file = __DIR__ . '/dictionary/' . $language . '.php';
		$dict = is_readable( $file ) ? (array) include $file : array();
		return apply_filters( 'woi_pdf_second_language_dictionary', $dict, $language );
	}

	public function secondary_label( string $key, $document ): string {
		$overrides = (array) ( $document->get_setting( 'second_language_labels' ) ?: array() );
		if ( isset( $overrides[ $key ] ) && '' !== trim( (string) $overrides[ $key ] ) ) {
			$value = trim( (string) $overrides[ $key ] );
		} else {
			$dict  = $this->dictionary( $this->secondary_language( $document ) );
			$value = isset( $dict[ $key ] ) ? (string) $dict[ $key ] : '';
		}
		return (string) apply_filters( 'woi_pdf_second_language_label', $value, $key, $document );
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualEngineTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/Bilingual/BilingualEngine.php includes/Bilingual/dictionary/ar.php tests/Unit/Bilingual/BilingualEngineTest.php
git commit -m "feat: bilingual engine core — enablement + label resolution"
```

---

### Task 2: Content-value resolvers (shop AR + localized location)

**Files:**
- Modify: `includes/Bilingual/BilingualEngine.php`
- Test: `tests/Unit/Bilingual/BilingualContentTest.php`

**Interfaces:**
- Produces:
  - `secondary_shop_name(): string` — from general setting `shop_name_ar`, else `''`
  - `secondary_shop_address(): string` — from general setting `shop_address_ar`, else `''`
  - `localized_location( string $value, string $type, $order ): string` — `$type` is `'country'` or `'state'`; returns the WooCommerce name in the AR locale, else `$value`
- Consumes: `get_option('woi_pdf_general_settings')` array; `$order->get_billing_country()`, `$order->get_billing_state()`; `WC()->countries`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Bilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Bilingual\BilingualEngine;

class BilingualContentTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_shop_name_ar_from_settings(): void {
		Functions\when( 'get_option' )->justReturn( array( 'shop_name_ar' => 'ميلانو' ) );
		$this->assertSame( 'ميلانو', BilingualEngine::instance()->secondary_shop_name() );
	}

	public function test_shop_name_ar_empty_when_unset(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( '', BilingualEngine::instance()->secondary_shop_name() );
	}

	public function test_localized_country_uses_wc_name(): void {
		Functions\when( 'switch_to_locale' )->justReturn( true );
		Functions\when( 'restore_previous_locale' )->justReturn( true );
		$countries = new class {
			public function get_countries() { return array( 'AE' => 'الإمارات العربية المتحدة' ); }
			public function get_states( $cc ) { return array(); }
		};
		Functions\when( 'WC' )->justReturn( new class( $countries ) {
			public $countries;
			public function __construct( $c ) { $this->countries = $c; }
		} );
		$order = new class {
			public function get_billing_country() { return 'AE'; }
			public function get_billing_state() { return ''; }
		};
		$this->assertSame(
			'الإمارات العربية المتحدة',
			BilingualEngine::instance()->localized_location( 'UAE', 'country', $order )
		);
	}

	public function test_localized_country_falls_back_when_missing(): void {
		Functions\when( 'switch_to_locale' )->justReturn( true );
		Functions\when( 'restore_previous_locale' )->justReturn( true );
		$countries = new class {
			public function get_countries() { return array(); }
			public function get_states( $cc ) { return array(); }
		};
		Functions\when( 'WC' )->justReturn( new class( $countries ) {
			public $countries;
			public function __construct( $c ) { $this->countries = $c; }
		} );
		$order = new class {
			public function get_billing_country() { return 'AE'; }
			public function get_billing_state() { return ''; }
		};
		$this->assertSame( 'UAE', BilingualEngine::instance()->localized_location( 'UAE', 'country', $order ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualContentTest`
Expected: FAIL — `secondary_shop_name()` not defined.

- [ ] **Step 3: Add methods to the engine**

Add to `BilingualEngine` (inside the class):

```php
	public function secondary_shop_name(): string {
		$general = (array) get_option( 'woi_pdf_general_settings' );
		return isset( $general['shop_name_ar'] ) ? trim( (string) $general['shop_name_ar'] ) : '';
	}

	public function secondary_shop_address(): string {
		$general = (array) get_option( 'woi_pdf_general_settings' );
		return isset( $general['shop_address_ar'] ) ? trim( (string) $general['shop_address_ar'] ) : '';
	}

	public function localized_location( string $value, string $type, $order ): string {
		$code = ( 'state' === $type ) ? $order->get_billing_state() : $order->get_billing_country();
		if ( empty( $code ) ) {
			return $value;
		}
		$switched = switch_to_locale( 'ar' );
		if ( 'state' === $type ) {
			$states = WC()->countries->get_states( $order->get_billing_country() );
			$name   = $states[ $code ] ?? '';
		} else {
			$countries = WC()->countries->get_countries();
			$name      = $countries[ $code ] ?? '';
		}
		if ( $switched ) {
			restore_previous_locale();
		}
		return '' !== $name ? $name : $value;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualContentTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Bilingual/BilingualEngine.php tests/Unit/Bilingual/BilingualContentTest.php
git commit -m "feat: bilingual content resolvers — shop AR + localized country/state"
```

---

### Task 3: Font assets + gated font CSS

**Files:**
- Create: `templates/Business/fonts/NotoNaskhArabic-Regular.ttf` (and `-Bold.ttf`)
- Create: same two files under `templates/Modern/fonts/`, `templates/Simple/fonts/`, `templates/Simple Premium/fonts/`
- Modify: `includes/Bilingual/BilingualEngine.php`
- Test: `tests/Unit/Bilingual/BilingualFontTest.php`

**Interfaces:**
- Produces:
  - `font_family(): string` — returns `'Noto Naskh Arabic'`
  - `font_css( $document ): string` — returns the `@font-face` + secondary-class CSS when enabled, else `''`

- [ ] **Step 1: Download the font files**

Run (from repo root; Noto Naskh Arabic is SIL OFL):

```bash
mkdir -p /tmp/notonaskh && cd /tmp/notonaskh
curl -L -o reg.ttf "https://github.com/googlefonts/noto-fonts/raw/main/hinted/ttf/NotoNaskhArabic/NotoNaskhArabic-Regular.ttf"
curl -L -o bold.ttf "https://github.com/googlefonts/noto-fonts/raw/main/hinted/ttf/NotoNaskhArabic/NotoNaskhArabic-Bold.ttf"
cd -
for t in "Business" "Modern" "Simple" "Simple Premium"; do
  cp /tmp/notonaskh/reg.ttf  "templates/$t/fonts/NotoNaskhArabic-Regular.ttf"
  cp /tmp/notonaskh/bold.ttf "templates/$t/fonts/NotoNaskhArabic-Bold.ttf"
done
ls -la templates/Business/fonts/
```

Expected: both `NotoNaskhArabic-*.ttf` present (each > 100KB) in every template `fonts/` dir. Verify they are valid TTF: `file templates/Business/fonts/NotoNaskhArabic-Regular.ttf` → "TrueType Font data".

- [ ] **Step 2: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Bilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Bilingual\BilingualEngine;

class BilingualFontTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
	private function doc( array $s ) {
		return new class( $s ) {
			private $s;
			public function __construct( $s ) { $this->s = $s; }
			public function get_setting( $k ) { return $this->s[ $k ] ?? null; }
		};
	}

	public function test_font_family_name(): void {
		$this->assertSame( 'Noto Naskh Arabic', BilingualEngine::instance()->font_family() );
	}

	public function test_font_css_empty_when_disabled(): void {
		$this->assertSame( '', BilingualEngine::instance()->font_css( $this->doc( array() ) ) );
	}

	public function test_font_css_present_when_enabled(): void {
		$css = BilingualEngine::instance()->font_css( $this->doc( array( 'enable_second_language' => 1 ) ) );
		$this->assertStringContainsString( '@font-face', $css );
		$this->assertStringContainsString( 'Noto Naskh Arabic', $css );
		$this->assertStringContainsString( '.woi-lbl-secondary', $css );
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualFontTest`
Expected: FAIL — `font_family()` not defined.

- [ ] **Step 4: Add methods to the engine**

Add to `BilingualEngine`:

```php
	public function font_family(): string {
		return 'Noto Naskh Arabic';
	}

	public function font_css( $document ): string {
		if ( ! $this->is_enabled( $document ) ) {
			return '';
		}
		// Dompdf resolves font-family names against its synced font dir by family
		// name; the bundled NotoNaskhArabic TTFs are copied there by FontSynchronizer.
		$family = $this->font_family();
		$dir    = $this->is_rtl( $document ) ? 'rtl' : 'ltr';
		$css    = "@font-face { font-family: '{$family}'; font-style: normal; font-weight: normal; src: url('NotoNaskhArabic-Regular.ttf'); }\n";
		$css   .= "@font-face { font-family: '{$family}'; font-style: normal; font-weight: bold; src: url('NotoNaskhArabic-Bold.ttf'); }\n";
		$css   .= ".woi-lbl-secondary { display: block; font-family: '{$family}'; direction: {$dir}; }\n";
		$css   .= ".woi-lbl-inline .woi-lbl-secondary { display: inline; }\n";
		$css   .= ".woi-bilingual-secondary { font-family: '{$family}'; direction: {$dir}; }\n";
		return $css;
	}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualFontTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add templates/*/fonts/NotoNaskhArabic-*.ttf "templates/Simple Premium/fonts" includes/Bilingual/BilingualEngine.php tests/Unit/Bilingual/BilingualFontTest.php
git commit -m "feat: bundle Noto Naskh Arabic + gated bilingual font CSS"
```

---

### Task 4: Wire the engine into the plugin + inject font CSS

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php` (autoload/include of `includes/Bilingual/BilingualEngine.php` if not autoloaded; bump `WOI_PDF_VERSION`)
- Modify: `includes/Main.php` (hook `font_css` onto `woi_pdf_custom_styles`)
- Test: `tests/Unit/Bilingual/BilingualStyleHookTest.php`

**Interfaces:**
- Consumes: `BilingualEngine::instance()->font_css( $document )`, the `woi_pdf_custom_styles` action (fires inside `template_custom_styles()` in the document head; receives the document).
- Produces: bilingual font CSS appears in the rendered `<style>` only when enabled.

- [ ] **Step 1: Confirm the include/autoload path**

Run: `grep -n "includes/Bilingual\|spl_autoload\|require.*includes" woocommerce-orders-invoice-pdf.php | head`
If the plugin uses a PSR-style autoloader keyed on `WOI\PDF\` → `includes/`, no include line is needed (namespace `WOI\PDF\Bilingual` maps to `includes/Bilingual/`). If it uses explicit `require_once`, add one for the new file near the other `includes/` requires.

- [ ] **Step 2: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Bilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Bilingual\BilingualEngine;

class BilingualStyleHookTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
	private function doc( array $s ) {
		return new class( $s ) {
			private $s;
			public function __construct( $s ) { $this->s = $s; }
			public function get_setting( $k ) { return $this->s[ $k ] ?? null; }
		};
	}

	public function test_callback_echoes_css_when_enabled(): void {
		ob_start();
		woi_pdf_print_bilingual_styles( 'invoice', $this->doc( array( 'enable_second_language' => 1 ) ) );
		$out = ob_get_clean();
		$this->assertStringContainsString( '@font-face', $out );
	}

	public function test_callback_silent_when_disabled(): void {
		ob_start();
		woi_pdf_print_bilingual_styles( 'invoice', $this->doc( array() ) );
		$out = ob_get_clean();
		$this->assertSame( '', $out );
	}
}
```

- [ ] **Step 3: Add the callback to `woi-pdf-functions.php`**

```php
if ( ! function_exists( 'woi_pdf_print_bilingual_styles' ) ) {
	/**
	 * Echo bilingual font/secondary CSS into the document head.
	 * Hooked on woi_pdf_custom_styles. No-op when the document is single-language.
	 *
	 * @param string $document_type
	 * @param object $document
	 */
	function woi_pdf_print_bilingual_styles( $document_type, $document = null ) {
		if ( ! $document ) {
			return;
		}
		echo \WOI\PDF\Bilingual\BilingualEngine::instance()->font_css( $document ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
```

- [ ] **Step 4: Hook it (in `Main.php`, where other `woi_pdf_custom_styles` / style hooks register)**

```php
add_action( 'woi_pdf_custom_styles', 'woi_pdf_print_bilingual_styles', 20, 2 );
```

Verify the action passes `( $document_type, $document )`. If it only passes the type, instead hook `woi_pdf_before_document` and resolve the document there; adjust the callback signature accordingly.

- [ ] **Step 5: Bump version**

In `woocommerce-orders-invoice-pdf.php`, increment `WOI_PDF_VERSION` (e.g. `1.3.4` → `1.4.0`).

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualStyleHookTest`
Expected: PASS (2 tests).
Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter ServiceWiringTest`
Expected: PASS (no regression).

- [ ] **Step 7: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php includes/Main.php woi-pdf-functions.php tests/Unit/Bilingual/BilingualStyleHookTest.php
git commit -m "feat: wire bilingual engine + inject gated font CSS into document head"
```

---

### Task 5: Label-pair rendering in OrderDocument (patterns 2 & 3)

**Files:**
- Modify: `includes/Documents/OrderDocument.php` (add `render_label()`; route print helpers through it)
- Test: `tests/Unit/Documents/RenderLabelTest.php`

**Interfaces:**
- Produces: `OrderDocument::render_label( string $slug ): void` — echoes
  `<span class="woi-lbl-primary">EN</span><span class="woi-lbl-secondary" dir="...">AR</span>` when bilingual is enabled and a secondary exists; otherwise echoes `esc_html( primary )`.
- Consumes: `BilingualEngine::instance()->is_enabled()`, `secondary_label()`, `is_rtl()`; existing `get_title_for( $slug )`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class RenderLabelTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Minimal stand-in exposing render_label with controllable deps. */
	private function doc( bool $enabled, string $secondary ) {
		return new class( $enabled, $secondary ) {
			use \WOI\PDF\Documents\BilingualLabelTrait; // see Step 3
			public $enabled; public $secondary;
			public function __construct( $e, $s ) { $this->enabled = $e; $this->secondary = $s; }
			public function get_title_for( string $slug ): string { return 'Invoice No'; }
			protected function bilingual_enabled(): bool { return $this->enabled; }
			protected function bilingual_secondary( string $slug ): string { return $this->secondary; }
			protected function bilingual_rtl(): bool { return true; }
		};
	}

	public function test_single_language_outputs_plain_text(): void {
		ob_start();
		$this->doc( false, 'رقم الفاتورة' )->render_label( 'document_number' );
		$this->assertSame( 'Invoice No', ob_get_clean() );
	}

	public function test_enabled_outputs_both_spans(): void {
		ob_start();
		$this->doc( true, 'رقم الفاتورة' )->render_label( 'document_number' );
		$out = ob_get_clean();
		$this->assertStringContainsString( '<span class="woi-lbl-primary">Invoice No</span>', $out );
		$this->assertStringContainsString( 'woi-lbl-secondary', $out );
		$this->assertStringContainsString( 'رقم الفاتورة', $out );
		$this->assertStringContainsString( 'dir="rtl"', $out );
	}

	public function test_enabled_but_no_secondary_outputs_plain(): void {
		ob_start();
		$this->doc( true, '' )->render_label( 'document_number' );
		$this->assertSame( 'Invoice No', ob_get_clean() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter RenderLabelTest`
Expected: FAIL — trait `BilingualLabelTrait` not found.

- [ ] **Step 3: Create the trait**

`includes/Documents/BilingualLabelTrait.php`:

```php
<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

trait BilingualLabelTrait {

	protected function bilingual_enabled(): bool {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->is_enabled( $this );
	}

	protected function bilingual_secondary( string $slug ): string {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->secondary_label( $slug, $this );
	}

	protected function bilingual_rtl(): bool {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->is_rtl( $this );
	}

	/**
	 * Echo a label, bilingual when enabled. $slug must match get_title_for().
	 */
	public function render_label( string $slug ): void {
		$primary = $this->get_title_for( $slug );
		if ( ! $this->bilingual_enabled() ) {
			echo esc_html( $primary );
			return;
		}
		$secondary = $this->bilingual_secondary( $slug );
		if ( '' === $secondary ) {
			echo esc_html( $primary );
			return;
		}
		$dir = $this->bilingual_rtl() ? 'rtl' : 'ltr';
		printf(
			'<span class="woi-lbl-primary">%s</span><span class="woi-lbl-secondary" dir="%s">%s</span>',
			esc_html( $primary ),
			esc_attr( $dir ),
			esc_html( $secondary )
		);
	}
}
```

- [ ] **Step 4: Use the trait in OrderDocument**

In `includes/Documents/OrderDocument.php`, add `use BilingualLabelTrait;` inside the class (near the top, with other `use` traits if any), and route the existing print helpers through `render_label()`. Replace the bodies of these methods:

```php
	public function title() { $this->render_label( 'document' ); }
	public function number_title() { $this->render_label( 'document_number' ); }
	public function date_title() { $this->render_label( 'document_date' ); }
	public function due_date_title() { $this->render_label( 'document_due_date' ); }
	public function billing_address_title(): void { $this->render_label( 'billing_address' ); }
	public function shipping_address_title(): void { $this->render_label( 'shipping_address' ); }
	public function order_number_title(): void { $this->render_label( 'order_number' ); }
	public function order_date_title(): void { $this->render_label( 'order_date' ); }
```

Note: `get_title_for()` slugs `document_due_date` / `document` already exist — keep the slug strings matching the `get_title_for` switch. Add an `include_once includes/Documents/BilingualLabelTrait.php` near OrderDocument's load if the autoloader doesn't cover traits.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter RenderLabelTest`
Expected: PASS (3 tests).
Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: full suite green (no regression from the print-method changes).

- [ ] **Step 6: Add CSS for the label classes (all 4 templates)**

In each of `templates/Business/style.css`, `templates/Modern/style.css`, `templates/Simple/style.css`, `templates/Simple Premium/style.css`, add near the RTL block:

```css
/* Bilingual label pairs */
.woi-lbl-primary { display: block; }
.woi-lbl-secondary { display: block; }
.woi-lbl-inline .woi-lbl-primary,
.woi-lbl-inline .woi-lbl-secondary { display: inline; }
.woi-lbl-inline .woi-lbl-secondary::before { content: "\\005C"; padding: 0 3px; } /* backslash separator */
```

- [ ] **Step 7: Commit**

```bash
git add includes/Documents/BilingualLabelTrait.php includes/Documents/OrderDocument.php templates/*/style.css "templates/Simple Premium/style.css" tests/Unit/Documents/RenderLabelTest.php
git commit -m "feat: bilingual label pairs via OrderDocument::render_label"
```

---

### Task 6: Secondary values for item-table headers & totals (filter hooks)

**Files:**
- Modify: `includes/Bilingual/BilingualEngine.php` (add filter callbacks + register)
- Modify: `templates/Business/invoice.php` and the other templates' header/totals loops (render `secondary`)
- Test: `tests/Unit/Bilingual/BilingualTableTest.php`

**Interfaces:**
- Produces:
  - `add_header_secondaries( array $headers, string $type, $document ): array` — adds `secondary` to each header keyed by its column `type`.
  - `add_totals_secondaries( array $totals, string $type, $document ): array` — adds `secondary` to each total keyed by its `type`.
  - Both registered on `woi_pdf_templates_table_headers` / `woi_pdf_templates_totals`, gated by `is_enabled`.
- Consumes: header rows have a `type` key (e.g. `sku`, `description`); totals rows have a `type` key (e.g. `subtotal`, `total`).

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Bilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Bilingual\BilingualEngine;

class BilingualTableTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
	private function doc( array $s ) {
		return new class( $s ) {
			private $s;
			public function __construct( $s ) { $this->s = $s; }
			public function get_setting( $k ) { return $this->s[ $k ] ?? null; }
		};
	}

	public function test_headers_get_secondary_when_enabled(): void {
		$headers = array( 'c1' => array( 'type' => 'description', 'title' => 'Description of Goods' ) );
		$out = BilingualEngine::instance()->add_header_secondaries( $headers, 'invoice', $this->doc( array( 'enable_second_language' => 1 ) ) );
		$this->assertSame( 'البيان الصنف', $out['c1']['secondary'] );
	}

	public function test_headers_untouched_when_disabled(): void {
		$headers = array( 'c1' => array( 'type' => 'description', 'title' => 'Description of Goods' ) );
		$out = BilingualEngine::instance()->add_header_secondaries( $headers, 'invoice', $this->doc( array() ) );
		$this->assertArrayNotHasKey( 'secondary', $out['c1'] );
	}

	public function test_totals_get_secondary_when_enabled(): void {
		$totals = array( 't1' => array( 'type' => 'total', 'label' => 'Total', 'value' => 'AED 5,197.50' ) );
		$out = BilingualEngine::instance()->add_totals_secondaries( $totals, 'invoice', $this->doc( array( 'enable_second_language' => 1 ) ) );
		$this->assertSame( 'المجموع', $out['t1']['secondary'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualTableTest`
Expected: FAIL — `add_header_secondaries()` not defined.

- [ ] **Step 3: Add the callbacks to the engine**

```php
	public function add_header_secondaries( array $headers, string $type, $document ): array {
		if ( ! $this->is_enabled( $document ) ) {
			return $headers;
		}
		foreach ( $headers as $key => $row ) {
			$col_type = $row['type'] ?? '';
			$secondary = '' !== $col_type ? $this->secondary_label( $col_type, $document ) : '';
			if ( '' !== $secondary ) {
				$headers[ $key ]['secondary'] = $secondary;
			}
		}
		return $headers;
	}

	public function add_totals_secondaries( array $totals, string $type, $document ): array {
		if ( ! $this->is_enabled( $document ) ) {
			return $totals;
		}
		foreach ( $totals as $key => $row ) {
			$total_type = $row['type'] ?? '';
			$secondary = '' !== $total_type ? $this->secondary_label( $total_type, $document ) : '';
			if ( '' !== $secondary ) {
				$totals[ $key ]['secondary'] = $secondary;
			}
		}
		return $totals;
	}
```

- [ ] **Step 4: Register the callbacks**

In `woi-pdf-functions.php` (near where other `woi_pdf_templates_*` filters are added) or in `Main.php` init, add:

```php
add_filter( 'woi_pdf_templates_table_headers', array( \WOI\PDF\Bilingual\BilingualEngine::instance(), 'add_header_secondaries' ), 20, 3 );
add_filter( 'woi_pdf_templates_totals', array( \WOI\PDF\Bilingual\BilingualEngine::instance(), 'add_totals_secondaries' ), 20, 3 );
```

- [ ] **Step 5: Render `secondary` in the template loops (all 4 templates)**

In each template `invoice.php` (and `proforma.php`, `credit-note.php`, `receipt.php`, `packing-slip.php`, `delivery-note.php` where the same loops exist), update the header loop:

```php
<th class="<?php echo esc_attr( $header_data['class'] ); ?>"<?php echo woi_pdf_templates_maybe_apply_column_styles( $header_data, 'header' ); ?>><?php
	echo esc_html( $header_data['title'] );
	if ( ! empty( $header_data['secondary'] ) ) {
		echo '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $header_data['secondary'] ) . '</span>';
	}
?></th>
```

and the totals loop:

```php
<th class="description"><span><?php
	echo esc_html( $total_data['label'] );
	if ( ! empty( $total_data['secondary'] ) ) {
		echo '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $total_data['secondary'] ) . '</span>';
	}
?></span></th>
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualTableTest`
Expected: PASS (3 tests).
Run: full suite — `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php` → green.

- [ ] **Step 7: Commit**

```bash
git add includes/Bilingual/BilingualEngine.php woi-pdf-functions.php includes/Main.php templates/ tests/Unit/Bilingual/BilingualTableTest.php
git commit -m "feat: bilingual secondary values for item-table headers and totals"
```

---

### Task 7: Mirror blocks — shop + addresses

**Files:**
- Modify: `includes/Documents/BilingualLabelTrait.php` (add `bilingual_shop_block()`, `bilingual_address_block()`)
- Modify: `templates/Business/invoice.php` (and the other 3 templates' invoice/proforma/credit-note/receipt) header + addresses regions
- Test: `tests/Unit/Documents/MirrorBlockTest.php`

**Interfaces:**
- Produces:
  - `bilingual_shop_block(): void` — when enabled, echoes a two-column table row (primary shop block left, secondary shop block right with `woi-bilingual-secondary` + `dir`); when disabled, calls the existing single-language shop markup.
  - `bilingual_address_block( string $type ): void` — `$type` is `'billing'` or `'shipping'`; mirror of the address with localized country/state on the secondary side.
- Consumes: existing `$this->shop_name()`, `$this->shop_address()`, `$this->billing_address()`, `$this->shipping_address()`; `BilingualEngine` content resolvers.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class MirrorBlockTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function doc( bool $enabled ) {
		return new class( $enabled ) {
			use \WOI\PDF\Documents\BilingualLabelTrait;
			public $enabled;
			public function __construct( $e ) { $this->enabled = $e; }
			protected function bilingual_enabled(): bool { return $this->enabled; }
			protected function bilingual_rtl(): bool { return true; }
			protected function secondary_shop_name(): string { return 'ميلانو'; }
			protected function secondary_shop_address(): string { return 'دبي'; }
			// existing single-language emitters (stubbed)
			public function shop_name() { echo 'MILANO'; }
			public function shop_address() { echo 'Dubai'; }
		};
	}

	public function test_disabled_emits_single_block_only(): void {
		ob_start();
		$this->doc( false )->bilingual_shop_block();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'MILANO', $out );
		$this->assertStringNotContainsString( 'ميلانو', $out );
	}

	public function test_enabled_emits_both_sides(): void {
		ob_start();
		$this->doc( true )->bilingual_shop_block();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'MILANO', $out );
		$this->assertStringContainsString( 'ميلانو', $out );
		$this->assertStringContainsString( 'woi-bilingual-secondary', $out );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter MirrorBlockTest`
Expected: FAIL — `bilingual_shop_block()` not defined.

- [ ] **Step 3: Add the helpers to the trait**

Add to `BilingualLabelTrait` (and add a `secondary_shop_name()`/`secondary_shop_address()` bridge so the trait can be tested in isolation but defaults to the engine):

```php
	protected function secondary_shop_name(): string {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->secondary_shop_name();
	}
	protected function secondary_shop_address(): string {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->secondary_shop_address();
	}

	public function bilingual_shop_block(): void {
		if ( ! $this->bilingual_enabled() ) {
			echo '<div class="shop-name"><h3>'; $this->shop_name(); echo '</h3></div>';
			$this->shop_address();
			return;
		}
		$dir       = $this->bilingual_rtl() ? 'rtl' : 'ltr';
		$sec_name  = $this->secondary_shop_name();
		$sec_addr  = $this->secondary_shop_address();
		echo '<table class="bilingual-shop"><tr>';
		echo '<td class="bilingual-primary">';
		echo '<div class="shop-name"><h3>'; $this->shop_name(); echo '</h3></div>';
		$this->shop_address();
		echo '</td>';
		echo '<td class="bilingual-secondary woi-bilingual-secondary" dir="' . esc_attr( $dir ) . '">';
		if ( '' !== $sec_name ) {
			echo '<div class="shop-name"><h3>' . esc_html( $sec_name ) . '</h3></div>';
		} else {
			echo '<div class="shop-name"><h3>'; $this->shop_name(); echo '</h3></div>';
		}
		if ( '' !== $sec_addr ) {
			echo '<div class="shop-address">' . nl2br( esc_html( $sec_addr ) ) . '</div>';
		} else {
			$this->shop_address();
		}
		echo '</td></tr></table>';
	}

	public function bilingual_address_block( string $type ): void {
		$emit = ( 'shipping' === $type ) ? 'shipping_address' : 'billing_address';
		if ( ! $this->bilingual_enabled() ) {
			echo '<p>'; $this->$emit(); echo '</p>';
			return;
		}
		$dir = $this->bilingual_rtl() ? 'rtl' : 'ltr';
		echo '<table class="bilingual-address"><tr>';
		echo '<td class="bilingual-primary"><p>'; $this->$emit(); echo '</p></td>';
		echo '<td class="bilingual-secondary woi-bilingual-secondary" dir="' . esc_attr( $dir ) . '"><p>';
		$this->$emit(); // same Latin content; labels around it come from render_label
		echo '</p></td></tr></table>';
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter MirrorBlockTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Call the helpers from the templates**

In `templates/Business/invoice.php`, replace the shop-address `<div class="shop-address">…</div>` inner block (lines ~13-29) with a call to `$this->bilingual_shop_block();` (keep the surrounding hooks `woi_pdf_before_shop_name` etc.), and replace the billing `<p><?php $this->billing_address(); ?></p>` (line ~59) with `<?php $this->bilingual_address_block( 'billing' ); ?>` and the shipping `<p><?php $this->shipping_address(); ?></p>` (line ~72) with `<?php $this->bilingual_address_block( 'shipping' ); ?>`. Repeat in `templates/Modern/invoice.php`, `templates/Simple/invoice.php`, `templates/Simple Premium/invoice.php`, and each template's `proforma.php`, `credit-note.php`, `receipt.php`.

- [ ] **Step 6: Add mirror CSS (all 4 templates' style.css)**

```css
/* Bilingual mirror blocks */
.bilingual-shop, .bilingual-address { width: 100%; border-collapse: collapse; }
.bilingual-shop td, .bilingual-address td { vertical-align: top; width: 50%; }
.bilingual-secondary { text-align: right; }
.woi-bilingual-secondary { font-family: 'Noto Naskh Arabic'; }
```

- [ ] **Step 7: Run full suite + commit**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: green.

```bash
git add includes/Documents/BilingualLabelTrait.php templates/ tests/Unit/Documents/MirrorBlockTest.php
git commit -m "feat: bilingual mirror blocks for shop + addresses"
```

---

### Task 8: Customiser "Second language" settings section

**Files:**
- Modify: the Customiser settings registration (find with `grep -rn "document_title" includes/Editor*.php includes/Settings*.php` — register the new fields next to `document_title`)
- Modify: general settings registration (add `shop_name_ar`, `shop_address_ar` near `vat_number`)
- Test: `tests/Unit/Settings/BilingualSettingsTest.php` (assert the fields are present in the settings definition array)

**Interfaces:**
- Produces settings keys, all read by `BilingualEngine`: `enable_second_language` (checkbox), `second_language` (select, default `ar`), `second_language_rtl` (checkbox), `second_language_labels` (repeatable text map keyed by dictionary key), `shop_name_ar` (text), `shop_address_ar` (textarea).
- Consumes: existing `show_if` conditional-field machinery (`Settings::show_if_class`).

- [ ] **Step 1: Locate the document settings definition**

Run: `grep -rn "document_title\|'enable_'\|show_if" includes/Editor*.php includes/Settings*.php | head -30`
Identify the array where per-document fields (like `document_title`) are declared. New fields are added to that array.

- [ ] **Step 2: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Guards that the bilingual settings fields are declared. Reads the raw
 * settings-definition method output; adjust the accessor to match the
 * located registration point from Step 1.
 */
class BilingualSettingsTest extends TestCase {
	public function test_second_language_fields_declared(): void {
		$fields = woi_pdf_test_get_invoice_setting_ids(); // helper added in Step 3
		$this->assertContains( 'enable_second_language', $fields );
		$this->assertContains( 'second_language', $fields );
		$this->assertContains( 'second_language_labels', $fields );
	}
}
```

- [ ] **Step 3: Add a test accessor + the fields**

Add a small accessor near the settings definition (only if no public accessor exists) that returns the declared field IDs for the invoice document, e.g. `woi_pdf_test_get_invoice_setting_ids()` in `woi-pdf-functions.php` returning the `id` of each registered field. Then declare the fields in the located array. Example field declarations (match the existing array's shape — `type`, `id`, `title`, `callback`, `args`):

```php
array(
	'type'  => 'setting',
	'id'    => 'enable_second_language',
	'title' => __( 'Enable second language', 'woocommerce-orders-invoice-pdf' ),
	'callback' => 'checkbox',
	'args'  => array( 'option_name' => $option_name, 'id' => 'enable_second_language' ),
),
array(
	'type'  => 'setting',
	'id'    => 'second_language',
	'title' => __( 'Second language', 'woocommerce-orders-invoice-pdf' ),
	'callback' => 'select',
	'args'  => array(
		'option_name' => $option_name,
		'id'          => 'second_language',
		'default'     => 'ar',
		'options'     => array( 'ar' => __( 'Arabic', 'woocommerce-orders-invoice-pdf' ) ),
	),
	'class' => $this->show_if_class( 'enable_second_language' ),
),
array(
	'type'  => 'setting',
	'id'    => 'second_language_rtl',
	'title' => __( 'Right-to-left', 'woocommerce-orders-invoice-pdf' ),
	'callback' => 'checkbox',
	'args'  => array( 'option_name' => $option_name, 'id' => 'second_language_rtl', 'default' => 1 ),
	'class' => $this->show_if_class( 'enable_second_language' ),
),
array(
	'type'  => 'setting',
	'id'    => 'second_language_labels',
	'title' => __( 'Label translations', 'woocommerce-orders-invoice-pdf' ),
	'callback' => 'woi_pdf_second_language_labels_table', // renders the editable key→AR table
	'args'  => array( 'option_name' => $option_name, 'id' => 'second_language_labels' ),
	'class' => $this->show_if_class( 'enable_second_language' ),
),
```

Implement `woi_pdf_second_language_labels_table()` to render a row per dictionary key (`BilingualEngine::instance()->dictionary()`), each with the primary label text (read-only) and an `<input name="{option}[second_language_labels][{key}]">` pre-filled from the saved value or seeded from the dictionary. Add `shop_name_ar` (text) and `shop_address_ar` (textarea) to the general-settings array near `vat_number`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter BilingualSettingsTest`
Expected: PASS.

- [ ] **Step 5: Manual check**

Load Customiser → invoice → Document details. Toggle "Enable second language" → the language/RTL/label-table fields appear (show_if). Save; reload; values persist. Enter a shop AR address in General; confirm it saves.

- [ ] **Step 6: Bump version + commit**

```bash
# bump WOI_PDF_VERSION (CSS/markup of settings changed)
git add includes/ woi-pdf-functions.php woocommerce-orders-invoice-pdf.php tests/Unit/Settings/BilingualSettingsTest.php
git commit -m "feat: Customiser Second-language settings section + shop AR fields"
```

---

### Task 9: "Standard UAE Tax Invoice" preset template

**Files:**
- Create: `templates/Standard UAE Tax Invoice/` — copy the Business template's `invoice.php`, `proforma.php`, `credit-note.php`, `receipt.php`, `packing-slip.php`, `html-document-wrapper.php`, `style.css`, `template-functions.php`, and `fonts/` (including the Noto Naskh TTFs from Task 3)
- Modify: the new `template-functions.php` (preset defaults that enable the engine)
- Test: `tests/Unit/TemplateLoaderTest.php` (assert the new template is discovered)

**Interfaces:**
- Consumes: existing template discovery (`Settings::get_installed_templates()` scans `templates/`), the `woi_pdf_template_editor_defaults` filter mechanism.
- Produces: a selectable template whose defaults set `enable_second_language=1`, `second_language='ar'`, `second_language_rtl=1`, `document_title='Tax Invoice'`, and seed `second_language_labels` from the AR dictionary (incl. `document => 'فاتورة ضريبية'`).

- [ ] **Step 1: Scaffold the template directory**

```bash
cp -r "templates/Business" "templates/Standard UAE Tax Invoice"
ls "templates/Standard UAE Tax Invoice"
```

Confirm the Noto Naskh TTFs are present under its `fonts/` (they were added to Business in Task 3, so the copy includes them).

- [ ] **Step 2: Write the failing test**

Add to `tests/Unit/TemplateLoaderTest.php` (or a new method):

```php
	public function test_standard_uae_template_is_discovered(): void {
		$dir = dirname( __DIR__, 2 ) . '/templates/Standard UAE Tax Invoice';
		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . '/invoice.php' );
		$this->assertFileExists( $dir . '/template-functions.php' );
		$this->assertFileExists( $dir . '/fonts/NotoNaskhArabic-Regular.ttf' );
	}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter test_standard_uae_template_is_discovered`
Expected: FAIL if the dir/files are missing; PASS once Step 1 copied them. (If it already passes after the copy, that is the GREEN — proceed.)

- [ ] **Step 4: Set preset defaults**

In `templates/Standard UAE Tax Invoice/template-functions.php`, rename the defaults function (e.g. `woi_pdf_standard_uae_template_defaults`) and add bilingual defaults to the non-packing-slip branch's settings. After the existing `columns`/`totals` cases, add a `default` seed for the document settings:

```php
add_filter( 'woi_pdf_template_editor_defaults', 'woi_pdf_standard_uae_template_defaults', 9, 3 );
add_filter( 'woi_pdf_template_editor_settings', 'woi_pdf_standard_uae_template_defaults', 9, 3 );
function woi_pdf_standard_uae_template_defaults( $settings, $document_type, $settings_name ) {
	$editor_settings = get_option( 'woi_pdf_editor_settings' );
	if ( isset( $editor_settings['settings_saved'] ) && ! isset( $_GET['load-defaults'] ) ) {
		return $settings;
	}
	// Seed bilingual engine ON for this preset.
	$dict = \WOI\PDF\Bilingual\BilingualEngine::instance()->dictionary( 'ar' );
	switch ( $settings_name ) {
		case 'enable_second_language':
			$settings = 1; break;
		case 'second_language':
			$settings = 'ar'; break;
		case 'second_language_rtl':
			$settings = 1; break;
		case 'document_title':
			$settings = __( 'Tax Invoice', 'woocommerce-orders-invoice-pdf' ); break;
		case 'second_language_labels':
			$settings = $dict; break;
	}
	return $settings;
}
```

(Keep the existing `columns`/`totals` cases from the Business copy; the UAE-specific column set is sub-project B.)

- [ ] **Step 5: Style the preset for the boxed look**

In `templates/Standard UAE Tax Invoice/style.css`, add bordered tabular styling approximating the reference:

```css
table.order-details { border-collapse: collapse; width: 100%; }
table.order-details th, table.order-details td { border: 0.5pt solid #000; padding: 2px 4px; }
.document-type-label { text-align: center; }
```

- [ ] **Step 6: Run tests + manual check**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: green.
Manual: Settings → template picker now lists "Standard UAE Tax Invoice"; selecting it + previewing an invoice shows bilingual labels, mirror blocks, Arabic font, and boxed table.

- [ ] **Step 7: Commit**

```bash
git add "templates/Standard UAE Tax Invoice" tests/Unit/TemplateLoaderTest.php
git commit -m "feat: Standard UAE Tax Invoice preset template (bilingual scaffold)"
```

---

### Task 10: End-to-end verification + docs

**Files:**
- Modify: `README.md` (document the second-language engine + preset)
- No new code.

- [ ] **Step 1: Full suite**

Run: `vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: all green, including `ServiceWiringTest`.

- [ ] **Step 2: Disabled-path regression (manual)**

With a normal template (Business) and bilingual OFF: generate an invoice PDF and confirm it is unchanged vs. before this branch (no Arabic, no extra spans, no `@font-face`). Diff the HTML if needed by enabling the preview.

- [ ] **Step 3: Enabled-path (manual)**

Select "Standard UAE Tax Invoice", generate a PDF for a UAE order. Confirm: title stacked (`Tax Invoice` / `فاتورة ضريبية`), shop + buyer mirror blocks, stacked column headers, inline `Invoice No\…` label, Arabic glyphs render (font embedded), country/state localized where available.

- [ ] **Step 4: Customiser preview parity (manual)**

Confirm the Customiser preview matches the generated PDF for the preset (same bilingual rendering). If they differ, the editor preview path bypasses the template helpers — note and fix the preview render path.

- [ ] **Step 5: Update README + commit**

Add a short "Second language (bilingual documents)" section to `README.md` describing the toggle, the editable translations, the AR preset, and the Standard UAE Tax Invoice template.

```bash
git add README.md
git commit -m "docs: document bilingual second-language engine + UAE preset"
```

---

## Self-Review

**Spec coverage:**
- Engine service (enable/lang/rtl/dictionary/resolution) → Task 1 ✓
- User-editable translations w/ preset-seeded fallback → Task 1 (resolution) + Task 8 (UI) ✓
- Content resolvers (shop AR, WC_Countries) → Task 2 ✓
- Noto Naskh font bundling + gated CSS → Tasks 3, 4 ✓
- Label pairs patterns 2 & 3 (chokepoint + builders) → Tasks 5, 6 ✓
- Mirror blocks pattern 1 → Task 7 ✓
- Settings/Customiser section → Task 8 ✓
- Standard UAE preset template (scaffold) → Task 9 ✓
- Zero-impact-when-disabled + fallbacks → asserted in Tasks 1,3,5,6,7,10 ✓
- Testing (unit + manual) → throughout + Task 10 ✓

**Placeholder scan:** Tasks 8 and 9 contain locate-then-edit steps (settings array shape, defaults wiring) because the exact registration array shape must be read from the codebase first; each gives the concrete field declarations and exact grep to find the insertion point — no behavioral TBDs.

**Type consistency:** `BilingualEngine` method names (`is_enabled`, `secondary_language`, `is_rtl`, `dictionary`, `secondary_label`, `secondary_shop_name`, `secondary_shop_address`, `localized_location`, `font_family`, `font_css`, `add_header_secondaries`, `add_totals_secondaries`) are used consistently across Tasks 1-9. Trait methods (`render_label`, `bilingual_shop_block`, `bilingual_address_block`, `bilingual_enabled`, `bilingual_secondary`, `bilingual_rtl`) consistent across Tasks 5, 7. Settings keys (`enable_second_language`, `second_language`, `second_language_rtl`, `second_language_labels`, `shop_name_ar`, `shop_address_ar`) consistent across Tasks 1, 2, 8, 9.
