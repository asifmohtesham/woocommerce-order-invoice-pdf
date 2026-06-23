<?php
namespace WOI\PDF\Tests\Unit\Editor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Editor\EditorSettings;
use WOI\PDF\Editor\EditorMain;

class UomColumnTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Mirror the existing test pattern: translations pass through,
		// apply_filters returns the filtered value (its 2nd argument).
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wc_help_tip' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_schema_registers_uom_with_unit_width_and_label_ar(): void {
		$schema = EditorSettings::instance()->get_columns_field_options();

		$this->assertArrayHasKey( 'uom', $schema, 'uom column type must be registered' );
		$this->assertArrayHasKey( 'unit', $schema['uom']['options'], 'uom must expose a unit option' );
		// Added to every block by the post-processing foreach in get_columns_field_options().
		$this->assertArrayHasKey( 'width', $schema['uom']['options'], 'width is auto-added to every block' );
		$this->assertArrayHasKey( 'label_ar', $schema['uom']['options'], 'Arabic header is auto-added to every block' );
	}

	public function test_header_default_title_and_class(): void {
		$editor = new EditorMain();
		$header = $editor->get_order_details_header( array( 'type' => 'uom' ), null );

		$this->assertSame( 'UOM', $header['title'], 'default uom header title' );
		$this->assertStringContainsString( 'uom', $header['class'], 'uom header carries the uom css class' );
	}

	public function test_cell_defaults_to_nos(): void {
		$editor = new EditorMain();
		$column = $editor->get_order_details_data( array( 'type' => 'uom' ), array(), null );

		$this->assertSame( 'Nos', $column['data'], 'uom cell defaults to Nos when unit is unset' );
	}

	public function test_cell_uses_configured_unit(): void {
		$editor = new EditorMain();
		$column = $editor->get_order_details_data(
			array( 'type' => 'uom', 'unit' => 'PCS' ),
			array(),
			null
		);

		$this->assertSame( 'PCS', $column['data'], 'uom cell echoes the configured unit verbatim' );
	}
}
