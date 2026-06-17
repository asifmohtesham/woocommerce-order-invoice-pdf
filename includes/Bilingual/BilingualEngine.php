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

	public function secondary_shop_name(): string {
		$general = (array) get_option( 'woi_pdf_general_settings' );
		return isset( $general['shop_name_ar'] ) ? trim( (string) $general['shop_name_ar'] ) : '';
	}

	public function secondary_shop_address(): string {
		$general = (array) get_option( 'woi_pdf_general_settings' );
		return isset( $general['shop_address_ar'] ) ? trim( (string) $general['shop_address_ar'] ) : '';
	}

	public function localized_location( string $value, string $type, $order ): string {
		$code = ( 'state' === $type ) ? $order->get_billing_state() : $order->get_billing_country();
		if ( empty( $code ) ) {
			return $value;
		}
		$switched = switch_to_locale( 'ar' );
		if ( 'state' === $type ) {
			$states = WC()->countries->get_states( $order->get_billing_country() );
			$name   = $states[ $code ] ?? '';
		} else {
			$countries = WC()->countries->get_countries();
			$name      = $countries[ $code ] ?? '';
		}
		if ( $switched ) {
			restore_previous_locale();
		}
		return '' !== $name ? $name : $value;
	}

	public function add_header_secondaries( array $headers, string $type, $document ): array {
		if ( ! $this->is_enabled( $document ) ) {
			return $headers;
		}
		foreach ( $headers as $key => $row ) {
			$col_type  = $row['type'] ?? '';
			$secondary = '' !== $col_type ? $this->secondary_label( $col_type, $document ) : '';
			if ( '' !== $secondary ) {
				$headers[ $key ]['secondary'] = $secondary;
			}
		}
		return $headers;
	}

	public function add_totals_secondaries( array $totals, string $type, $document ): array {
		if ( ! $this->is_enabled( $document ) ) {
			return $totals;
		}
		foreach ( $totals as $key => $row ) {
			$total_type = $row['type'] ?? '';
			$secondary  = '' !== $total_type ? $this->secondary_label( $total_type, $document ) : '';
			if ( '' !== $secondary ) {
				$totals[ $key ]['secondary'] = $secondary;
			}
		}
		return $totals;
	}

	public function font_family(): string {
		return 'Noto Naskh Arabic';
	}

	public function font_css( $document ): string {
		if ( ! $this->is_enabled( $document ) ) {
			return '';
		}
		// Dompdf resolves font-family names against its synced font dir by family
		// name; the bundled NotoNaskhArabic TTFs are copied there by FontSynchronizer.
		$family = $this->font_family();
		$dir    = $this->is_rtl( $document ) ? 'rtl' : 'ltr';
		$css    = "@font-face { font-family: '{$family}'; font-style: normal; font-weight: normal; src: url('NotoNaskhArabic-Regular.ttf'); }\n";
		$css   .= "@font-face { font-family: '{$family}'; font-style: normal; font-weight: bold; src: url('NotoNaskhArabic-Bold.ttf'); }\n";
		$css   .= ".woi-lbl-secondary { display: block; font-family: '{$family}'; direction: {$dir}; }\n";
		$css   .= ".woi-lbl-inline .woi-lbl-secondary { display: inline; }\n";
		$css   .= ".woi-bilingual-secondary { font-family: '{$family}'; direction: {$dir}; }\n";
		return $css;
	}
}
