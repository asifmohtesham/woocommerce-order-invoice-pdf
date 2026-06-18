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

	public function test_font_family_defaults_to_xbriyaz(): void {
		$this->assertSame( 'xbriyaz', BilingualEngine::instance()->font_family() );
		$this->assertSame( 'xbriyaz', BilingualEngine::instance()->font_family( $this->doc( array() ) ) );
	}

	public function test_font_family_honours_lateef_setting(): void {
		$doc = $this->doc( array( 'second_language_font' => 'lateef' ) );
		$this->assertSame( 'lateef', BilingualEngine::instance()->font_family( $doc ) );
	}

	public function test_font_family_rejects_unknown_font(): void {
		$doc = $this->doc( array( 'second_language_font' => 'comic-sans' ) );
		$this->assertSame( 'xbriyaz', BilingualEngine::instance()->font_family( $doc ) );
	}

	public function test_font_css_empty_when_disabled(): void {
		$this->assertSame( '', BilingualEngine::instance()->font_css( $this->doc( array() ) ) );
	}

	public function test_font_css_present_when_enabled(): void {
		$css = BilingualEngine::instance()->font_css( $this->doc( array( 'enable_second_language' => 1 ) ) );
		// mPDF shapes natively from bundled fonts: no @font-face needed.
		$this->assertStringNotContainsString( '@font-face', $css );
		$this->assertStringContainsString( 'font-family: xbriyaz', $css );
		$this->assertStringContainsString( '.woi-lbl-secondary', $css );
	}
}
