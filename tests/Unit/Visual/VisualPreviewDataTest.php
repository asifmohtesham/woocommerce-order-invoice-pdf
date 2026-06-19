<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class VisualPreviewDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Rest constructor reads debug settings + hooks actions.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Rest subclass that overrides both seams with fixture values.
	 *
	 * woi_pdf_get_document() is defined in woi-pdf-functions.php before Patchwork loads,
	 * so it cannot be stubbed via Brain\Monkey. The protected get_document() seam is used
	 * instead, keeping tests hermetic without touching the bootstrap load order.
	 */
	private function rest_with_map( array $map, bool $null_document = false ): Rest {
		$doc = $null_document ? false : new \stdClass();
		return new class( $map, $doc ) extends Rest {
			private array $map;
			/** @var object|false */
			private $doc;
			public function __construct( array $map, $doc ) {
				$this->map = $map;
				$this->doc = $doc;
				parent::__construct();
			}
			protected function get_document( string $doc_type, $order ) { return $this->doc; }
			protected function token_map( $document ): array { return $this->map; }
		};
	}

	private function stub_order( int $id ) {
		return new class( $id ) {
			private int $id;
			public function __construct( $id ) { $this->id = $id; }
			public function get_order_number() { return (string) $this->id; }
			public function get_billing_first_name() { return 'John'; }
			public function get_billing_last_name() { return 'Buyer'; }
		};
	}

	private function request( array $params ) {
		return new class( $params ) {
			private array $p;
			public function __construct( $p ) { $this->p = $p; }
			public function get_param( $k ) { return $this->p[ $k ] ?? null; }
		};
	}

	public function test_returns_token_map_for_explicit_order(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_order' )->justReturn( $this->stub_order( 239 ) );

		$rest   = $this->rest_with_map( array( '{{line_items}}' => '<table></table>' ) );
		$result = $rest->handle_visual_preview_data( $this->request( array( 'order_id' => 239, 'doc_type' => 'invoice' ) ) );

		$this->assertSame( 239, $result['order_id'] );
		$this->assertStringContainsString( '#239', $result['order_label'] );
		$this->assertStringContainsString( 'John Buyer', $result['order_label'] );
		$this->assertSame( '<table></table>', $result['tokens']['{{line_items}}'] );
	}

	public function test_defaults_to_last_order_when_no_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_orders' )->justReturn( array( 512 ) );
		Functions\when( 'wc_get_order' )->justReturn( $this->stub_order( 512 ) );

		$rest   = $this->rest_with_map( array() );
		$result = $rest->handle_visual_preview_data( $this->request( array() ) );

		$this->assertSame( 512, $result['order_id'] );
	}

	public function test_404_when_no_order_found(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'wc_get_order' )->justReturn( false );

		$rest   = $this->rest_with_map( array() );
		$result = $rest->handle_visual_preview_data( $this->request( array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_403_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$rest   = $this->rest_with_map( array() );
		$result = $rest->handle_visual_preview_data( $this->request( array( 'order_id' => 1 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_404_when_no_document_built(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_order' )->justReturn( $this->stub_order( 7 ) );

		$rest   = $this->rest_with_map( array(), true );
		$result = $rest->handle_visual_preview_data( $this->request( array( 'order_id' => 7 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}
}
