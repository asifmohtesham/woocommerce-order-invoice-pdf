<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Settings\\SettingsDebug' ) ) :

class SettingsDebug {

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'admin_init', array( $this, 'init_settings' ) );
	}

	public function get_settings_categories(): array {
		return apply_filters( 'woi_pdf_debug_settings_categories', array() );
	}

	public function init_settings(): void {
		$page = $option_group = $option_name = 'woi_pdf_settings_debug';

		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'debug',
				'title'    => '',
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'html_output',
				'title'    => __( 'Output HTML', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'html_output',
					'description' => __( 'Output HTML instead of PDF. Useful for template development.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'disable_preview',
				'title'    => __( 'Disable preview', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'disable_preview',
					'description' => __( 'Disable the PDF preview on the settings page.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'enable_debug',
				'title'    => __( 'Enable debug mode', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'enable_debug',
					'description' => __( 'Log debug messages to the WooCommerce log.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'calculate_document_numbers',
				'title'    => __( 'Calculate document numbers', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'calculate_document_numbers',
					'description' => __( 'Use calculation method instead of auto-increment for document numbers. Use if document numbers are skipping values.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'pretty_document_links',
				'title'    => __( 'Pretty document links', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'pretty_document_links',
					'description' => __( 'Use pretty permalinks for document download links.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'document_link_access_type',
				'title'    => __( 'Document access', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'select',
				'section'  => 'debug',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'document_link_access_type',
					'default'     => 'logged_in',
					'options'     => array(
						'logged_in' => __( 'Logged in (recommended)', 'woocommerce-orders-invoice-pdf' ),
						'full'      => __( 'Full — anyone with the order key in the link', 'woocommerce-orders-invoice-pdf' ),
					),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'enable_rest_api',
				'title'    => __( 'Enable REST API', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'enable_rest_api',
					'description' => __( 'Enable the REST API endpoints for document management.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'enable_document_data_editing',
				'title'    => __( 'Enable document data editing', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'enable_document_data_editing',
					'description' => __( 'Allow editing of document dates and numbers in the order admin panel.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'use_html5_parser',
				'title'    => __( 'Use HTML5 parser', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'use_html5_parser',
					'description' => __( 'Use the HTML5 parser for PDF generation. May improve compatibility with modern HTML.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'section',
				'id'       => 'debug_cleanup',
				'title'    => __( 'Cleanup', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'enable_cleanup',
				'title'    => __( 'Enable cleanup', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug_cleanup',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'enable_cleanup',
					'description' => __( 'Periodically remove old temporary PDF files.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'cleanup_days',
				'title'    => __( 'Remove files after (days)', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'debug_cleanup',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'cleanup_days',
					'default'     => 7,
				),
			),
			array(
				'type'     => 'section',
				'id'       => 'debug_danger',
				'title'    => __( 'Danger zone', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'enable_danger_zone_tools',
				'title'    => __( 'Enable danger zone tools', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'debug_danger',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'enable_danger_zone_tools',
					'description' => __( 'Enables bulk delete and other destructive tools. Use with caution.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
		);

		$settings_fields = apply_filters( 'woi_pdf_settings_fields_debug', $settings_fields, $page, $option_group, $option_name );

		\WOI_PDF()->settings->add_settings_fields( $settings_fields, $page, $option_group, $option_name );
	}
}

endif;
