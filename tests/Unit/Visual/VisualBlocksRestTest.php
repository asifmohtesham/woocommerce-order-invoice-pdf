<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class VisualBlocksRestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
	}
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	private function request( array $params ) {
		return new class( $params ) {
			public array $p;
			public function __construct( array $p ) { $this->p = $p; }
			public function get_param( $k ) { return $this->p[ $k ] ?? null; }
		};
	}

	public function test_blocks_save_renders_then_stores_both_options(): void {
		$captured = array();
		Functions\when( 'update_option' )->alias( function ( $n, $v ) use ( &$captured ) { $captured[ $n ] = $v; return true; } );

		$rest = new class extends Rest {
			public function __construct() {}                       // skip parent hook wiring
			protected function render_blocks( string $markup ): string { return '<p>{{shop_name}}</p>'; }
		};
		$result = $rest->handle_visual_blocks_save( $this->request( array(
			'doc_type' => 'invoice',
			'markup'   => '<!-- wp:woi/shop-name -->{{shop_name}}<!-- /wp:woi/shop-name -->',
		) ) );

		$this->assertSame( array( 'saved' => true ), $result );
		$this->assertStringContainsString( '<!-- wp:woi/shop-name -->', $captured['woi_pdf_visual_blocks_invoice'] );
		$this->assertSame( '<p>{{shop_name}}</p>', $captured['woi_pdf_visual_blocks_html_invoice'] );
	}

	public function test_blocks_save_forbidden_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$rest = new class extends Rest { public function __construct() {} };
		$result = $rest->handle_visual_blocks_save( $this->request( array( 'doc_type' => 'invoice', 'markup' => 'x' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_set_active_source_persists_valid_value(): void {
		$captured = array();
		Functions\when( 'update_option' )->alias( function ( $n, $v ) use ( &$captured ) { $captured[ $n ] = $v; return true; } );
		Functions\when( 'get_option' )->alias( function ( $n ) use ( &$captured ) { return $captured[ $n ] ?? false; } );
		$rest = new class extends Rest { public function __construct() {} };
		$result = $rest->handle_visual_active_source( $this->request( array( 'source' => 'blocks' ) ) );
		$this->assertSame( 'blocks', $captured['woi_pdf_visual_active_source'] );
		$this->assertSame( array( 'source' => 'blocks' ), $result );
	}
}
