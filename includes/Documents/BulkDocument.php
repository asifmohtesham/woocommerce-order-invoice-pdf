<?php
namespace WOI\PDF\Documents;

use WOI\PDF\Documents\BulkDocumentInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WOI\\PDF\\Documents\\BulkDocument' ) ) :

/**
 * Bulk Document
 *
 * Wraps single documents in a bulk document
 */

abstract class BulkDocument extends OrderDocument implements BulkDocumentInterface {

	/**
	 * Document slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Document type.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Wrapper document - used for filename etc.
	 *
	 * @var string
	 */
	public $wrapper_document;

	/**
	 * Order IDs.
	 *
	 * @var array
	 */
	public $order_ids;

	/**
	 * Is bulk document
	 *
	 * @var bool
	 */
	public $is_bulk;

	/**
	 * Document output formats
	 *
	 * @var array
	 */
	public $output_formats;

	public function __construct( $document_type, $order_ids = array() ) {
		$this->slug      = 'bulk';
		$this->type      = $document_type;
		$this->order_ids = $order_ids;
		$this->is_bulk   = true;

		// output formats (placed after parent construct to override the abstract default)
		$this->output_formats = apply_filters( 'woi_pdf_document_output_formats', array( 'pdf' ), $this );
	}

	public function set_order_ids( array $order_ids ): void {
		$this->order_ids = $order_ids;
	}

	public function exists(): bool {
		$exists = false;

		foreach ( $this->order_ids as $order_id ) {
			$document = woi_pdf_get_document( $this->type, $order_id );
			if ( $document && is_callable( array( $document, 'exists' ) ) && $document->exists() ) {
				$exists = true;
				break;
			}
		}

		return $exists;
	}

	public function is_enabled( $output_format = 'pdf' ): bool {
		if ( in_array( $output_format, $this->output_formats ) ) {
			return true;
		}
		return false;
	}

	public function get_type(): string {
		return $this->type;
	}

	public function get_pdf() {
		do_action( 'woi_pdf_before_pdf', $this->get_type(), $this );

		// temporarily apply filters that need to be removed again after the pdf is generated
		$pdf_filters = apply_filters( 'woi_pdf_pdf_filters', array(), $this );
		\woi_pdf_add_filters( $pdf_filters );

		$html = $this->get_html();
		$pdf_settings = array(
			'paper_size'		=> apply_filters( 'woi_pdf_paper_format', $this->wrapper_document->get_setting( 'paper_size', 'A4' ), $this->get_type(), $this ),
			'paper_orientation'	=> apply_filters( 'woi_pdf_paper_orientation', 'portrait', $this->get_type(), $this ),
			'font_subsetting'	=> $this->wrapper_document->get_setting( 'font_subsetting', false ),
		);
		$pdf_maker = woi_pdf_get_pdf_maker( $html, $pdf_settings, $this );
		$pdf = apply_filters( 'woi_pdf_pdf_data', $pdf_maker->output(), $this );

		do_action( 'woi_pdf_after_pdf', $this->get_type(), $this );

		// remove temporary filters
		\woi_pdf_remove_filters( $pdf_filters );

		return $pdf;
	}

	public function get_html( $args = array() ): string {
		// temporarily apply filters that need to be removed again after the html is generated
		$html_filters = apply_filters( 'woi_pdf_html_filters', array(), $this );
		\woi_pdf_add_filters( $html_filters );

		do_action( 'woi_pdf_before_html', $this->get_type(), $this );

		$html_content = array();
		foreach ( $this->order_ids as $key => $order_id ) {
			do_action( 'woi_pdf_process_template_order', $this->get_type(), $order_id );

			$order = wc_get_order( $order_id );

			if ( $document = woi_pdf_get_document( $this->get_type(), $order, true ) ) {
				$html_content[ $key ] = $document->get_html( array( 'wrap_html_content' => false ) );
			}
		}

		// get wrapper document & insert body content
		$this->wrapper_document = woi_pdf_get_document( $this->get_type(), null );
		$html = $this->wrapper_document->wrap_html_content( $this->merge_documents( $html_content ) );

		// clean up special characters
		if ( apply_filters( 'woi_pdf_convert_encoding', function_exists( 'htmlspecialchars_decode' ) ) ) {
			$html = htmlspecialchars_decode( woi_pdf_convert_encoding( $html ), ENT_QUOTES );
		}

		do_action( 'woi_pdf_after_html', $this->get_type(), $this );

		// remove temporary filters
		\woi_pdf_remove_filters( $html_filters );

		return $html;
	}


	public function merge_documents( $html_content ) {
		// insert page breaks merge
		$page_break = "\n<div style=\"page-break-before: always;\"></div>\n";
		$html = implode( $page_break, $html_content );
		return apply_filters( 'woi_pdf_merged_bulk_document_content', $html, $html_content, $this );
	}

	public function output_pdf( $output_mode = 'download' ) {
		$pdf = $this->get_pdf();
		woi_pdf_pdf_headers( $this->get_filename(), $output_mode, $pdf );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		die();
	}

	public function output_html() {
		echo $this->get_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		die();
	}

	public function get_filename( $context = 'download', $args = array() ) {
		if ( empty( $this->wrapper_document ) ) {
			$this->wrapper_document = woi_pdf_get_document( $this->get_type(), null );
		}
		$default_args = array(
			'order_ids' => $this->order_ids,
		);
		$args = $args + $default_args;
		$filename = $this->wrapper_document->get_filename( $context, $args );
		return $filename;
	}

	protected function add_filters( $filters ) {
		\woi_pdf_deprecated_function( __FUNCTION__, '5.0.0', 'woi_pdf_add_filters' );
		return woi_pdf_add_filters( $filters );
	}

	protected function remove_filters( $filters ) {
		\woi_pdf_deprecated_function( __FUNCTION__, '5.0.0', 'woi_pdf_remove_filters' );
		return woi_pdf_remove_filters( $filters );
	}

	protected function normalize_filter_args( $filter ) {
		\woi_pdf_deprecated_function( __FUNCTION__, '5.0.0', 'woi_pdf_normalize_filter_args' );
		return woi_pdf_normalize_filter_args( $filter );
	}

}

endif; // class_exists
