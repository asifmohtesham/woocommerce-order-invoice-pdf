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

	public function test_uom_has_arabic_dictionary_default(): void {
		$doc = $this->doc( array() );
		$this->assertSame( 'الوحدة', BilingualEngine::instance()->secondary_label( 'uom', $doc ) );
	}

	public function test_user_override_wins_over_dictionary(): void {
		$doc = $this->doc( array( 'second_language_labels' => array( 'document_number' => 'CUSTOM AR' ) ) );
		$this->assertSame( 'CUSTOM AR', BilingualEngine::instance()->secondary_label( 'document_number', $doc ) );
	}

	public function test_unknown_label_returns_empty(): void {
		$this->assertSame( '', BilingualEngine::instance()->secondary_label( 'nope', $this->doc( array() ) ) );
	}

	public function test_primary_labels_returns_non_empty_map(): void {
		$labels = BilingualEngine::instance()->primary_labels();
		$this->assertNotEmpty( $labels );
		$this->assertArrayHasKey( 'document', $labels );
		$this->assertArrayHasKey( 'document_number', $labels );
		$this->assertArrayHasKey( 'total', $labels );
	}

	public function test_primary_labels_covers_all_ar_dictionary_keys(): void {
		$engine = BilingualEngine::instance();
		$dict   = $engine->dictionary( 'ar' );
		$labels = $engine->primary_labels();
		foreach ( array_keys( $dict ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$labels,
				"primary_labels() is missing key '{$key}' that exists in ar dictionary"
			);
		}
	}

	public function test_dictionary_rejects_invalid_lang_code(): void {
		$engine = BilingualEngine::instance();
		$this->assertSame( array(), $engine->dictionary( '../etc/passwd' ) );
		$this->assertSame( array(), $engine->dictionary( 'ar/../secret' ) );
		$this->assertSame( array(), $engine->dictionary( '' ) );
		$this->assertSame( array(), $engine->dictionary( 'AR' ) ); // uppercase rejected
	}

	public function test_dictionary_returns_array_for_valid_ar(): void {
		$engine = BilingualEngine::instance();
		$dict = $engine->dictionary( 'ar' );
		$this->assertArrayHasKey( 'total', $dict );
	}

	public function test_secondary_key_uses_type_for_standard_columns(): void {
		$engine = BilingualEngine::instance();
		$this->assertSame( 'description', $engine->secondary_key( array( 'type' => 'description' ) ) );
		$this->assertSame( 'price', $engine->secondary_key( array( 'type' => 'price', 'price_type' => 'total' ) ) );
		$this->assertSame( '', $engine->secondary_key( array() ) );
	}

	public function test_secondary_key_uses_field_for_custom_columns(): void {
		$engine = BilingualEngine::instance();
		// Custom-field columns key by the field they show, not the generic type —
		// so a "Barcode" (product_custom → global_unique_id) gets its own key.
		$this->assertSame( 'global_unique_id', $engine->secondary_key( array( 'type' => 'product_custom', 'field_name' => 'global_unique_id' ) ) );
		$this->assertSame( 'po_number', $engine->secondary_key( array( 'type' => 'item_meta', 'field_name' => 'po_number' ) ) );
		$this->assertSame( 'colour', $engine->secondary_key( array( 'type' => 'product_attribute', 'attribute_name' => 'colour' ) ) );
		// Falls back to the type when the field key is missing.
		$this->assertSame( 'product_custom', $engine->secondary_key( array( 'type' => 'product_custom' ) ) );
	}

	public function test_header_secondary_prefers_per_column_label_ar(): void {
		$engine = BilingualEngine::instance();
		$doc    = $this->doc( array( 'enable_second_language' => 1 ) );
		$headers = array(
			'c1' => array( 'type' => 'description' ),                                  // dictionary
			'c2' => array( 'type' => 'product_custom', 'field_name' => 'gtin', 'label_ar' => 'الباركود' ), // per-column override
		);
		$out = $engine->add_header_secondaries( $headers, 'invoice', $doc );
		$this->assertSame( 'البيان الصنف', $out['c1']['secondary'] ); // from ar dictionary
		$this->assertSame( 'الباركود', $out['c2']['secondary'] );      // per-column wins
	}

	public function test_header_secondary_for_custom_field_uses_global_override(): void {
		$engine = BilingualEngine::instance();
		// No per-column label_ar: a custom column resolves via its FIELD key against
		// the global overrides (so one global entry covers every such column).
		$doc = $this->doc( array(
			'enable_second_language' => 1,
			'second_language_labels' => array( 'gtin' => 'الرقم العالمي' ),
		) );
		$headers = array( 'c' => array( 'type' => 'product_custom', 'field_name' => 'gtin' ) );
		$out = $engine->add_header_secondaries( $headers, 'invoice', $doc );
		$this->assertSame( 'الرقم العالمي', $out['c']['secondary'] );
	}

	public function test_totals_secondary_prefers_per_row_label_ar(): void {
		$engine = BilingualEngine::instance();
		$doc    = $this->doc( array( 'enable_second_language' => 1 ) );
		$totals = array(
			't1' => array( 'type' => 'subtotal' ),                          // dictionary
			't2' => array( 'type' => 'total', 'label_ar' => 'الإجمالي النهائي' ), // per-row override
		);
		$out = $engine->add_totals_secondaries( $totals, 'invoice', $doc );
		$this->assertSame( 'المجموع الفرعي', $out['t1']['secondary'] );
		$this->assertSame( 'الإجمالي النهائي', $out['t2']['secondary'] );
	}
}
