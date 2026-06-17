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
}
