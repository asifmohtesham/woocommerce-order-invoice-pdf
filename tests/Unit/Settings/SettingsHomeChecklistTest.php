<?php
namespace WOI\PDF\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings\SettingsHome;

class SettingsHomeChecklistTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_all_items_undone_on_fresh_install(): void {
		$items = SettingsHome::compute_checklist( array(), array(), 1 );
		$this->assertCount( 5, $items );
		foreach ( $items as $item ) {
			$this->assertFalse( $item['done'], "item {$item['id']} should be undone" );
		}
	}

	public function test_shop_address_requires_name_and_line_1(): void {
		$items = SettingsHome::compute_checklist( array( 'shop_name' => 'Milano' ), array(), 1 );
		$this->assertFalse( $items['shop_address']['done'] );

		$items = SettingsHome::compute_checklist(
			array( 'shop_name' => 'Milano', 'shop_address_line_1' => 'Street 1' ),
			array(),
			1
		);
		$this->assertTrue( $items['shop_address']['done'] );
	}

	public function test_multilingual_array_values_count_as_filled(): void {
		$items = SettingsHome::compute_checklist(
			array(
				'shop_name'           => array( 'default' => 'Milano' ),
				'shop_address_line_1' => array( 'default' => 'Street 1' ),
			),
			array(),
			1
		);
		$this->assertTrue( $items['shop_address']['done'] );
	}

	public function test_numbering_done_when_format_set_or_sequence_started(): void {
		$items = SettingsHome::compute_checklist( array(), array( 'number_format' => array( 'prefix' => 'INV-' ) ), 1 );
		$this->assertTrue( $items['numbering']['done'] );

		$items = SettingsHome::compute_checklist( array(), array(), 482 );
		$this->assertTrue( $items['numbering']['done'] );
	}

	public function test_invoice_enabled_logo_and_attachment(): void {
		$items = SettingsHome::compute_checklist(
			array( 'header_logo' => '123' ),
			array( 'enabled' => 1, 'attach_to_email_ids' => array( 'customer_invoice' ) ),
			1
		);
		$this->assertTrue( $items['invoice_enabled']['done'] );
		$this->assertTrue( $items['logo']['done'] );
		$this->assertTrue( $items['email_attachment']['done'] );
	}
}
