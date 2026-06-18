<?php
namespace WOI\PDF\Makers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WOI\\PDF\\Makers\\MpdfMaker' ) ) :

/**
 * Shared mPDF rendering helper.
 *
 * mPDF replaced Dompdf as the engine because Dompdf has no Arabic shaper or
 * bidi support — mPDF shapes Arabic natively (using the font's OpenType tables),
 * so no pre-shaping pass is needed. Both render paths (PDFMaker for preview/main
 * PDFs and DocumentRenderer for email attachments) funnel through render() here
 * so configuration and hooks stay in one place.
 *
 * The engine is the Strauss-prefixed \WOI\PDF\Vendor\Mpdf\Mpdf; class_resolver()
 * falls back to the un-prefixed \Mpdf\Mpdf so the code also works in a dev/test
 * checkout where only the composer (un-prefixed) copy is installed.
 */
class MpdfMaker {

	/** Resolve the (prefixed or un-prefixed) mPDF class name. */
	public static function mpdf_class(): string {
		return class_exists( '\\WOI\\PDF\\Vendor\\Mpdf\\Mpdf' )
			? '\\WOI\\PDF\\Vendor\\Mpdf\\Mpdf'
			: '\\Mpdf\\Mpdf';
	}

	/** Resolve the matching Output\Destination class name. */
	public static function destination_class(): string {
		return class_exists( '\\WOI\\PDF\\Vendor\\Mpdf\\Output\\Destination' )
			? '\\WOI\\PDF\\Vendor\\Mpdf\\Output\\Destination'
			: '\\Mpdf\\Output\\Destination';
	}

	/**
	 * Build a configured mPDF instance.
	 *
	 * @param array       $settings Maker settings (paper_size, paper_orientation, …).
	 * @param object|null $document The order document, for filter context.
	 * @return object The mPDF instance.
	 */
	public static function create( array $settings, ?object $document = null ): object {
		$class       = self::mpdf_class();
		$orientation = ( isset( $settings['paper_orientation'] ) && 'landscape' === $settings['paper_orientation'] ) ? 'L' : 'P';

		$config = apply_filters( 'woi_pdf_mpdf_config', array(
			'mode'             => 'utf-8',
			'format'           => $settings['paper_size'] ?? 'A4',
			'orientation'      => $orientation,
			'tempDir'          => WOI_PDF()->main->get_tmp_path( 'mpdf' ),
			// Native Arabic/RTL shaping: pick the right script's font automatically
			// and shape via the font's OpenType layout tables.
			'autoScriptToLang' => true,
			'autoLangToFont'   => true,
		), $document, $settings );

		return new $class( $config );
	}

	/**
	 * Render an HTML document to a raw PDF string.
	 *
	 * @param string      $html     Full HTML document.
	 * @param array       $settings Maker settings.
	 * @param object|null $document The order document, for hook context.
	 * @return string Raw PDF binary (empty string when $html is empty).
	 */
	public static function render( string $html, array $settings = array(), ?object $document = null ): string {
		if ( '' === $html ) {
			return '';
		}

		$mpdf = self::create( $settings, $document );

		// before: lets the preview watermark call SetWatermarkText() prior to layout.
		$mpdf = apply_filters( 'woi_pdf_before_mpdf_render', $mpdf, $html, $document );

		$mpdf->WriteHTML( $html );

		$mpdf = apply_filters( 'woi_pdf_after_mpdf_render', $mpdf, $html, $document );

		$destination = self::destination_class();
		return (string) $mpdf->Output( '', $destination::STRING_RETURN );
	}
}

endif; // class_exists
