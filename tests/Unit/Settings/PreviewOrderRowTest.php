<?php
namespace WOI\PDF\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings;

/**
 * Guards the preview order-search row builder used by the Visual editor combobox.
 * - line/unit counts and payment label are computed correctly
 * - new raw (label-free) amount/date fields are present
 * - legacy label-prefixed fields (consumed by admin.js) are preserved
 */
class PreviewOrderRowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();
		// woi_pdf_sanitize_html_content() internals: keep filter passthrough.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_price' )->alias( function ( $v ) { return 'AED ' . number_format( (float) $v, 2 ); } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function settings(): Settings {
		return ( new \ReflectionClass( Settings::class ) )->newInstanceWithoutConstructor();
	}

	private function build_row( $order ): array {
		$m = new \ReflectionMethod( Settings::class, 'build_order_row' );
		$m->setAccessible( true );
		return $m->invoke( $this->settings(), $order );
	}

	private function stub_order() {
		return new class {
			public function get_id() { return 237; }
			public function get_order_number() { return '237'; }
			public function get_billing_first_name() { return 'John'; }
			public function get_billing_last_name() { return 'Buyer'; }
			public function get_billing_company() { return 'Nesto Hypermarket LLC'; }
			public function get_payment_method_title() { return 'Bank transfer'; }
			public function get_total() { return 1250; }
			public function get_date_created() {
				return new class { public function format( $f ) { return '2026/06/17'; } };
			}
			public function get_items() {
				return array(
					new class { public function get_quantity() { return 2; } },
					new class { public function get_quantity() { return 12; } },
				);
			}
		};
	}

	public function test_counts_payment_and_raw_fields(): void {
		$row = $this->build_row( $this->stub_order() );

		$this->assertSame( 2, $row['line_count'], 'two distinct line items' );
		$this->assertSame( 14, $row['unit_count'], 'summed quantities 2 + 12' );
		$this->assertStringContainsString( 'Bank transfer', $row['payment_method'] );
		$this->assertSame( '2026/06/17', $row['date_raw'] );
		$this->assertStringContainsString( '1,250', $row['total_raw'] );
		$this->assertStringNotContainsString( '<strong>', $row['total_raw'], 'raw total has no label prefix' );
	}

	public function test_preserves_legacy_labelled_fields(): void {
		$row = $this->build_row( $this->stub_order() );

		$this->assertSame( '237', $row['order_number'] );
		$this->assertStringContainsString( 'Total', $row['total'] );
		$this->assertStringContainsString( 'Date', $row['date_created'] );
	}
}
