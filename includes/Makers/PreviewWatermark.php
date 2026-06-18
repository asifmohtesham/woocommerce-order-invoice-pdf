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
 * woi_pdf_after_dompdf_render filter, so real (customer-facing) PDFs are
 * never affected. The preview render fires this hook as apply_filters() on its
 * own Dompdf instance (PDFMaker::output()); the real-PDF render path
 * (DocumentRenderer::render()) fires it as do_action() on a separate instance,
 * and register()/unregister() are bracketed by a try/finally in preview_pdf(),
 * so the two paths can never share the watermark.
 */
class PreviewWatermark {

	const HOOK = 'woi_pdf_after_dompdf_render';

	/**
	 * Attach the watermark to the post-render filter.
	 */
	public static function register(): void {
		add_filter( self::HOOK, array( self::class, 'stamp_after_render' ), 10, 4 );
	}

	/**
	 * Detach the watermark from the post-render filter.
	 */
	public static function unregister(): void {
		remove_filter( self::HOOK, array( self::class, 'stamp_after_render' ), 10 );
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
	 * Draw the watermark on every page of the rendered Dompdf document.
	 *
	 * Matches the woi_pdf_after_dompdf_render filter signature and returns the
	 * Dompdf object unchanged.
	 *
	 * @param object      $dompdf   The rendered Dompdf instance.
	 * @param string      $html     Source HTML (unused).
	 * @param object|null $options  Dompdf options (unused).
	 * @param object|null $document The order document (unused).
	 * @return object
	 */
	public static function stamp_after_render( $dompdf, $html = '', $options = null, $document = null ): object {
		if ( ! self::is_enabled() ) {
			return $dompdf;
		}

		$text   = self::get_text();
		$canvas = $dompdf->getCanvas();
		$fonts  = $dompdf->getFontMetrics();
		$font   = $fonts->getFont( 'DejaVu Sans', 'normal' );

		$size  = 72.0;
		$color = array( 0.8, 0.8, 0.8 ); // light gray; page_text has no true alpha
		$angle = 45.0;

		$width      = $canvas->get_width();
		$height     = $canvas->get_height();
		$text_width = $fonts->getTextWidth( $text, $font, $size );

		// Center the rotated text roughly on the page.
		$x = ( $width - ( $text_width * cos( deg2rad( $angle ) ) ) ) / 2;
		$y = ( $height + ( $text_width * sin( deg2rad( $angle ) ) ) ) / 2;

		// page_text() applies the text to EVERY page of the document.
		$canvas->page_text( $x, $y, $text, $font, $size, $color, 0.0, 0.0, $angle );

		return $dompdf;
	}
}

endif; // class_exists
