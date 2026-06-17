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
}
