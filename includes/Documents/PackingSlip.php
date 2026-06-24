<?php
namespace WOI\PDF\Documents;

use WOI\PDF\Documents\EmailAttachableInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WOI\\PDF\\Documents\\PackingSlip' ) ) :

/**
 * Packing Slip Document
 */

class PackingSlip extends OrderDocumentMethods implements EmailAttachableInterface {

	/**
	 * Init/load the order object.
	 *
	 * @param  int|object|WC_Order $order Order to init.
	 */
	public function __construct( $order = 0 ) {
		// set properties
		$this->type  = 'packing-slip';
		$this->title = __( 'Packing Slip', 'woocommerce-orders-invoice-pdf' );
		$this->icon  = WOI_PDF()->plugin_url() . "/assets/images/packing-slip.svg";
		
		// call parent constructor
		parent::__construct( $order );

		$this->output_formats = apply_filters(
			'woi_pdf_document_output_formats',
			$this->output_formats,
			$this
		);
	}

	// -------------------------------------------------------------------------
	// EmailAttachableInterface
	// -------------------------------------------------------------------------

	public function get_attach_to_email_ids( $output_format = 'pdf' ): array {
		return parent::get_attach_to_email_ids( $output_format );
	}

	// -------------------------------------------------------------------------

	/**
	 * Get the document title
	 *
	 * @return string
	 */
	public function get_title(): string {
		// override/not using $this->title to allow for language switching!
		$title = __( 'Packing Slip', 'woocommerce-orders-invoice-pdf' );
		$title = apply_filters_deprecated( "woi_pdf_{$this->slug}_title", array( $title, $this ), '3.8.7', 'woi_pdf_document_title' ); // deprecated
		return apply_filters( 'woi_pdf_document_title', $title, $this );
	}

	/**
	 * Get the document number title
	 *
	 * @return string
	 */
	public function get_number_title() {
		// override to allow for language switching!
		$title = __( 'Packing Slip Number:', 'woocommerce-orders-invoice-pdf' );
		$title = apply_filters_deprecated( "woi_pdf_{$this->slug}_number_title", array( $title, $this ), '3.8.7', 'woi_pdf_document_number_title' ); // deprecated
		return apply_filters( 'woi_pdf_document_number_title', $title, $this );
	}

	/**
	 * Get the document date title
	 *
	 * @return string
	 */
	public function get_date_title() {
		// override to allow for language switching!
		$title = __( 'Packing Slip Date:', 'woocommerce-orders-invoice-pdf' );
		$title = apply_filters_deprecated( "woi_pdf_{$this->slug}_date_title", array( $title, $this ), '3.8.7', 'woi_pdf_document_date_title' ); // deprecated
		return apply_filters( 'woi_pdf_document_date_title', $title, $this );
	}

	public function get_filename( $context = 'download', $args = array() ) {
		$order_ids   = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$order_count = count( $order_ids );
		$name        = _n( 'packing-slip', 'packing-slips', $order_count, 'woocommerce-orders-invoice-pdf' );

		if ( empty( $this->order ) && isset( $order_ids[0] ) ) {
			$order = wc_get_order( $order_ids[0] );
		} else {
			$order = $this->order;
		}
		$order_number = is_callable( array( $order, 'get_order_number' ) ) ? $order->get_order_number() : '';

		return woi_pdf_build_filename( array(
			'type'            => $this->get_type(),
			'document_type'   => $name,
			'order_ids'       => $order_ids,
			'order_number'    => $order_number,
			'order_id'        => $this->order_id,
			'document_number' => (string) $this->get_number(),
			'document_number_sequence' => woi_pdf_document_number_sequence( $this->get_number() ),
			'output_format'   => ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf',
			'context'         => $context,
			'filter_args'     => $args,
		) );
	}

