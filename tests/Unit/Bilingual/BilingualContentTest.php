<?php
namespace WOI\PDF\Tests\Unit\Bilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Bilingual\BilingualEngine;

class BilingualContentTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_shop_name_ar_from_settings(): void {
		Functions\when( 'get_option' )->justReturn( array( 'shop_name_ar' => 'ميلانو' ) );
		$this->assertSame( 'ميلانو', BilingualEngine::instance()->secondary_shop_name() );
	}

	public function test_shop_name_ar_empty_when_unset(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( '', BilingualEngine::instance()->secondary_shop_name() );
	}

	public function test_localized_country_uses_wc_name(): void {
		Functions\when( 'switch_to_locale' )->justReturn( true );
		Functions\when( 'restore_previous_locale' )->justReturn( true );
		$countries = new class {
			public function get_countries() { return array( 'AE' => 'الإمارات العربية المتحدة' ); }
			public function get_states( $cc ) { return array(); }
		};
		Functions\when( 'WC' )->justReturn( new class( $countries ) {
			public $countries;
			public function __construct( $c ) { $this->countries = $c; }
		} );
		$order = new class {
			public function get_billing_country() { return 'AE'; }
			public function get_billing_state() { return ''; }
		};
		$this->assertSame(
			'الإمارات العربية المتحدة',
			BilingualEngine::instance()->localized_location( 'UAE', 'country', $order )
		);
	}

	public function test_localized_country_falls_back_when_missing(): void {
		Functions\when( 'switch_to_locale' )->justReturn( true );
		Functions\when( 'restore_previous_locale' )->justReturn( true );
		$countries = new class {
			public function get_countries() { return array(); }
			public function get_states( $cc ) { return array(); }
		};
		Functions\when( 'WC' )->justReturn( new class( $countries ) {
			public $countries;
			public function __construct( $c ) { $this->countries = $c; }
		} );
		$order = new class {
			public function get_billing_country() { return 'AE'; }
			public function get_billing_state() { return ''; }
		};
		$this->assertSame( 'UAE', BilingualEngine::instance()->localized_location( 'UAE', 'country', $order ) );
	}
}
