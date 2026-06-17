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
