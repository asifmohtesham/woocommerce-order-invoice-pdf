<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

/**
 * Tests for POST woi-pdf/v1/naming-preview.
 *
 * woi_pdf_get_document() and woi_pdf_format_document_number() are defined in
 * woi-pdf-functions.php before Patchwork loads, so they cannot be stubbed via
 * Brain\Monkey\Functions\when(). The protected get_document() / format_document_number()
 * seams on Rest are overridden in subclasses instead — the same pattern used
 * throughout the suite (e.g. VisualPreviewDataTest).
 */
class DocumentNamingPreviewRestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return is_string( $v ) ? trim( $v ) : $v; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		// Rest constructor reads debug settings + hooks actions.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Build a minimal fake request that returns values via get_param(). */
	private function make_request( array $params ): object {
		return new class( $params ) {
			private array $p;
			public function __construct( array $p ) { $this->p = $p; }
			public function get_param( string $key ) { return $this->p[ $key ] ?? null; }
		};
	}

	// -------------------------------------------------------------------------
	// 1) Unknown type → WP_Error 400
	// -------------------------------------------------------------------------

	public function test_unknown_type_returns_400_error(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$rest = new Rest();
		$req  = $this->make_request( array( 'type' => 'not-a-type', 'order_id' => 1 ) );
		$res  = $rest->handle_naming_preview( $req );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$data = $res->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	// -------------------------------------------------------------------------
	// 2) Series type with a stubbed order + document resolves both previews.
	//    Uses Rest subclass to override get_document() and format_document_number()
	//    seams because both underlying globals are loaded before Patchwork.
	// -------------------------------------------------------------------------

	public function test_series_type_resolves_number_and_filename_previews(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		// Fake order.
		$fake_order = new class {
			public function get_id(): int { return 237; }
			public function get_order_number(): string { return '237'; }
		};
		Functions\when( 'wc_get_order' )->justReturn( $fake_order );

		// Fake document with get_filename().
		$fake_document = new class {
			public function get_filename( string $context, array $args ): string {
				return 'invoice_2026-04-000004_2026-04-22.pdf';
			}
		};

		// Subclass that overrides get_document() and format_document_number() seams.
		$rest = new class( $fake_document ) extends Rest {
			private object $doc;
			public function __construct( object $doc ) {
				$this->doc = $doc;
				parent::__construct();
			}
			protected function get_document( string $doc_type, $order ) {
				return $this->doc;
			}
			protected function format_document_number( int $plain, string $prefix, string $suffix, int $padding, $document, $order ): string {
				return '2026-04-000004';
			}
		};

		$req = $this->make_request( array(
			'type'              => 'invoice',
			'order_id'          => 237,
			'prefix'            => '[invoice_year]-',
			'suffix'            => '',
			'padding'           => 6,
			'next_number'       => 4,
			'filename_template' => '{document_type}_{document_number}_{date}',
		) );
		$res = $rest->handle_naming_preview( $req );

		$this->assertIsArray( $res );
		$this->assertSame( '2026-04-000004', $res['number_preview'] );
		$this->assertSame( 'invoice_2026-04-000004_2026-04-22.pdf', $res['filename_preview'] );
		$this->assertTrue( $res['has_order'] );
		$this->assertSame( 237, $res['order_id'] );
	}

	// -------------------------------------------------------------------------
	// 3) No order found anywhere → has_order false + empty previews
	// -------------------------------------------------------------------------

	public function test_no_order_returns_has_order_false_and_empty_previews(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		// wc_get_order and wc_get_orders both return falsy / empty.
		Functions\when( 'wc_get_order' )->justReturn( false );
		Functions\when( 'wc_get_orders' )->justReturn( array() );

		$rest = new Rest();
		$req  = $this->make_request( array(
			'type'              => 'invoice',
			'order_id'          => 0,
			'prefix'            => 'INV-',
			'suffix'            => '',
			'padding'           => 6,
			'next_number'       => 1,
			'filename_template' => '',
		) );
		$res = $rest->handle_naming_preview( $req );

		$this->assertIsArray( $res );
		$this->assertFalse( $res['has_order'] );
		$this->assertSame( '', $res['number_preview'] );
		$this->assertSame( '', $res['filename_preview'] );
	}
}