	public function init_settings() {
		// Register settings.
		$page = $option_group = $option_name = 'woi_pdf_documents_settings_packing-slip';

		$settings_fields = array(
			array(
				'type'			=> 'section',
				'id'			=> 'packing_slip',
				'title'			=> '',
				'callback'		=> 'section',
			),
			array(
				'type'			=> 'setting',
				'id'			=> 'enabled',
				'title'			=> __( 'Enable', 'woocommerce-orders-invoice-pdf' ),
				'callback'		=> 'checkbox',
				'section'		=> 'packing_slip',
				'args'			=> array(
					'option_name'		=> $option_name,
					'id'				=> 'enabled',
				)
			),
			array(
				'type'			=> 'setting',
				'id'			=> 'display_billing_address',
				'title'			=> __( 'Display billing address', 'woocommerce-orders-invoice-pdf' ),
				'callback'		=> 'select',
				'section'		=> 'packing_slip',
				'args'			=> array(
					'option_name'	=> $option_name,
					'id'			=> 'display_billing_address',
					'options' 		=> array(
						''				=> __( 'No' , 'woocommerce-orders-invoice-pdf' ),
						'when_different'=> __( 'Only when different from shipping address' , 'woocommerce-orders-invoice-pdf' ),
						'always'		=> __( 'Always' , 'woocommerce-orders-invoice-pdf' ),
					),
					// 'description'	=> __( 'Display billing address (in addition to the default shipping address) if different from shipping address', 'woocommerce-orders-invoice-pdf' ),
				)
			),
			array(
				'type'			=> 'setting',
				'id'			=> 'display_email',
				'title'			=> __( 'Display email address', 'woocommerce-orders-invoice-pdf' ),
				'callback'		=> 'checkbox',
				'section'		=> 'packing_slip',
				'args'			=> array(
					'option_name'	=> $option_name,
					'id'			=> 'display_email',
				)
			),
			array(
				'type'			=> 'setting',
				'id'			=> 'display_phone',
				'title'			=> __( 'Display phone number', 'woocommerce-orders-invoice-pdf' ),
				'callback'		=> 'checkbox',
				'section'		=> 'packing_slip',
				'args'			=> array(
					'option_name'	=> $option_name,
					'id'			=> 'display_phone',
				)
			),
			array(
				'type'			=> 'setting',
				'id'			=> 'display_customer_notes',
				'title'			=> __( 'Display customer notes', 'woocommerce-orders-invoice-pdf' ),
				'callback'		=> 'checkbox',
				'section'		=> 'packing_slip',
				'args'			=> array(
					'option_name'		=> $option_name,
					'id'				=> 'display_customer_notes',
					'store_unchecked'	=> true,
					'default'			=> 1,
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'filename_template',
				'title'    => __( 'PDF filename override', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'packing_slip',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'filename_template',
					'size'        => 'regular',
					'description' => sprintf(
						/* translators: %s: comma-separated list of placeholder tokens */
						__( 'Filename for this document\'s PDF. Leave blank to use the global template (Settings &rarr; General). Available tokens: %s. The extension is added automatically.', 'woocommerce-orders-invoice-pdf' ),
						'<code>{document_type}</code>, <code>{order_number}</code>, <code>{document_number}</code>, <code>{document_number_sequence}</code>, <code>{date}</code>'
					),
				),
			),
		);

		if ( ! function_exists( 'WOI_PDF_Pro' ) ) {
			ob_start();
			?>
			<div class="notice notice-info inline">
				<p><a href="https://wpovernight.com/downloads/woocommerce-pdf-invoices-packing-slips-professional/" target="_blank"><?php esc_html_e( 'Upgrade to our Professional extension to attach packing slips to any email!', 'woocommerce-orders-invoice-pdf' ); ?></a></p>
			</div>
			<?php
			$html = ob_get_clean();

			$pro_notice = array(
				array(
					'type'			=> 'setting',
					'id'			=> 'attach_to_email_ids',
					'title'			=> __( 'Attach to:', 'woocommerce-orders-invoice-pdf' ),
					'callback'		=> 'html_section',
					'section'		=> 'packing_slip',
					'args'			=> array(
						'option_name' => $option_name,
						'id'          => 'attach_to_email_ids',
						'html'        => $html,
					)
				),
			);
			$settings_fields = WOI_PDF()->settings->move_setting_after_id( $settings_fields, $pro_notice, 'enabled' );
		}

		// Legacy filter to allow plugins to alter settings fields.
		$settings_fields = apply_filters( 'woi_pdf_settings_fields_documents_packing_slip', $settings_fields, $page, $option_group, $option_name );

		// Allow plugins to alter settings fields.
		$settings_fields = apply_filters( "woi_pdf_settings_fields_documents_{$this->type}_pdf", $settings_fields, $page, $option_group, $option_name, $this );

		if ( ! empty( $settings_fields ) ) {
			WOI_PDF()->settings->add_settings_fields( $settings_fields, $page, $option_group, $option_name );
		}
	}

	/**
	 * Get the settings categories.
	 *
	 * @param string $output_format
	 *
	 * @return array
	 */
	public function get_settings_categories( string $output_format ): array {
		if ( ! in_array( $output_format, $this->output_formats, true ) ) {
			return array();
		}

		$settings_categories = array(
			'pdf' => array(
				'general'          => array(
					'title'   => __( 'General', 'woocommerce-orders-invoice-pdf' ),
					'members' => array(
						'enabled',
						'attach_to_email_ids',
					),
				),
				'document_details' => array(
					'title'   => __( 'Document details', 'woocommerce-orders-invoice-pdf' ),
					'members' => array(
						'display_email',
						'display_phone',
						'display_customer_notes',
						'display_billing_address',
					),
				),
			),
		);

		return apply_filters( 'woi_pdf_document_settings_categories', $settings_categories[ $output_format ], $output_format, $this );
	}

}

endif; // class_exists
