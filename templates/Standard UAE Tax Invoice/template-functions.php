<?php
/**
 * Standard UAE Tax Invoice — preset template functions.
 *
 * Pre-wires the bilingual engine ON with UAE/Arabic defaults so the user gets
 * bilingual output in one click after selecting this template.
 *
 * Load-timing note: this file is included via Main::load_template_functions()
 * which resolves to the ACTIVE template only (via get_template_path() with no
 * argument falls back to the saved general_settings['template_path']). There is
 * therefore no need for an extra active-template guard here — the callbacks
 * below are only registered when this template is selected.
 *
 * Requires PDF Invoices & Packing Slips for WooCommerce 1.4.13 or higher.
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// ---------------------------------------------------------------------------
// 1. Document-settings injection — bilingual defaults
// ---------------------------------------------------------------------------

add_filter( 'woi_pdf_document_settings', 'woi_pdf_standard_uae_inject_bilingual_defaults', 9, 4 );

/**
 * Merge bilingual defaults into the document settings for the UAE preset.
 *
 * Only fills keys that are absent from $settings (array_key_exists check so a
 * deliberately-empty/zero user value is respected). The filter is registered
 * with priority 9, before any user-land callbacks, so user overrides at p≥10
 * can still win.
 *
 * @param array         $settings      Merged document settings.
 * @param object        $document      The OrderDocument instance.
 * @param bool          $latest        Whether these are latest (non-historical) settings.
 * @param string        $output_format Output format ('pdf', 'html', …).
 * @return array
 */
function woi_pdf_standard_uae_inject_bilingual_defaults( $settings, $document, $latest, $output_format ) {
	if ( class_exists( '\WOI\PDF\Bilingual\BilingualEngine' ) ) {
		$dict = \WOI\PDF\Bilingual\BilingualEngine::instance()->dictionary( 'ar' );
	} else {
		$dict = array();
	}

	$defaults = array(
		'enable_second_language'  => 1,
		'second_language'         => 'ar',
		'second_language_rtl'     => 1,
		'second_language_font'    => 'xbriyaz', // mPDF-bundled Arabic font (XB Riyaz); 'lateef' also available
		'document_title'          => __( 'Tax Invoice', 'woocommerce-orders-invoice-pdf' ),
		'second_language_labels'  => $dict,
	);

	foreach ( $defaults as $key => $value ) {
		if ( ! array_key_exists( $key, $settings ) ) {
			$settings[ $key ] = $value;
		}
	}

	return $settings;
}

// ---------------------------------------------------------------------------
// 1b. Document title — keep the English title in step with the Arabic
// ---------------------------------------------------------------------------

add_filter( 'woi_pdf_document_title', 'woi_pdf_standard_uae_document_title', 10, 2 );

/**
 * Force the English document title to "Tax Invoice" when the merchant has not
 * set a custom one.
 *
 * The Arabic secondary label is the dictionary value "فاتورة ضريبية" (Tax
 * Invoice). Without this, orders that have no saved `document_title` fall back
 * to the bare "Invoice" default, leaving the English and Arabic titles saying
 * different things. Filtering at render time fixes historical orders too — the
 * settings-injection default in (1) only covers freshly saved settings.
 *
 * A merchant-supplied custom title is left untouched.
 *
 * @param string $title    Resolved title.
 * @param object $document The OrderDocument instance.
 * @return string
 */
function woi_pdf_standard_uae_document_title( $title, $document ) {
	$custom = '';
	if ( is_object( $document ) && is_callable( array( $document, 'get_settings_text' ) ) ) {
		$custom = trim( (string) $document->get_settings_text( 'document_title', '', false ) );
	}
	if ( '' === $custom ) {
		return __( 'Tax Invoice', 'woocommerce-orders-invoice-pdf' );
	}
	return $title;
}

// ---------------------------------------------------------------------------
// 2. Editor (Customiser) defaults — columns and totals
// ---------------------------------------------------------------------------

add_filter( 'woi_pdf_template_editor_defaults', 'woi_pdf_standard_uae_editor_defaults', 9, 3 );
add_filter( 'woi_pdf_template_editor_settings', 'woi_pdf_standard_uae_editor_defaults', 9, 3 );

/**
 * Seed Customiser column and totals defaults for the UAE preset.
 *
 * These filters are called by EditorSettings::get_settings() with
 * $settings_name equal to 'columns' or 'totals' only (the drag-drop editor
 * sections). Bilingual keys are NOT routed through these filters, hence they
 * are handled via woi_pdf_document_settings above.
 *
 * @param mixed  $settings       Current setting value.
 * @param string $document_type  Document type slug.
 * @param string $settings_name  Setting key name.
 * @return mixed
 */
function woi_pdf_standard_uae_editor_defaults( $settings, $document_type, $settings_name ) {
	$editor_settings = get_option( 'woi_pdf_editor_settings' );

	if ( isset( $editor_settings['settings_saved'] ) && ! isset( $_GET['load-defaults'] ) ) {
		return $settings;
	}

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
