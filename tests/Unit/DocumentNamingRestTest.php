<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class DocumentNamingRestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return is_string( $v ) ? trim( $v ) : $v; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_numbering_types_are_the_four_numbered_docs(): void {
		$this->assertSame(
			array( 'invoice', 'proforma', 'credit-note', 'receipt' ),
			Rest::numbering_types()
		);
	}

	public function test_naming_types_add_packing_slip(): void {
		$this->assertContains( 'packing-slip', Rest::naming_types() );
		$this->assertContains( 'invoice', Rest::naming_types() );
	}

	public function test_read_naming_settings_shape_for_numbered_type(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'number_format'       => array( 'prefix' => 'INV-', 'suffix' => '', 'padding' => '6' ),
			'reset_number_yearly' => '1',
			'filename_template'   => 'INV_{order_number}',
		) );
		$s = Rest::read_naming_settings( 'invoice' );
		$this->assertTrue( $s['has_series'] );
		$this->assertSame( 'INV-', $s['prefix'] );
		$this->assertSame( '6', (string) $s['padding'] );
		$this->assertTrue( $s['reset_number_yearly'] );
		$this->assertSame( 'INV_{order_number}', $s['filename_template'] );
	}

	public function test_read_naming_settings_packing_slip_has_no_series(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$s = Rest::read_naming_settings( 'packing-slip' );
		$this->assertFalse( $s['has_series'] );
		$this->assertSame( '', $s['filename_template'] );
	}

	public function test_merge_preserves_unrelated_keys_and_sets_number_format(): void {
		$existing = array( 'enabled' => '1', 'document_title' => 'Tax Invoice' );
		$incoming = array(
			'prefix'              => 'INV-',
			'suffix'              => '/X',
			'padding'             => '5',
			'reset_number_yearly' => true,
			'filename_template'   => 'INV_{order_number}',
		);
		$merged = Rest::merge_naming_settings( $existing, $incoming, true );
		$this->assertSame( '1', $merged['enabled'] );                 // untouched
		$this->assertSame( 'Tax Invoice', $merged['document_title'] ); // untouched
		$this->assertSame( 'INV-', $merged['number_format']['prefix'] );
		$this->assertSame( '/X', $merged['number_format']['suffix'] );
		$this->assertSame( '5', $merged['number_format']['padding'] );
		$this->assertSame( '1', $merged['reset_number_yearly'] );
		$this->assertSame( 'INV_{order_number}', $merged['filename_template'] );
	}

	public function test_merge_unchecked_reset_removes_key(): void {
		$existing = array( 'reset_number_yearly' => '1' );
		$merged   = Rest::merge_naming_settings( $existing, array( 'reset_number_yearly' => false ), true );
		$this->assertArrayNotHasKey( 'reset_number_yearly', $merged );
	}

	public function test_merge_packing_slip_ignores_number_fields(): void {
		$merged = Rest::merge_naming_settings(
			array(),
			array( 'prefix' => 'X', 'padding' => '4', 'reset_number_yearly' => true, 'filename_template' => 'PS_{order_number}' ),
			false // no series
		);
		$this->assertArrayNotHasKey( 'number_format', $merged );
		$this->assertArrayNotHasKey( 'reset_number_yearly', $merged );
		$this->assertSame( 'PS_{order_number}', $merged['filename_template'] );
	}
}
