<?php
namespace WOI\PDF\Documents;

use WOI\PDF\Documents\NumberedDocumentInterface;
use WOI\PDF\Documents\EmailAttachableInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WOI\\PDF\\Documents\\CreditNote' ) ) :

/**
 * Credit Note Document
 *
 * @class  \WOI\PDF\Documents\CreditNote
 */

class CreditNote extends OrderDocumentMethods implements NumberedDocumentInterface, EmailAttachableInterface {

	/**
	 * @var string
	 */
	public $type;

	/**
	 * @var string
	 */
	public $title;

	/**
	 * @var string
	 */
	public $icon;

	/**
	 * Init/load the order object.
	 *
	 * @param  int|object|WC_Order $order Order to init.
	 */
	public function __construct( $order = 0 ) {
		// set properties
		$this->type  = 'credit-note';
		$this->title = __( 'Credit Note', 'woocommerce-orders-invoice-pdf' );
		$this->icon  = WOI_PDF()->plugin_url() . '/assets/images/credit-note.svg';

		// Call parent constructor
		parent::__construct( $order );

		// Add XML without wiping what the parent put in $this->output_formats
		if ( ! in_array( 'xml', $this->output_formats, true ) ) {
			$this->output_formats[] = 'xml';
		}

		$this->output_formats = apply_filters(
			'woi_pdf_document_output_formats',
			$this->output_formats,
			$this
		);

		// Determine numbering system (main invoice number or separate document sequence)
		add_filter( 'woi_pdf_document_sequential_number_store', array( $this, 'get_number_sequence' ), 1, 2 );
	}

	public function use_historical_settings(): bool {
		$document_settings = get_option( 'woi_pdf_documents_settings_' . $this->get_type() );
		// this setting is inverted on the frontend so that it needs to be actively/purposely enabled to be used
		if ( ! empty( $document_settings ) && isset( $document_settings['use_latest_settings'] ) ) {
			$use_historical_settings = false;
		} else {
			$use_historical_settings = true;
		}
		return apply_filters( 'woi_pdf_document_use_historical_settings', $use_historical_settings, $this );
	}

	public function storing_settings_enabled(): bool {
		return apply_filters( 'woi_pdf_document_store_settings', true, $this );
	}

	public function init( $order = null ): void {
		// init settings
		$this->init_settings_data();
		$this->save_settings();

		if ( isset( $this->settings['display_date'] ) && 'order_date' === $this->settings['display_date'] && ! empty( $this->order ) ) {
			$this->set_date( $this->order->get_date_created() );
		} elseif ( empty( $this->get_date() ) ) {
			$this->set_date( current_time( 'timestamp', true ) );
		}

		$this->initiate_number();

		do_action( 'woi_pdf_init_document', $this );
	}

	public function exists(): bool {
		return ! empty( $this->data['number'] );
	}

	public function get_number_sequence( $number_store_name, $document ): string {
		return isset( $document->settings['number_sequence'] ) ? $document->settings['number_sequence'] : "{$document->slug}_number";
	}

	public function get_title(): string {
		// override/not using $this->title to allow for language switching!
		$title = __( 'Credit Note', 'woocommerce-orders-invoice-pdf' );
		$title = apply_filters_deprecated( "woi_pdf_{$this->slug}_title", array( $title, $this ), '2.15.11', 'woi_pdf_document_title' ); // deprecated
		return apply_filters( 'woi_pdf_document_title', $title, $this );
	}

	public function get_number_title(): string {
		// override to allow for language switching!
		$title = __( 'Credit Note Number:', 'woocommerce-orders-invoice-pdf' );
		$title = apply_filters_deprecated( "woi_pdf_{$this->slug}_number_title", array( $title, $this ), '2.15.11', 'woi_pdf_document_number_title' ); // deprecated
		return apply_filters( 'woi_pdf_document_number_title', $title, $this );
	}

	public function get_date_title(): string {
		// override to allow for language switching!
		$title = __( 'Credit Note Date:', 'woocommerce-orders-invoice-pdf' );
		$title = apply_filters_deprecated( "woi_pdf_{$this->slug}_date_title", array( $title, $this ), '2.15.11', 'woi_pdf_document_date_title' ); // deprecated
		return apply_filters( 'woi_pdf_document_date_title', $title, $this );
	}

	/**
	 * Get the shipping address title
	 *
	 * @return string
	 */
	public function get_shipping_address_title(): string {
		// override to allow for language switching!
		return apply_filters( 'woi_pdf_document_shipping_address_title', __( 'Ship To:', 'woocommerce-orders-invoice-pdf' ), $this );
	}

