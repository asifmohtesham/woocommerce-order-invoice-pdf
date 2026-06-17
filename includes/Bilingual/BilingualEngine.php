<?php
namespace WOI\PDF\Bilingual;

if ( ! defined( 'ABSPATH' ) ) exit;

class BilingualEngine {

	protected static $instance = null;

	/** RTL language codes. */
	protected $rtl_languages = array( 'ar', 'he', 'fa', 'ur' );

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_enabled( $document ): bool {
		return ! empty( $document->get_setting( 'enable_second_language' ) );
	}

	public function secondary_language( $document ): string {
		$lang = $document->get_setting( 'second_language' );
		return ! empty( $lang ) ? (string) $lang : 'ar';
	}

	public function is_rtl( $document ): bool {
		$override = $document->get_setting( 'second_language_rtl' );
		if ( null !== $override ) {
			return ! empty( $override );
		}
		return in_array( $this->secondary_language( $document ), $this->rtl_languages, true );
	}

	public function dictionary( string $language = 'ar' ): array {
		$file = __DIR__ . '/dictionary/' . $language . '.php';
		$dict = is_readable( $file ) ? (array) include $file : array();
		return apply_filters( 'woi_pdf_second_language_dictionary', $dict, $language );
	}

	public function secondary_label( string $key, $document ): string {
		$overrides = (array) ( $document->get_setting( 'second_language_labels' ) ?: array() );
		if ( isset( $overrides[ $key ] ) && '' !== trim( (string) $overrides[ $key ] ) ) {
			$value = trim( (string) $overrides[ $key ] );
		} else {
			$dict  = $this->dictionary( $this->secondary_language( $document ) );
			$value = isset( $dict[ $key ] ) ? (string) $dict[ $key ] : '';
		}
		return (string) apply_filters( 'woi_pdf_second_language_label', $value, $key, $document );
	}
}
