<?php
namespace WOI\PDF\Tests\Unit\Makers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Makers\PreviewWatermark;

class PreviewWatermarkTest extends TestCase {

	// Bridges Brain Monkey / Mockery expectations into PHPUnit so that
	// Functions\expect(...) is verified and counted (no "risky" no-assertion tests).
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Default: apply_filters returns the passed default value (arg #2).
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fake Dompdf canvas that records page_text() calls.
	 */
	private function fakeCanvas() {
		return new class {
			public array $texts = array();
			public function get_width() { return 600.0; }
			public function get_height() { return 800.0; }
			public function page_text( $x, $y, $text, $font, $size, $color = array(), $ws = 0.0, $cs = 0.0, $angle = 0.0 ) {
				$this->texts[] = $text;
			}
		};
	}

	private function fakeFonts() {
		return new class {
			public function getFont( $family, $subtype = 'normal' ) { return 'font-handle'; }
			public function getTextWidth( $text, $font, $size, $ws = 0.0, $cs = 0.0 ) { return 200.0; }
		};
	}

	private function fakeDompdf( $canvas, $fonts ) {
		return new class( $canvas, $fonts ) {
			private $canvas;
			private $fonts;
			public function __construct( $c, $f ) { $this->canvas = $c; $this->fonts = $f; }
			public function getCanvas() { return $this->canvas; }
			public function getFontMetrics() { return $this->fonts; }
		};
	}

	public function test_is_enabled_defaults_true(): void {
		$this->assertTrue( PreviewWatermark::is_enabled() );
	}

	public function test_is_enabled_respects_filter(): void {
		Functions\when( 'apply_filters' )->alias( function( $hook, $value ) {
			return ( 'woi_pdf_preview_watermark_enabled' === $hook ) ? false : $value;
		} );
		$this->assertFalse( PreviewWatermark::is_enabled() );
	}

	public function test_get_text_defaults_to_sample(): void {
		$this->assertSame( 'SAMPLE', PreviewWatermark::get_text() );
	}

	public function test_get_text_respects_filter(): void {
		Functions\when( 'apply_filters' )->alias( function( $hook, $value ) {
			return ( 'woi_pdf_preview_watermark_text' === $hook ) ? 'DRAFT' : $value;
		} );
		$this->assertSame( 'DRAFT', PreviewWatermark::get_text() );
	}

	public function test_stamp_returns_same_dompdf(): void {
		$dompdf = $this->fakeDompdf( $this->fakeCanvas(), $this->fakeFonts() );
		$this->assertSame( $dompdf, PreviewWatermark::stamp_after_render( $dompdf ) );
	}

	public function test_stamp_draws_text_when_enabled(): void {
		$canvas = $this->fakeCanvas();
		$dompdf = $this->fakeDompdf( $canvas, $this->fakeFonts() );
		PreviewWatermark::stamp_after_render( $dompdf );
		$this->assertSame( array( 'SAMPLE' ), $canvas->texts );
	}

	public function test_stamp_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias( function( $hook, $value ) {
			return ( 'woi_pdf_preview_watermark_enabled' === $hook ) ? false : $value;
		} );
		$canvas = $this->fakeCanvas();
		$dompdf = $this->fakeDompdf( $canvas, $this->fakeFonts() );
		PreviewWatermark::stamp_after_render( $dompdf );
		$this->assertSame( array(), $canvas->texts );
	}

	public function test_register_adds_filter(): void {
		Functions\expect( 'add_filter' )->once()->with(
			'woi_pdf_after_dompdf_render',
			array( PreviewWatermark::class, 'stamp_after_render' ),
			10,
			4
		);
		PreviewWatermark::register();
	}

	public function test_unregister_removes_filter(): void {
		Functions\expect( 'remove_filter' )->once()->with(
			'woi_pdf_after_dompdf_render',
			array( PreviewWatermark::class, 'stamp_after_render' ),
			10
		);
		PreviewWatermark::unregister();
	}
}
