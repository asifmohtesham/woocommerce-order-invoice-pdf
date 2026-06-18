<?php
namespace WOI\PDF\Makers;

use WOI\PDF\Vendor\Dompdf\Dompdf;
use WOI\PDF\Vendor\Dompdf\Options;
use WOI\PDF\Bilingual\ArabicShaper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WOI\\PDF\\Makers\\PDFMaker' ) ) :

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

		$options = new Options( apply_filters( 'woi_pdf_dompdf_options', array(
			'tempDir'                 => WOI_PDF()->main->get_tmp_path( 'dompdf' ),
			'fontDir'                 => WOI_PDF()->main->get_tmp_path( 'fonts' ),
			'fontCache'               => WOI_PDF()->main->get_tmp_path( 'fonts' ),
			'chroot'                  => $this->get_chroot_paths(),
			'logOutputFile'           => WOI_PDF()->main->get_tmp_path( 'dompdf' ) . '/log.htm',
			'defaultFont'             => 'dejavu sans',
			'isRemoteEnabled'         => true,
			'isHtml5ParserEnabled'    => true,
			'isFontSubsettingEnabled' => (bool) $this->settings['font_subsetting'],
		) ) );

		if ( isset( WOI_PDF()->settings->debug_settings['enable_debug'] ) ) {
			$this->set_additional_debug_options( $options );
		}

		// Dompdf has no Arabic shaper/bidi engine: pre-shape Arabic runs into
		// presentation forms and visual order before handing the HTML over.
		// PDF-only path — HTML/email output is shaped by the browser instead.
		$html = class_exists( '\\WOI\\PDF\\Bilingual\\ArabicShaper' )
			? ArabicShaper::shape_html( $this->html )
			: $this->html;

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( $this->settings['paper_size'], $this->settings['paper_orientation'] );
		$dompdf = apply_filters( 'woi_pdf_before_dompdf_render', $dompdf, $this->html, $options, $this->document );
		$dompdf->render();
		$dompdf = apply_filters( 'woi_pdf_after_dompdf_render', $dompdf, $this->html, $options, $this->document );

		return $dompdf->output();
	}

	private function get_chroot_paths(): array {
		$chroot         = array( WP_CONTENT_DIR );
		$wp_upload_base = WOI_PDF()->main->get_wp_upload_base();
		$tmp_base       = WOI_PDF()->main->get_tmp_base();

		if ( ! empty( $wp_upload_base ) ) {
			$chroot[] = $wp_upload_base;
		}

		if ( ! empty( $tmp_base ) ) {
			$chroot[] = $tmp_base;
		}

		return apply_filters( 'woi_pdf_dompdf_chroot', $chroot );
	}

	private function set_additional_debug_options( Options $options ): void {
		$dompdf_debug_options = apply_filters( 'woi_pdf_dompdf_additional_debug_options', array(
			'debugPng',
			'debugCss',
			'debugLayout',
		) );

		foreach ( $dompdf_debug_options as $option ) {
			$options->set( $option, true );
		}
	}

}

endif; // class_exists
