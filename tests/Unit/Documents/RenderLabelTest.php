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
