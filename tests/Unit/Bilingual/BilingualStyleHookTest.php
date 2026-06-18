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
		$this->assertStringContainsString( 'font-family: xbriyaz', $out );
	}

	public function test_callback_silent_when_disabled(): void {
		ob_start();
		woi_pdf_print_bilingual_styles( 'invoice', $this->doc( array() ) );
		$out = ob_get_clean();
		$this->assertSame( '', $out );
	}
}
