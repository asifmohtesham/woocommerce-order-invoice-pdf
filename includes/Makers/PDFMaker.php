<?php
namespace WOI\PDF\Makers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WOI\\PDF\\Makers\\PDFMaker' ) ) :

/**
 * Primary PDF maker (preview, bulk and main document PDFs).
 *
 * Renders via mPDF (see MpdfMaker) for native Arabic shaping + bidi. Previously
 * used Dompdf with a presentation-form pre-shaper; mPDF shapes natively from the
 * font's OpenType tables, so that workaround is gone.
 */
class PDFMaker {

	public string $html;
	public array $settings;
	public ?object $document;

	public function __construct( string $html, array $settings = array(), ?object $document = null ) {
		$this->html     = $html;
		$this->document = $document;

		$default_settings = array(
			'paper_size'        => 'A4',
			'paper_orientation' => 'portrait',
			'font_subsetting'   => false,
		);
		$this->settings = $settings + $default_settings;
	}

	public function output(): ?string {
		if ( empty( $this->html ) ) {
			return null;
		}

		return MpdfMaker::render( $this->html, $this->settings, $this->document );
	}
}

endif; // class_exists
