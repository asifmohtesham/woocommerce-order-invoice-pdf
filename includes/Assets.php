<?php
namespace WOI\PDF;

use WOI\PDF\EDI\Standards\EN16931;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WOI\\PDF\\Assets' ) ) :

class Assets {

	protected static $_instance = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct()	{
		add_action( 'admin_enqueue_scripts', array( $this, 'backend_scripts_styles' ) );
		add_filter( 'script_loader_tag', array( $this, 'edi_prism_add_data_manual_attr' ), 10, 3 );
	}

	/**
	 * Load styles & scripts
	 */
	public function backend_scripts_styles( $hook ) {
		$suffix        = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$pdfjs_version = '4.3.136';

		if ( WOI_PDF()->admin->is_order_page() ) {

			// STYLES
			if ( ! wp_style_is( 'thickbox', 'enqueue' ) ) {
				wp_enqueue_style( 'thickbox' );
			}

			wp_enqueue_style(
				'woi-pdf-order-styles',
				WOI_PDF()->plugin_url() . '/assets/css/admin.css',
				array(),
				WOI_PDF_VERSION
			);

			wp_enqueue_style(
				'woi-pdf-order-styles-buttons',
				WOI_PDF()->plugin_url() . '/assets/css/order-styles-buttons-wc39.css',
				array(),
				WOI_PDF_VERSION
			);

			// SCRIPTS
			wp_enqueue_script(
				'woi-pdf',
				WOI_PDF()->plugin_url() . '/assets/js/order-script.js',
				array(
					'jquery',
					wp_script_is( 'wc-jquery-blockui', 'registered' ) ? 'wc-jquery-blockui' : 'jquery-blockui',
				),
				WOI_PDF_VERSION,
				true
			);

			wp_localize_script(
				'woi-pdf',
				'woi_pdf_ajax',
				array(
					'ajaxurl'                      => admin_url( 'admin-ajax.php' ), // URL to WordPress ajax handling page
					'nonce'                        => wp_create_nonce( 'generate_woi_pdf' ),
					'bulk_actions'                 => array_keys( woi_pdf_get_bulk_actions() ),
					'select_orders'                => __( 'You have to select order(s) first!', 'woocommerce-orders-invoice-pdf' ),
					'confirm_delete'               => __( 'Are you sure you want to delete this document? This cannot be undone.', 'woocommerce-orders-invoice-pdf' ),
					'confirm_regenerate'           => __( 'Are you sure you want to regenerate this document? This will make the document reflect the most current settings (such as footer text, document name, etc.) rather than using historical settings.', 'woocommerce-orders-invoice-pdf' ),
					'sticky_document_data_metabox' => apply_filters( 'woi_pdf_sticky_document_data_metabox', true ),
					'error_loading_number_preview' => __( 'Error loading preview', 'woocommerce-orders-invoice-pdf' ),
					'error_fetching_refund_ids'    => __( 'Error fetching refund order IDs', 'woocommerce-orders-invoice-pdf' ),
					'error_no_refunds_found'       => __( 'No refunds found for this order', 'woocommerce-orders-invoice-pdf' ),
					'edi_metabox'                  => array(
						'show'  => __( 'Show', 'woocommerce-orders-invoice-pdf' ),
						'hide'  => __( 'Hide', 'woocommerce-orders-invoice-pdf' ),
						'saved' => __( 'Identifiers saved.', 'woocommerce-orders-invoice-pdf' ),
						'fail'  => __( 'Could not save identifiers. Please try again.', 'woocommerce-orders-invoice-pdf' ),
					),
				)
			);
		}

		// only load on our own settings page
		// maybe find a way to refer directly to WOI\PDF\Settings::$options_page_hook ?
		if ( ! empty( $hook ) && false !== strpos( $hook, 'woi_pdf_options_page' ) ) {
			$tab = filter_input( INPUT_GET, 'tab', FILTER_DEFAULT );
			$tab = sanitize_text_field( $tab );

			wp_enqueue_style(
				'woi-pdf-settings-styles',
				WOI_PDF()->plugin_url() . '/assets/css/settings-styles.min.css',
				array('woocommerce_admin_styles'),
				WOI_PDF_VERSION
			);
			wp_add_inline_style( 'woi-pdf-settings-styles', ".next-number-input.ajax-waiting {
				background-image: url(".WOI_PDF()->plugin_url().'/assets/images/spinner.gif'.") !important;
				background-position: 95% 50% !important;
				background-repeat: no-repeat !important;
			}" );
			wp_add_inline_style( 'woi-pdf-settings-styles', "#preview-order-search.ajax-waiting {
				background-image: url(".WOI_PDF()->plugin_url().'/assets/images/spinner.gif'.") !important;
				background-repeat: no-repeat !important;
				background-position: right 10px center !important;
			}" );
			wp_add_inline_style( 'woi-pdf-settings-styles', "#woi-pdf-preview-wrapper .slider.slide-left:after {
					content: '".__( 'Preview', 'woocommerce-orders-invoice-pdf' )."';
				}
				#woi-pdf-preview-wrapper .slider.slide-right:after {
					content: '".__( 'Settings', 'woocommerce-orders-invoice-pdf' )."';
			}" );
			if ( ! wp_script_is( 'wc-enhanced-select', 'enqueued' ) ) {
				wp_enqueue_script( 'wc-enhanced-select' );
			}

			if ( ! wp_script_is( 'wp-pointer', 'enqueued' ) ) {
				wp_enqueue_script( 'wp-pointer' );
			}

			$tiptip_handle = version_compare( WC_VERSION, '10.3', '>=' ) ? 'wc-jquery-tiptip' : 'jquery-tiptip';

			if ( ! wp_script_is( $tiptip_handle, 'enqueued' ) ) {
				wp_enqueue_script( $tiptip_handle );
			}

			$admin_deps = array(
				'jquery',
				'wc-enhanced-select',
				'wp-pointer',
				'jquery-ui-datepicker',
				wp_script_is( 'wc-jquery-blockui', 'registered' ) ? 'wc-jquery-blockui' : 'jquery-blockui',
				wp_script_is( 'wc-jquery-tiptip', 'registered' ) ? 'wc-jquery-tiptip' : 'jquery-tiptip',
			);

			// edi preview
			if ( woi_pdf_edi_preview_is_enabled() ) {
				wp_enqueue_style(
					'wpo-ips-edi-prism',
					WOI_PDF()->plugin_url() . '/assets/css/prism.min.css',
					array(),
					'1.30.0'
				);

				wp_enqueue_script(
					'wpo-ips-edi-prism-core',
					WOI_PDF()->plugin_url() . '/assets/js/prism.min.js',
					array(),
					'1.30.0',
					true
				);

				$admin_deps[] = 'wpo-ips-edi-prism-core';
			}

			wp_enqueue_script(
				'woi-pdf-admin',
				WOI_PDF()->plugin_url() . '/assets/js/admin.js',
				$admin_deps,
				WOI_PDF_VERSION,
				true
			);

			$search_index = $this->get_settings_search_index( $tab );

			wp_localize_script(
				'woi-pdf-admin',
				'woi_pdf_admin',
				array(
					'ajaxurl'                   => admin_url( 'admin-ajax.php' ),
					'search_index'              => $search_index,
					'nonce'                     => wp_create_nonce( 'woi_pdf_admin_nonce' ),
					'template_paths'            => WOI_PDF()->settings->get_installed_templates(),
					'pdfjs_worker'              => WOI_PDF()->plugin_url() . '/assets/js/pdf_js/pdf.worker.min.js?ver=' . $pdfjs_version, // taken from https://cdnjs.com/libraries/pdf.js
					'preview_excluded_settings' => apply_filters( 'woi_pdf_preview_excluded_settings', array(
						// general
						'download_display',
						'test_mode',
						'checkout_field_enable',
						'checkout_field_label',
						'checkout_field_as_vat_number',
						'checkout_field_enable_my_account',
						// document
						'enabled',
						'archive_pdf',
						'auto_generate_for_statuses',
						'attach_to_email_ids',
						'disable_for_statuses',
						'reset_number_yearly',
						'my_account_buttons',
						'invoice_number_column',
						'invoice_date_column',
						'disable_free',
						'use_latest_settings',
						'mark_printed',
						'unmark_printed',
						'include_email_link',
						'include_email_link_placement',
					) ),
					'pointers'                  => array(
						'woi_pdf_document_settings_sections' => array(
							'target'        => '.wcpdf_document_settings_sections',
							'content'       => sprintf(
								'<h3>%s</h3><p>%s</p>',
								__( 'Document settings', 'woocommerce-orders-invoice-pdf' ),
								__( 'Select a document in the dropdown menu above to edit its settings.', 'woocommerce-orders-invoice-pdf' )
							),
							'pointer_class' => 'wp-pointer arrow-top woi-pdf-pointer',
							'pointer_width' => 300,
							'position'      => array(
								'edge'  => 'top',
								'align' => 'left',
							),
						),
					),
					'dismissed_pointers'        => get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ),
					'mysql_int_size_limit'      => sprintf(
						/* translators: mysql int size */
						__( 'The number should be smaller than %s. Please note you should add your next document number without prefix, suffix or padding.', 'woocommerce-orders-invoice-pdf' ),
						'<code>2147483647</code>'
					),
					'shop_country_changed_messages' => array(
						'loading' => __( 'Loading', 'woocommerce-orders-invoice-pdf' ) . '...',
						'empty'   => __( 'No states available', 'woocommerce-orders-invoice-pdf' ),
						'error'   => __( 'Error loading', 'woocommerce-orders-invoice-pdf' ),
					),
					'xml_document_types'        => array_values( array_map(
						fn( $document ) => $document->get_type(),
						WOI_PDF()->documents->get_documents( 'enabled', 'xml' )
					) ),
				)
			);

