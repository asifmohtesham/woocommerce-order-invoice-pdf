<?php
/**
 * Standard UAE Tax Invoice — preset template functions.
 *
 * Pre-wires the bilingual engine ON with UAE/Arabic defaults so the user gets
 * bilingual output in one click after selecting this template.
 *
 * NOTE on the defaults mechanism:
 * The `woi_pdf_template_editor_defaults` and `woi_pdf_template_editor_settings`
 * filters are called by EditorSettings::get_settings() with $settings_name equal
 * to 'columns' or 'totals' only (the drag-drop Customiser editor sections).
 * Bilingual settings (enable_second_language, second_language, etc.) are stored
 * as separate WP options (woi_pdf_documents_settings_{document_type}) and are NOT
 * routed through these filters. The switch cases below for bilingual keys are
 * therefore included for intent documentation; they are no-ops unless the filter
 * is later extended to cover those setting names.
 *
 * Requires PDF Invoices & Packing Slips for WooCommerce 1.4.13 or higher.
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

add_filter( 'woi_pdf_template_editor_defaults', 'woi_pdf_standard_uae_template_defaults', 9, 3 );
add_filter( 'woi_pdf_template_editor_settings', 'woi_pdf_standard_uae_template_defaults', 9, 3 );
function woi_pdf_standard_uae_template_defaults( $settings, $document_type, $settings_name ) {
	$editor_settings = get_option( 'woi_pdf_editor_settings' );

	if ( isset( $editor_settings['settings_saved'] ) && ! isset( $_GET['load-defaults'] ) ) {
		return $settings;
	}

	// Seed bilingual engine defaults for the UAE preset.
	// These cases are intent-documenting; see file header for why they are
	// currently no-ops (the filter is only called with 'columns'/'totals').
	if ( class_exists( '\WOI\PDF\Bilingual\BilingualEngine' ) ) {
		$dict = \WOI\PDF\Bilingual\BilingualEngine::instance()->dictionary( 'ar' );
	} else {
		$dict = array();
	}

	switch ( $settings_name ) {
		case 'enable_second_language':
			$settings = 1; break;
		case 'second_language':
			$settings = 'ar'; break;
		case 'second_language_rtl':
			$settings = 1; break;
		case 'document_title':
			$settings = __( 'Tax Invoice', 'woocommerce-orders-invoice-pdf' ); break;
		case 'second_language_labels':
			$settings = $dict; break;
	}

	// Columns and totals defaults — UAE-specific column set is a later sub-project.
	// For now these mirror the Business template defaults.
	if ( in_array( $document_type, array( 'packing-slip', 'delivery-note' ) ) ) {
		switch ( $settings_name ) {
			case 'columns':
				$settings = array(
					1 => array(
						'type'      => 'sku',
					),
					2 => array(
						'type'      => 'description',
						'show_meta' => 1,
					),
					3 => array(
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
				$settings = array(
					1 => array(
						'type'       => 'sku',
					),
					2 => array(
						'type'       => 'description',
						'show_meta'  => 1,
					),
					3 => array(
						'type'       => 'quantity',
					),
					4 => array(
						'type'       => 'price',
						'price_type' => 'single',
						'tax'        => 'excl',
						'discount'   => 'before',
					),
					5 => array(
						'type'       => 'tax_rate',
					),
					6 => array(
						'type'       => 'price',
						'price_type' => 'total',
						'tax'        => 'excl',
						'discount'   => 'before',
					),
				);
				break;
			case 'totals':
				$settings = array(
					1 => array(
						'type'     => 'subtotal',
						'tax'      => 'excl',
						'discount' => 'before',
					),
					2 => array(
						'type'     => 'discount',
						'tax'      => 'excl',
					),
					3 => array(
						'type'     => 'shipping',
						'tax'      => 'excl',
					),
					4 => array(
						'type'     => 'fees',
						'tax'      => 'excl',
					),
					5 => array(
						'type'     => 'vat',
					),
					6 => array(
						'type'     => 'total',
						'tax'      => 'incl',
					),
				);
				break;
		}
	}

	return $settings;
}