	public function get_filename( $context = 'download', $args = array() ): string {
		$order_ids   = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$order_count = count( $order_ids );
		$name        = _n( 'credit-note', 'credit-notes', $order_count, 'woocommerce-orders-invoice-pdf' );

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

	// -------------------------------------------------------------------------
	// NumberedDocumentInterface
	// -------------------------------------------------------------------------

	// get_number() and get_date() are inherited from OrderDocument; overriding them
	// with a DocumentNumber/WC_DateTime return type would discard the formatted
	// string these methods return when called with $formatted = true.

	public function has_number(): bool {
		return ! empty( $this->data['number'] );
	}

	// -------------------------------------------------------------------------
	// EmailAttachableInterface
	// -------------------------------------------------------------------------

	public function get_attach_to_email_ids( $output_format = 'pdf' ): array {
		return parent::get_attach_to_email_ids( $output_format );
	}

	// -------------------------------------------------------------------------

	/**
	 * Initialise settings
	 */
	public function init_settings(): void {
		// Register settings.
		$page = $option_group = $option_name = 'woi_pdf_documents_settings_credit-note';

		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'credit_note',
				'title'    => '',
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'enabled',
				'title'    => __( 'Enable', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'enabled',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'attach_to_email_ids',
				'title'    => __( 'Attach to:', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'multiple_checkboxes',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name'     => $option_name,
					'id'              => 'attach_to_email_ids',
					'fields_callback' => array( $this, 'get_wc_emails' ),
					'description'     => ! \WOI_PDF()->file_system->is_writable( \WOI_PDF()->main->get_tmp_path( 'attachments' ) )
						? '<span class="wpo-warning">' . sprintf(
							/* translators: %s: temp folder path */
							__( 'It looks like the temp folder (%s) is not writable, check the permissions for this folder! Without having write access to this folder, the plugin will not be able to email invoices.', 'woocommerce-orders-invoice-pdf' ),
							'<code>' . \WOI_PDF()->main->get_tmp_path( 'attachments' ) . '</code>'
						) . '</span>'
						: '',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_shipping_address',
				'title'    => __( 'Display shipping address', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'select',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_shipping_address',
					'options'     => array(
						''               => __( 'No' , 'woocommerce-orders-invoice-pdf' ),
						'when_different' => __( 'Only when different from billing address' , 'woocommerce-orders-invoice-pdf' ),
						'always'         => __( 'Always' , 'woocommerce-orders-invoice-pdf' ),
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_email',
				'title'    => __( 'Display email address', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_email',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_phone',
				'title'    => __( 'Display phone number', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_phone',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_date',
				'title'    => __( 'Display credit note date', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'select',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_date',
					'options'     => array(
						''           => __( 'No' , 'woocommerce-orders-invoice-pdf' ),
						'1'          => __( 'Credit Note Date' , 'woocommerce-orders-invoice-pdf' ),
						'order_date' => __( 'Refund Date' , 'woocommerce-orders-invoice-pdf' ),
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_number',
				'title'    => __( 'Display credit note number', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_number',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'number_sequence',
				'title'    => __( 'Number sequence', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'radio_button',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'number_sequence',
					'options'     => array(
						'invoice_number'     => __( 'Main invoice numbering' , 'woocommerce-orders-invoice-pdf' ),
						'credit_note_number' => __( 'Separate credit note numbering' , 'woocommerce-orders-invoice-pdf' ),
					),
					'default'     => 'credit_note_number',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'original_invoice_number',
				'title'    => __( 'Show original invoice number', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'original_invoice_number',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'next_credit_note_number',
				'title'    => __( 'Next credit note number (without prefix/suffix etc.)', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'next_number_edit',
				'section'  => 'credit_note',
				'args'     => array(
					'store_callback' => array( $this, 'get_sequential_number_store' ),
					'size'           => '10',
					'description'    => __( 'This is the number that will be used for the next document. By default, numbering starts from 1 and increases for every new document. Note that if you override this and set it lower than the current/highest number, this could create duplicate numbers!', 'woocommerce-orders-invoice-pdf' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'number_format',
				'title'    => __( 'Number format', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'multiple_text_input',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'number_format',
					'fields'      => array(
						'prefix'  => array(
							'label'       => __( 'Prefix' , 'woocommerce-orders-invoice-pdf' ),
							'size'        => 20,
							'description' => __( 'If set, this value will be used as number prefix.' , 'woocommerce-orders-invoice-pdf' ) . ' ' . sprintf(
								/* translators: 1. document type, 2-3 placeholders */
								__( 'You can use the %1$s year and/or month with the %2$s or %3$s placeholders respectively.', 'woocommerce-orders-invoice-pdf' ),
								strtolower( __( 'Credit Note', 'woocommerce-orders-invoice-pdf' ) ), '<strong>[credit_note_year]</strong>', '<strong>[credit_note_month]</strong>'
							) . ' ' . __( 'Check the Docs article below to see all the available placeholders for prefix/suffix.', 'woocommerce-orders-invoice-pdf' ),
						),
						'suffix'  => array(
							'label'       => __( 'Suffix' , 'woocommerce-orders-invoice-pdf' ),
							'size'        => 20,
							'description' => __( 'If set, this value will be used as number suffix.' , 'woocommerce-orders-invoice-pdf' ) . ' ' . sprintf(
								/* translators: 1. document type, 2-3 placeholders */
								__( 'You can use the %1$s year and/or month with the %2$s or %3$s placeholders respectively.', 'woocommerce-orders-invoice-pdf' ),
								strtolower( __( 'Credit Note', 'woocommerce-orders-invoice-pdf' ) ), '<strong>[credit_note_year]</strong>', '<strong>[credit_note_month]</strong>'
							) . ' ' . __( 'Check the Docs article below to see all the available placeholders for prefix/suffix.', 'woocommerce-orders-invoice-pdf' ),
						),
						'padding' => array(
							'label'       => __( 'Padding' , 'woocommerce-orders-invoice-pdf' ),
							'size'        => 20,
							'type'        => 'number',
							/* translators: document type */
							'description' => sprintf( __( 'Enter the number of digits you want to use as padding. For instance, enter <code>6</code> to display the %s number <code>123</code> as <code>000123</code>, filling it with zeros until the number set as padding is reached.' , 'woocommerce-orders-invoice-pdf' ), strtolower( __( 'Credit Note', 'woocommerce-orders-invoice-pdf' ) ) ),
						),
					),
					/* translators: document type */
					'description' => __( 'For more information about setting up the number format and see the available placeholders for the prefix and suffix, check this article:', 'woocommerce-orders-invoice-pdf' ) . sprintf( ' <a href="https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/number-format-explained/" target="_blank">%s</a>', __( 'Number format explained', 'woocommerce-orders-invoice-pdf') ) . '.<br><br>' . sprintf( __( '<strong>Note</strong>: Changes made to the number format will only be reflected on new orders. Also, if you have already created a custom %s number format with a filter, the above settings will be ignored.', 'woocommerce-orders-invoice-pdf' ), strtolower( __( 'Credit Note', 'woocommerce-orders-invoice-pdf' ) ) ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'reset_number_yearly',
				'title'    => __( 'Reset credit note number yearly', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'reset_number_yearly',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'credit_note_number_search',
				'title'    => __( 'Enable credit note number search in the orders list', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'credit_note_number_search',
					'description' => __( 'Can potentially slow down the search process.', 'woocommerce-orders-invoice-pdf' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'disable_free',
				'title'    => __( 'Disable for free orders', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'disable_free',
					'description' => sprintf(
						/* translators: %s: price */
						__( 'Disable document when the order total is %s', 'woocommerce-orders-invoice-pdf' ),
						function_exists( 'wc_price' ) ? wc_price( 0 ) : 0
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'positive_prices',
				'title'    => __( 'Use positive prices', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'positive_prices',
					'description' => __( 'Prices in Credit Notes are negative by default, but some countries (like Germany) require positive prices.', 'woocommerce-orders-invoice-pdf' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'use_parent_data',
				'title'    => __( 'Use products & totals fallback', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'use_parent_data',
					'description' => __( 'If orders are refunded without setting products, credit notes will not contain these details. This option provides a fallback method by using data from the original order for the credit note. This may cause issues in some setups, so testing is recommended.', 'woocommerce-orders-invoice-pdf' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'use_latest_settings',
				'title'    => __( 'Always use most current settings', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'use_latest_settings',
					'description' => __( 'When enabled, the document will always reflect the most current settings (such as footer text, document name, etc.) rather than using historical settings.', 'woocommerce-orders-invoice-pdf' )
					                . '<br>'
					                . __( '<strong>Caution:</strong> enabling this will also mean that if you change your company name or address in the future, previously generated documents will also be affected.', 'woocommerce-orders-invoice-pdf' ),
				)
			),
		);

		// Legacy filter to allow plugins to alter settings fields.
		$settings_fields = apply_filters( 'woi_pdf_settings_fields_documents_credit_note', $settings_fields, $page, $option_group, $option_name );

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
						'my_account_buttons',
					),
				),
				'document_details' => array(
					'title'   => __( 'Document details', 'woocommerce-orders-invoice-pdf' ),
					'members' => array(
						'display_email',
						'display_phone',
						'display_customer_notes',
						'display_shipping_address',
						'display_number',
						'original_invoice_number',
						'next_credit_note_number', // this should follow 'display_number'
						'number_format',
						'display_date',
						'positive_prices',
						'use_parent_data',
					)
				),
				'advanced'         => array(
					'title'   => __( 'Advanced', 'woocommerce-orders-invoice-pdf' ),
					'members' => array(
						'reset_number_yearly',
						'number_sequence',
						'disable_free',
						'credit_note_number_search',
						'use_latest_settings',
					)
				),
			),
		);

		return apply_filters( 'woi_pdf_document_settings_categories', $settings_categories[ $output_format ] ?? array(), $output_format, $this );
	}
}

endif; // class_exists
