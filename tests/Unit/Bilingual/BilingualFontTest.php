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
