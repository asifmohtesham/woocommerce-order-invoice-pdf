<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WOI\\PDF\\Documents\\Summary' ) ) :

/**
 * Summary Document
 *
 * @class  \WOI\PDF\Documents\Summary
 */

class Summary extends BulkDocument {

	/**
	 * Export settings
	 *
	 * @var array
	 */
	public $export_settings;

	/**
	 * Common (shared) document settings
	 *
	 * @var array
	 */
	public $common_settings;

	public function __construct( $order_ids = array(), $export_settings = array() ) {
		// BulkDocument::__construct expects ($document_type, $order_ids)
		parent::__construct( 'summary', $order_ids );

		// set additional properties
		$this->slug            = 'summary';
		$this->title           = __( 'Summary of Invoices', 'woocommerce-orders-invoice-pdf' );
		$this->order_ids       = apply_filters( 'woi_pdf_summary_order_ids', $order_ids );
		$this->export_settings = apply_filters( 'woi_pdf_summary_export_settings', $export_settings );
		$this->common_settings = WOI_PDF()->settings->get_common_document_settings();

		// remove, we are not using logo here
		remove_action( 'woi_pdf_custom_styles', array( WOI_PDF()->main, 'set_header_logo_height' ), 9, 2 );
	}

	public function get_date( $document_type = '', $order = null, $context = 'view', $formatted = false ): ?\WC_DateTime {
		return new \WC_DateTime( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function output_date(): void {
		$date = $this->get_date();
		echo date_i18n( woi_pdf_date_format( $this, 'document_date' ), $date ? $date->getTimestamp() : 0 );
	}

	public function get_date_title(): string {
		// override to allow for language switching!
		$title = __( 'Summary date', 'woocommerce-orders-invoice-pdf' );
		$title = apply_filters_deprecated( "woi_pdf_{$this->slug}_date_title", array( $title, $this ), '2.15.11', 'woi_pdf_document_date_title' ); // deprecated
		return apply_filters( 'woi_pdf_document_date_title', $title, $this );
	}

	public function get_export_date_type() {
		$date_types = woi_pdf_get_export_bulk_date_types();
		if ( ! empty( $date_type = $this->export_settings['date_type'] ) ) {
			return $date_types[ $date_type ];
		} else {
			return false;
		}
	}

	public function output_export_date_type(): void {
		echo $this->get_export_date_type();
	}

	public function get_export_date_interval() {
		if ( ! empty( $this->export_settings['date_after'] ) && ! empty( $this->export_settings['date_before'] ) ) {
			$date_after  = date_i18n( woi_pdf_date_format( $this, $this->get_export_date_type() ), $this->export_settings['date_after'] );
			$date_before = date_i18n( woi_pdf_date_format( $this, $this->get_export_date_type() ), $this->export_settings['date_before'] );
			$to          = __( 'to', 'woocommerce-orders-invoice-pdf' );
			return "{$date_after} {$to} {$date_before}";
		} else {
			return false;
		}
	}

	public function output_export_date_interval(): void {
		echo $this->get_export_date_interval();
	}

	public function get_title(): string {
		return $this->title;
	}

	public function get_filename( $context = 'download', $args = array() ): string {
		$name      = __( 'summary', 'woocommerce-orders-invoice-pdf' );
		$order_ids = isset( $args['order_ids'] ) ? $args['order_ids'] : $this->order_ids;

		return woi_pdf_build_filename( array(
			'type'          => $this->get_type(),
			'document_type' => $name,
			'order_ids'     => $order_ids,
			'order_number'  => '',
			'order_id'      => 0,
			'output_format' => ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf',
			'context'       => $context,
			'filter_args'   => $args,
		) );
	}

	/**
	 * Check if credit note data should be shown.
	 *
	 * @return bool
	 */
	public function show_credit_note_data(): bool {
		$show_credit_note_data = $this->export_settings['show_credit_note_data'] ?? '';

		return ! empty( $show_credit_note_data ) && 'no' !== $show_credit_note_data;
	}

	/**
	 * Get credit note headers based on settings.
	 *
	 * @return array
	 */
	public function get_credit_note_headers(): array {
		$mode = $this->export_settings['show_credit_note_data'] ?? '';

		return array(
			'show_number_and_date' => array(
				array(
					'class' => 'credit-note-number',
					'label' => __( 'Credit Note number and date', 'woocommerce-orders-invoice-pdf' )
				),
			),
			'show_number' => array(
				array(
					'class' => 'credit-note-number',
					'label' => __( 'Credit Note number', 'woocommerce-orders-invoice-pdf' )
				),
			),
			'show_number_and_date_separated' => array(
				array(
					'class' => 'credit-note-date',
					'label' => __( 'Credit Note date', 'woocommerce-orders-invoice-pdf' )
				),
				array(
					'class' => 'credit-note-number',
					'label' => __( 'Credit Note number', 'woocommerce-orders-invoice-pdf' )
				),
			),
		)[ $mode ] ?? array();
	}

	/**
	 * Get credit note data based on settings.
	 *
	 * @param \WC_Order $order
	 *
	 * @return array
	 */
	public function get_credit_note_data( \WC_Order $order ): array {
		$mode        = $this->export_settings['show_credit_note_data'] ?? '';
		$credit_note = woi_pdf_get_document( 'credit-note', $order );
		$order_ids   = array( $order->get_id() );

		if ( $credit_note instanceof BulkDocument ) {
			$order_ids = (array) $credit_note->order_ids ?? $order_ids;
		}

		$numbers = array();
		$dates   = array();

		foreach ( $order_ids as $order_id ) {
			$_credit_note = woi_pdf_get_document( 'credit-note', (int) $order_id );

			if ( $_credit_note && $_credit_note->exists() ) {
				$numbers[ $order_id ] = $_credit_note->get_number();
				$dates[ $order_id ]   = $_credit_note->get_date( '', null, 'view', true );
			} else {
				$order_ids = array_diff( $order_ids, array( $order_id ) );
			}
		}

		return array(
			'show_number_and_date' => array(
				array(
					'class' => 'credit-note-number-and-date',
					'value' => implode( '<br> ', array_map( function ( $order_id ) use ( $numbers, $dates ) {
						return $numbers[ $order_id ] . ' (' . $dates[ $order_id ] . ')';
					}, $order_ids ) )
				),
			),
			'show_number' => array(
				array(
					'class' => 'credit-note-number',
					'value' => implode( '<br>', $numbers ),
				),
			),
			'show_number_and_date_separated' => array(
				array(
					'class' => 'credit-note-date',
					'value' => implode( '<br>', $dates ),
				),
				array(
					'class' => 'credit-note-number',
					'value' => implode( '<br>', $numbers ),
				),
			),
		)[ $mode ] ?? array();
	}

	public function init_settings(): void {
		$page = $option_group = $option_name = 'woi_pdf_documents_settings_summary';

		ob_start();
		?>
		<p><?php esc_html_e( 'The Summary of Invoices is a bulk document generated from the Orders list. It does not have per-order settings — configure the individual invoice template via the Invoice tab.', 'woocommerce-orders-invoice-pdf' ); ?></p>
		<?php
		$html = ob_get_clean();

		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'summary_info',
				'title'    => __( 'Summary of Invoices', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'summary_notice',
				'title'    => '',
				'callback' => 'html_section',
				'section'  => 'summary_info',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'summary_notice',
					'html'        => $html,
				),
			),
		);

		\WOI_PDF()->settings->add_settings_fields( $settings_fields, $page, $option_group, $option_name );
	}
}

endif; // class_exists
