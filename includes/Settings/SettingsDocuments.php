<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Settings\\SettingsDocuments' ) ) :

class SettingsDocuments {

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'woi_pdf_settings_output_documents', array( $this, 'output' ), 10, 2 );
	}

	public function output( string $section, string $nonce ): void {
		if ( ! wp_verify_nonce( $nonce, 'wp_woi_pdf_settings_page_nonce' ) ) {
			return;
		}

		$section   = ! empty( $section ) ? sanitize_key( $section ) : 'invoice';
		$documents = \WOI_PDF()->documents->get_documents( 'all' );

		$active = null;
		foreach ( $documents as $doc ) {
			if ( $doc->get_type() === $section ) {
				$active = $doc;
				break;
			}
		}
		if ( empty( $active ) ) {
			$active = reset( $documents );
			if ( ! $active ) {
				return;
			}
			$section = $active->get_type();
		}

		$option_name = 'woi_pdf_documents_settings_' . $section;

		settings_fields( $option_name );
		do_settings_sections( $option_name );
		submit_button();
	}
}

endif;
