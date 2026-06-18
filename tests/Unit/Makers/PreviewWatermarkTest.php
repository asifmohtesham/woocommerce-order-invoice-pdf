<?php
namespace WOI\PDF\Tests\Unit\Makers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Makers\PreviewWatermark;

class PreviewWatermarkTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fake mPDF instance recording watermark configuration. */
	private function fakeMpdf() {
		return new class {
			public $watermarkText = null;
			public $showWatermarkText = false;
			public $watermarkTextAlpha = null;
			public function SetWatermarkText( $text ) { $this->watermarkText = $text; }
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

	public function test_stamp_returns_same_mpdf(): void {
		$mpdf = $this->fakeMpdf();
		$this->assertSame( $mpdf, PreviewWatermark::stamp_before_render( $mpdf ) );
	}

	public function test_stamp_sets_watermark_when_enabled(): void {
		$mpdf = $this->fakeMpdf();
		PreviewWatermark::stamp_before_render( $mpdf );
		$this->assertSame( 'SAMPLE', $mpdf->watermarkText );
		$this->assertTrue( $mpdf->showWatermarkText );
		$this->assertSame( 0.1, $mpdf->watermarkTextAlpha );
	}

	public function test_stamp_skips_when_disabled(): void {
		Functions\when( 'apply_filters' )->alias( function( $hook, $value ) {
			return ( 'woi_pdf_preview_watermark_enabled' === $hook ) ? false : $value;
		} );
		$mpdf = $this->fakeMpdf();
		PreviewWatermark::stamp_before_render( $mpdf );
		$this->assertNull( $mpdf->watermarkText );
		$this->assertFalse( $mpdf->showWatermarkText );
	}

	public function test_register_adds_filter(): void {
		Functions\expect( 'add_filter' )->once()->with(
			'woi_pdf_before_mpdf_render',
			array( PreviewWatermark::class, 'stamp_before_render' ),
			10,
			3
		);
		PreviewWatermark::register();
	}

	public function test_unregister_removes_filter(): void {
		Functions\expect( 'remove_filter' )->once()->with(
			'woi_pdf_before_mpdf_render',
			array( PreviewWatermark::class, 'stamp_before_render' ),
			10
		);
		PreviewWatermark::unregister();
	}
}
