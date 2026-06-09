<?php
/**
 * Use this file for all your template filters and actions.
 * Requires PDF Invoices & Packing Slips for WooCommerce 1.4.13 or higher
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

add_filter( 'woi_pdf_template_editor_defaults', 'woi_pdf_modern_template_defaults', 9, 3 );
add_filter( 'woi_pdf_template_editor_settings', 'woi_pdf_modern_template_defaults', 9, 3 );
function woi_pdf_modern_template_defaults ( $settings, $document_type, $settings_name ) {
	$editor_settings = get_option( 'woi_pdf_editor_settings' );

	if ( isset( $editor_settings['settings_saved'] ) && ! isset( $_GET['load-defaults'] ) ) {
		return $settings;
	}

	// only packing slip and delivery note are different
	if ( in_array( $document_type, array( 'packing-slip', 'delivery-note' ) ) ) {
		switch ($settings_name) {
			case 'columns':
				$settings = array (
					1 => array (
						'type'      => 'thumbnail',
					),
					2 => array (
						'type'      => 'sku',
					),
					3 => array (
						'type'      => 'description',
						'show_meta' => 1,
					),
					4 => array (
						'type'      => 'quantity',
					),
				);
				break;
			case 'totals':
				$settings = array();
				break;
		}
	} else {
		switch ( $settings_name ) {
			case 'columns':
				$settings = array (
					1 => array (
						'type'       => 'thumbnail',
					),
					2 => array (
						'type'       => 'sku',
					),
					3 => array (
						'type'       => 'description',
						'show_meta'  => 1,
					),
					4 => array (
						'type'       => 'quantity',
					),
					5 => array (
						'type'       => 'price',
						'price_type' => 'single',
						'tax'        => 'incl',
						'discount'   => 'before',
					),
					6 => array (
						'type'       => 'price',
						'price_type' => 'total',
						'tax'        => 'incl',
						'discount'   => 'before',
					),
				);
				break;
			case 'totals':
				$settings = array(
					1 => array (
						'type'     => 'subtotal',
						'tax'      => 'incl',
						'discount' => 'before',
					),
					2 => array (
						'type'     => 'discount',
						'tax'      => 'incl',
					),
					3 => array (
						'type'     => 'shipping',
						'tax'      => 'incl',
					),
					4 => array (
						'type'     => 'fees',
						'tax'      => 'incl',
					),
					5 => array (
						'type'     => 'total',
						'tax'      => 'incl',
					),
					6 => array (
						'type'     => 'total',
						'tax'      => 'excl',
					),					
					7 => array (
						'type'     => 'vat',
					),
				);
				break;
		}
	}

	return $settings;
}

add_filter( 'woi_pdf_settings_fields_general', function( $settings_fields, $page, $option_group, $option_name ) {
	$settings_fields[] = array(
		'type'		=> 'setting',
		'id'		=> 'modern_color',
		'title'		=> __( 'Theme color', 'woi_pdf_templates' ),
		'callback'	=> 'text_input',
		'section'	=> 'general_settings',
		'args'		=> array(
			'option_name'	=> $option_name,
			'id'			=> 'modern_color',
			'size'			=> '5',
			'type'			=> 'color',
			'default'		=> '#2F62AF',
		)
	);
	return $settings_fields;
}, 10, 4 );

add_filter( 'woi_pdf_template_styles', function( $css, $document){
	if ( !empty( WOI_PDF()->settings->general_settings['modern_color'] ) ) {
		$color = WOI_PDF()->settings->general_settings['modern_color'];
		$css = str_replace( '#2F62AF', $color, $css );
		// determine foreground color
		if ( $hex = str_replace( '#', '', $color ) ) {
			if ( strlen($hex) == 6 ) {
				$hex_parts = str_split( $hex, 2 );
			} elseif ( strlen($hex) == 3 ) {
				$hex_parts = str_split( $hex, 1 );
				$hex_parts = array_map( function($hex){ return str_repeat( $hex, 2 ); }, $hex_parts );
			} else {
				$hex_parts = array_fill(0, 3, 0);
			}
			$rgb = array_map( function($hex){ return hexdec( $hex ); }, $hex_parts );
			$brightness = (0.299*$rgb[0] + 0.587*$rgb[1] + 0.114*$rgb[2]);
			// set to font to black if brightness higher than 50%
			if ( $brightness > 127 ) {
				$css .= "\ntable.totals .grand-total td, table.totals .grand-total th, .bluebox { color: black; }";
				// prevent unreadable font colors for texts with the theme color
				$css .= "\n.recipient-address, .document-type-label, table.order-data-addresses .address { color: black; }";
			}
		}

	}
	return $css;
}, 9, 2 );

