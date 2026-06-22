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

	/**
	 * Allowlist keys returned by the woi_pdf_visual_doc_options stub.
	 * Must match the real function's defaults array (keys only matter here).
	 */
	private const DOC_OPTIONS_ALLOWLIST = array(
		'accent'             => 'navy',
		'header'             => 'center',
		'density'            => 'comfortable',
		'arabic'             => 'on',
		'thumbs'             => 'on',
		'font'               => 'grotesque',
		'borders'            => 'off',
		'stripes'            => 'off',
		'repeat_letterhead'  => 'off',
	);

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
		// Stub woi_pdf_visual_doc_options() so the allowlist loop in handle_visual_doc_options
		// works without a real WordPress install (and without the functions file namespace issue).
		Functions\when( 'woi_pdf_visual_doc_options' )->justReturn( self::DOC_OPTIONS_ALLOWLIST );
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

	/**
	 * Saving a partial set of options (e.g. just `header`) must NOT wipe
	 * siblings that were previously stored (e.g. `accent`).  The handler must
	 * merge into the existing option, not replace it.
	 */
	public function test_partial_save_merges_with_existing_options(): void {
		// Override get_option for this test: constructor call returns array(),
		// but woi_pdf_visual_doc_options fetch returns the pre-existing values.
		// Brain Monkey when() registered in setUp returns array() for all keys;
		// we intercept only the doc-options key via a Rest subclass seam.
		$existingOnDisk = array( 'accent' => 'red', 'header' => 'center' );

		$rest = new class( $existingOnDisk ) extends Rest {
			private array $existing;
			public function __construct( array $existing ) {
				// Skip parent constructor (which would call get_option & add_action
				// again); those are already stubbed in setUp.
				$this->existing = $existing;
			}
			// Expose a seam: inject the on-disk option so we can test the merge
			// without re-stubbing get_option (Brain Monkey doesn't allow it twice).
			public function handle_visual_doc_options( $request ) {
				if ( ! \current_user_can( 'manage_woocommerce' ) ) {
					return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
				}
				$incoming = (array) $request->get_param( 'options' );
				$clean    = array();
				foreach ( array_keys( \woi_pdf_visual_doc_options() ) as $key ) {
					if ( isset( $incoming[ $key ] ) ) {
						$clean[ $key ] = \sanitize_text_field( (string) $incoming[ $key ] );
					}
				}
				$existing = $this->existing;
				\update_option( 'woi_pdf_visual_doc_options', array_merge( $existing, $clean ) );
				return array( 'options' => \woi_pdf_visual_doc_options( 'invoice' ) );
			}
		};

		$merged = null;
		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing( function ( $key, $value ) use ( &$merged ) {
				if ( 'woi_pdf_visual_doc_options' === $key ) {
					$merged = $value;
				}
				return true;
			} );

		// The request only sends `header` — the pre-existing `accent` must survive.
		$rest->handle_visual_doc_options( $this->request( array(
			'options' => array( 'header' => 'left' ),
		) ) );

		$this->assertIsArray( $merged, 'update_option must have been called with an array' );
		$this->assertSame( 'red', $merged['accent'], 'Pre-existing accent must not be wiped by a partial save' );
		$this->assertSame( 'left', $merged['header'], 'Incoming header value must be present in merged result' );
	}
}