			// preview PDFJS
			$debug_settings = get_option( 'woi_pdf_settings_debug', array() );
			if ( ! isset( $debug_settings['disable_preview'] ) ) {
				wp_enqueue_script(
					'woi-pdf-pdfjs',
					WOI_PDF()->plugin_url() . '/assets/js/pdf_js/pdf.min.js', // taken from https://cdnjs.com/libraries/pdf.js
					array(),
					$pdfjs_version,
					true
				);
			}

			wp_enqueue_media();

			wp_enqueue_script(
				'woi-pdf-media-upload',
				WOI_PDF()->plugin_url() . '/assets/js/media-upload.min.js',
				array( 'jquery' ),
				WOI_PDF_VERSION,
				true
			);

			// edi — only available when the EDI extension is installed
			if ( 'edi' === $tab && class_exists( 'WOI\\PDF\\EDI\\Standards\\EN16931' ) ) {
				wp_enqueue_script(
					'wpo-ips-edi',
					WOI_PDF()->plugin_url() . '/assets/js/edi-script' . $suffix . '.js',
					array( 'jquery', 'jquery-blockui' ),
					WOI_PDF_VERSION,
					true
				);

				wp_localize_script(
					'wpo-ips-edi',
					'woi_pdf_edi',
					array(
						'ajaxurl'                   => admin_url( 'admin-ajax.php' ),
						'nonce'                     => wp_create_nonce( 'woi_pdf_edi_nonce' ),
						'code'                      => __( 'Code', 'woocommerce-orders-invoice-pdf' ),
						'new'                       => __( 'New', 'woocommerce-orders-invoice-pdf' ),
						'unsaved'                   => __( 'unsaved', 'woocommerce-orders-invoice-pdf' ),
						'remarks'                   => EN16931::get_vatex_remarks(),
						'missing'                   => __( 'Missing', 'woocommerce-orders-invoice-pdf' ),
						'optional'                  => __( 'Optional', 'woocommerce-orders-invoice-pdf' ),
						'vat_warning'               => __( 'VAT number should start with a country prefix (e.g. NL123456789B01).', 'woocommerce-orders-invoice-pdf' ),
						'error_loading_identifiers' => __( 'Error loading identifiers', 'woocommerce-orders-invoice-pdf' ),
						'loading'                   => __( 'Loading...', 'woocommerce-orders-invoice-pdf' ),
						'valid_number'              => __( 'Order ID must be a valid number', 'woocommerce-orders-invoice-pdf' ),
						'enter_order_id'            => __( 'Please enter an Order ID.', 'woocommerce-orders-invoice-pdf' ),
						'no_identifiers_found'      => __( 'No customer identifiers found.', 'woocommerce-orders-invoice-pdf' ),
					)
				);
			}
		}

		if (
			$hook === 'woocommerce_page_wc-admin' &&
			WOI_PDF()->order_util->is_wc_admin_page()
		) {
			wp_enqueue_script(
				'woi-pdf-analytics-order',
				WOI_PDF()->plugin_url() . '/assets/js/analytics-order.js',
				array( 'wp-hooks' ),
				WOI_PDF_VERSION,
				true
			);

			wp_localize_script(
				'woi-pdf-analytics-order',
				'woi_pdf_analytics_order',
				array(
					'label' => __( 'Invoice Number', 'woocommerce-orders-invoice-pdf' ),
				)
			);
		}

	}

	/**
	 * Build the search index for the current settings tab.
	 *
	 * @param string $tab
	 *
	 * @return array
	 */
	private function get_settings_search_index( string $tab ): array {
		$page = '';

		switch ( $tab ) {
			case 'general':
			case '':
				$page = 'woi_pdf_settings_general';
				break;
			case 'documents':
				$section = filter_input( INPUT_GET, 'section', FILTER_DEFAULT );
				$section = ! empty( $section ) ? sanitize_text_field( $section ) : 'invoice';
				$page    = 'woi_pdf_documents_settings_' . $section;
				break;
			case 'debug':
				$section = filter_input( INPUT_GET, 'section', FILTER_DEFAULT );
				$section = ! empty( $section ) ? sanitize_text_field( $section ) : 'settings';
				if ( 'settings' === $section ) {
					$page = 'woi_pdf_settings_debug';
				}
				break;
		}

		if ( empty( $page ) ) {
			return array();
		}

		return WOI_PDF()->settings->get_search_index( $page );
	}

	/**
	 * Adds the `data-manual` attribute to Prism's <script> tag so that Prism
	 * stays in “manual” mode (i.e. it won't auto-highlight the entire page;
	 * you will call `Prism.highlightElement()` yourself).
	 *
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 *
	 * @return string
	 */
	public function edi_prism_add_data_manual_attr( string $tag, string $handle, string $src ): string {
		return ( $handle === 'wpo-ips-edi-prism-core' )
        	? str_replace( '<script ', '<script data-manual ', $tag )
        	: $tag;
	}

}

endif; // class_exists
