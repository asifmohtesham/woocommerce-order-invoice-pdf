<?php
namespace WOI\PDF\Makers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WOI\\PDF\\Makers\\PreviewWatermark' ) ) :

/**
 * Stamps a "SAMPLE" watermark onto the preview PDF.
 *
 * Registered only during OrderDocument::preview_pdf() via the
 * woi_pdf_before_mpdf_render filter, so real (customer-facing) PDFs are never
 * affected. register()/unregister() are bracketed by a try/finally in
 * preview_pdf(), so the watermark can never leak onto a real PDF.
 *
 * Uses mPDF's native SetWatermarkText (semi-transparent, drawn behind the page
 * content), which replaces the old Dompdf post-render canvas hack.
 */
class PreviewWatermark {

	const HOOK = 'woi_pdf_before_mpdf_render';

	/**
	 * Attach the watermark to the pre-render filter.
	 */
	public static function register(): void {
		add_filter( self::HOOK, array( self::class, 'stamp_before_render' ), 10, 3 );
	}

	/**
	 * Detach the watermark from the pre-render filter.
	 */
	public static function unregister(): void {
		remove_filter( self::HOOK, array( self::class, 'stamp_before_render' ), 10 );
	}

	/**
	 * Whether the watermark should be drawn.
	 */
	public static function is_enabled(): bool {
		return (bool) apply_filters( 'woi_pdf_preview_watermark_enabled', true );
	}

	/**
	 * The watermark text.
	 */
	public static function get_text(): string {
		return (string) apply_filters( 'woi_pdf_preview_watermark_text', 'SAMPLE' );
	}

	/**
	 * Enable mPDF's watermark text on the instance before layout.
	 *
	 * Matches the woi_pdf_before_mpdf_render filter signature and returns the
	 * mPDF object unchanged (configuration is applied as side effects).
	 *
	 * @param object      $mpdf     The mPDF instance.
	 * @param string      $html     Source HTML (unused).
	 * @param object|null $document The order document (unused).
	 * @return object
	 */
	public static function stamp_before_render( $mpdf, $html = '', $document = null ): object {
		if ( ! self::is_enabled() ) {
			return $mpdf;
		}

		$alpha = (float) apply_filters( 'woi_pdf_preview_watermark_opacity', 0.1 );

		$mpdf->SetWatermarkText( self::get_text() );
		$mpdf->showWatermarkText = true;
		$mpdf->watermarkTextAlpha = $alpha;

		return $mpdf;
	}
}

endif; // class_exists
