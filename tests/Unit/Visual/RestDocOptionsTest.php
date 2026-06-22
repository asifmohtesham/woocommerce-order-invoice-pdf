<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

/**
 * handle_visual_doc_options() must persist EVERY known appearance option. A
 * hardcoded save-allowlist that drifts from the read whitelist
 * (woi_pdf_visual_doc_options) silently drops newly-added options — so a toggle
 * like repeat_letterhead never reaches PDF generation even though the UI shows it
 * on. The save allowlist must derive from the same source of truth as the read.
 */
class RestDocOptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Rest constructor reads settings + hooks actions; doc-options option empty → defaults.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function request( array $params ) {
		return new class( $params ) {
			private array $p;
			public function __construct( $p ) { $this->p = $p; }
			public function get_param( $k ) { return $this->p[ $k ] ?? null; }
		};
	}

	public function test_repeat_letterhead_is_persisted_on_save(): void {
		$saved = null;
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$saved ) {
			if ( 'woi_pdf_visual_doc_options' === $key ) { $saved = $value; }
			return true;
		} );

		( new Rest() )->handle_visual_doc_options( $this->request( array(
			'options' => array( 'repeat_letterhead' => 'on', 'accent' => 'navy' ),
		) ) );

		$this->assertIsArray( $saved );
		$this->assertArrayHasKey( 'repeat_letterhead', $saved, 'repeat_letterhead must survive the save allowlist' );
		$this->assertSame( 'on', $saved['repeat_letterhead'] );
		$this->assertSame( 'navy', $saved['accent'] );
	}

	public function test_unknown_keys_are_dropped_on_save(): void {
		$saved = null;
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$saved ) {
			if ( 'woi_pdf_visual_doc_options' === $key ) { $saved = $value; }
			return true;
		} );

		( new Rest() )->handle_visual_doc_options( $this->request( array(
			'options' => array( 'repeat_letterhead' => 'on', 'evil' => '<script>' ),
		) ) );

		$this->assertArrayHasKey( 'repeat_letterhead', $saved );
		$this->assertArrayNotHasKey( 'evil', $saved );
	}
}
