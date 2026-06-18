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

	// ---- slot-method tests ----

	public function test_disabled_name_slot_emits_name_div(): void {
		ob_start();
		$this->doc( false )->bilingual_shop_name_slot();
		$out = ob_get_clean();
		$this->assertStringContainsString( '<div class="shop-name"><h3>', $out );
		$this->assertStringContainsString( 'MILANO', $out );
		// address must NOT appear here in disabled mode
		$this->assertStringNotContainsString( 'Dubai', $out );
	}

	public function test_disabled_address_slot_emits_address(): void {
		ob_start();
		$this->doc( false )->bilingual_shop_address_slot();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'Dubai', $out );
		// no wrapper when $wrap = false
		$this->assertStringNotContainsString( '<div class="shop-address">', $out );
	}

	public function test_disabled_address_slot_wraps_when_requested(): void {
		ob_start();
		$this->doc( false )->bilingual_shop_address_slot( true );
		$out = ob_get_clean();
		$this->assertStringContainsString( '<div class="shop-address">', $out );
		$this->assertStringContainsString( 'Dubai', $out );
	}

	public function test_enabled_name_slot_emits_mirror_table(): void {
		ob_start();
		$this->doc( true )->bilingual_shop_name_slot();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'bilingual-shop', $out );
		$this->assertStringContainsString( 'MILANO', $out );
		$this->assertStringContainsString( 'ميلانو', $out );
		// address also in mirror
		$this->assertStringContainsString( 'Dubai', $out );
	}

	public function test_enabled_address_slot_is_noop(): void {
		ob_start();
		$this->doc( true )->bilingual_shop_address_slot();
		$out = ob_get_clean();
		$this->assertSame( '', $out );
	}

	// ---- three-column header helpers (English | logo | Arabic) ----

	/** Stub with configurable secondary shop name/address. */
	private function doc_sec( bool $enabled, string $name, string $addr ) {
		return new class( $enabled, $name, $addr ) {
			use \WOI\PDF\Documents\BilingualLabelTrait;
			public $enabled; public $sname; public $saddr;
			public function __construct( $e, $n, $a ) { $this->enabled = $e; $this->sname = $n; $this->saddr = $a; }
			protected function bilingual_enabled(): bool { return $this->enabled; }
			protected function bilingual_rtl(): bool { return true; }
			protected function secondary_shop_name(): string { return $this->sname; }
			protected function secondary_shop_address(): string { return $this->saddr; }
			public function shop_name() { echo 'MILANO'; }
			public function shop_address() { echo 'Dubai'; }
		};
	}

	public function test_has_secondary_shop_true_when_translation_present(): void {
		$this->assertTrue( $this->doc_sec( true, 'ميلانو', '' )->has_secondary_shop() );
		$this->assertTrue( $this->doc_sec( true, '', 'دبي' )->has_secondary_shop() );
	}

	public function test_has_secondary_shop_false_when_disabled_or_empty(): void {
		$this->assertFalse( $this->doc_sec( false, 'ميلانو', 'دبي' )->has_secondary_shop() );
		$this->assertFalse( $this->doc_sec( true, '', '' )->has_secondary_shop() );
	}

	public function test_shop_block_primary_emits_only_latin(): void {
		ob_start();
		$this->doc_sec( true, 'ميلانو', 'دبي' )->shop_block_primary();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'MILANO', $out );
		$this->assertStringContainsString( 'Dubai', $out );
		$this->assertStringNotContainsString( 'ميلانو', $out );
	}

	public function test_shop_block_secondary_uses_translation_then_falls_back(): void {
		ob_start();
		$this->doc_sec( true, 'ميلانو', '' )->shop_block_secondary();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'ميلانو', $out ); // translated name
		$this->assertStringContainsString( 'Dubai', $out );   // address falls back to Latin
		$this->assertStringNotContainsString( 'MILANO', $out ); // Latin name not used
	}
}
