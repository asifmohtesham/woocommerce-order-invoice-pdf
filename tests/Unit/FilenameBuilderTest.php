<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * woi_pdf_build_filename() renders the configurable filename template,
 * always including the order number, and centralizes the filter +
 * sanitize_file_name() contract that used to be duplicated per document.
 */
class FilenameBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default: no saved settings -> helper falls back to defaults.
		Functions\when( 'get_option' )->justReturn( array() );

		// date_i18n( $format ) -> deterministic fixed date for assertions.
		Functions\when( 'date_i18n' )->alias( function ( $format ) {
			return gmdate( $format, strtotime( '2026-06-20' ) );
		} );

		// Pass the filename through the filter unchanged.
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			return $value;
		} );

		// Minimal sanitize_file_name: strip WP special chars (incl. parens),
		// collapse whitespace to dashes. Enough to assert our behavior.
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			$name = preg_replace( '/[?\[\]\/\\\\=<>:;,\'"&$#*()|~`!{}%+]/', '', $name );
			$name = preg_replace( '/[\s]+/', '-', $name );
			return trim( $name, '.-_' );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function args( array $overrides = array() ): array {
		return array_merge( array(
			'type'            => 'invoice',
			'document_type'   => 'invoice',
			'order_ids'       => array( 55 ),
			'order_number'    => '1042',
			'order_id'        => 55,
			'document_number' => '',
			'output_format'   => 'pdf',
			'context'         => 'download',
			'filter_args'     => array(),
		), $overrides );
	}

	public function test_default_template_single_order(): void {
		// Underscore delimits the data points; the date keeps its own hyphens.
		$this->assertSame(
			'invoice_1042_2026-06-20.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_document_number_token_present_when_in_template(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}-{order_number}-{document_number}-{date}',
		) );
		$this->assertSame(
			'invoice-1042-INV0007-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array( 'document_number' => 'INV0007' ) ) )
		);
	}

	public function test_empty_document_number_collapses_separators(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}-{order_number}-{document_number}-{date}',
		) );
		$this->assertSame(
			'invoice-1042-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array( 'document_number' => '' ) ) )
		);
	}

	public function test_underscore_template_empty_document_number_collapses(): void {
		// A custom underscore template with an empty {document_number} leaves a
		// doubled underscore that collapses to one — and the underscore
		// separator is preserved (never rewritten to a dash).
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}_{order_number}_{document_number}_{date}',
		) );
		$this->assertSame(
			'invoice_1042_2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array( 'document_number' => '' ) ) )
		);
	}

	public function test_bulk_order_number_becomes_count(): void {
		// Underscore delimits data points; "12-orders" keeps its internal hyphen.
		$this->assertSame(
			'invoices_3-orders_2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array(
				'document_type' => 'invoices',
				'order_ids'     => array( 55, 56, 57 ),
			) ) )
		);
	}

	public function test_custom_date_format(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_date_format' => 'd-m-Y',
		) );
		$this->assertSame(
			'invoice_1042_20-06-2026.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_empty_template_falls_back_to_default(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '',
		) );
		$this->assertSame(
			'invoice_1042_2026-06-20.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_empty_order_number_falls_back_to_order_id(): void {
		$this->assertSame(
			'invoice_55_2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array( 'order_number' => '' ) ) )
		);
	}

	public function test_parentheses_are_stripped_by_sanitize(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}-({order_number})-{date}',
		) );
		$this->assertSame(
			'invoice-1042-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_settings_resolver_applies_defaults(): void {
		$settings = woi_pdf_get_filename_settings();
		$this->assertSame( '{document_type}_{order_number}_{date}', $settings['template'] );
		$this->assertSame( 'Y-m-d', $settings['date_format'] );
	}

	public function test_no_order_context_drops_order_number_token(): void {
		// Summary export with zero orders: no order id, no order number, no ids.
		// The empty {order_number} leaves "summary__2026-06-20" which the
		// underscore-aware collapse reduces to a single underscore.
		$this->assertSame(
			'summary_2026-06-20.pdf',
			woi_pdf_build_filename( array(
				'type'          => 'summary',
				'document_type' => 'summary',
				'order_ids'     => array(),
				'order_number'  => '',
				'order_id'      => 0,
				'output_format' => 'pdf',
				'context'       => 'download',
				'filter_args'   => array(),
			) )
		);
	}

	public function test_filter_receives_expected_arguments(): void {
		$captured = array();
		Functions\when( 'apply_filters' )->alias( function ( ...$a ) use ( &$captured ) {
			$captured = $a;
			return $a[1]; // return $filename unchanged
		} );

		woi_pdf_build_filename( $this->args() );

		$this->assertSame( 'woi_pdf_filename', $captured[0] );
		$this->assertSame( 'invoice', $captured[2] );        // $type
		$this->assertSame( array( 55 ), $captured[3] );      // $order_ids
		$this->assertSame( 'download', $captured[4] );       // $context
		$this->assertSame( array(), $captured[5] );          // $filter_args
	}

	public function test_no_order_context_passes_empty_order_ids_to_filter(): void {
		$captured = array();
		Functions\when( 'apply_filters' )->alias( function ( ...$a ) use ( &$captured ) {
			$captured = $a;
			return $a[1];
		} );

		woi_pdf_build_filename( array(
			'type'          => 'summary',
			'document_type' => 'summary',
			'order_ids'     => array(),
			'order_number'  => '',
			'order_id'      => 0,
			'output_format' => 'pdf',
			'context'       => 'download',
			'filter_args'   => array(),
		) );

		$this->assertSame( array(), $captured[3] ); // no-order context -> empty order_ids
	}
}
