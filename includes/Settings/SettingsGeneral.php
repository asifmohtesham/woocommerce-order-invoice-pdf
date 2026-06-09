<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Settings\\SettingsGeneral' ) ) :

class SettingsGeneral {

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
		return apply_filters( 'woi_pdf_general_settings_categories', array() );
	}

	public function init_settings(): void {
		$page = $option_group = $option_name = 'woi_pdf_settings_general';

		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'general',
				'title'    => '',
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'download_display',
				'title'    => __( 'How to view the PDF?', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'select',
				'section'  => 'general',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'download_display',
					'options'     => array(
						'download' => __( 'Download PDF', 'woocommerce-orders-invoice-pdf' ),
						'display'  => __( 'Open PDF in browser', 'woocommerce-orders-invoice-pdf' ),
					),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'template_path',
				'title'    => __( 'Base template', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'select',
				'section'  => 'general',
				'args'     => array(
					'option_name'      => $option_name,
					'id'               => 'template_path',
					'options_callback' => array( WOI_PDF()->settings, 'get_installed_templates_list' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'font_subsetting',
				'title'    => __( 'Use font subsetting', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'checkbox',
				'section'  => 'general',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'font_subsetting',
					'description' => __( 'Reduces PDF file size by only embedding used characters. Disable if you see garbled characters.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'header_logo',
				'title'    => __( 'Shop header/logo', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'media_upload',
				'section'  => 'general',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'header_logo',
					'height_id'   => 'header_logo_height',
					'description' => __( 'Upload your shop logo. Used as header image in the PDF.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'section',
				'id'       => 'general_shop_details',
				'title'    => __( 'Shop details', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_name',
				'title'    => __( 'Shop name', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_name',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_address_line_1',
				'title'    => __( 'Address line 1', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_address_line_1',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_address_line_2',
				'title'    => __( 'Address line 2', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_address_line_2',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_address_city',
				'title'    => __( 'City', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_address_city',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_address_state',
				'title'    => __( 'State', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_address_state',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_address_postcode',
				'title'    => __( 'Postcode', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_address_postcode',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_address_country',
				'title'    => __( 'Country', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_address_country',
					'description' => __( 'Two-letter country code (e.g. US, DE, AE).', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_address_additional',
				'title'    => __( 'Additional address info', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'textarea_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_address_additional',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_phone_number',
				'title'    => __( 'Phone number', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_phone_number',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_email_address',
				'title'    => __( 'Email address', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'shop_email_address',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'vat_number',
				'title'    => __( 'VAT number', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'vat_number',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'coc_number',
				'title'    => __( 'CoC number', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => 'general_shop_details',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'coc_number',
				),
			),
			array(
				'type'     => 'section',
				'id'       => 'general_extra',
				'title'    => __( 'Extra template fields', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'footer',
				'title'    => __( 'Footer', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'textarea_element',
				'section'  => 'general_extra',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'footer',
					'description' => __( 'Footer text for the invoice PDF.', 'woocommerce-orders-invoice-pdf' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'extra_1',
				'title'    => __( 'Extra field 1', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'textarea_element',
				'section'  => 'general_extra',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'extra_1',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'extra_2',
				'title'    => __( 'Extra field 2', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'textarea_element',
				'section'  => 'general_extra',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'extra_2',
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'extra_3',
				'title'    => __( 'Extra field 3', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'textarea_element',
				'section'  => 'general_extra',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'extra_3',
				),
			),
		);

		$settings_fields = apply_filters( 'woi_pdf_settings_fields_general', $settings_fields, $page, $option_group, $option_name, $this );

		\WOI_PDF()->settings->add_settings_fields( $settings_fields, $page, $option_group, $option_name );
	}
}

endif;
