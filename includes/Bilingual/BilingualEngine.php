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
		if ( ! preg_match( '/^[a-z]{2,8}$/', $language ) ) {
			return array();
		}
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
		$general = (array) get_option( 'woi_pdf_settings_general' );
		return isset( $general['shop_name_ar'] ) ? trim( (string) $general['shop_name_ar'] ) : '';
	}

	public function secondary_shop_address(): string {
		$general = (array) get_option( 'woi_pdf_settings_general' );
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

	/**
	 * The lookup key a row uses to resolve its secondary (e.g. Arabic) label from
	 * the dictionary / global overrides. Most rows key by their column/total TYPE,
	 * but custom-field columns key by the field they show so a "Barcode"
	 * (product_custom → field_name "global_unique_id") can carry a translation the
	 * type-keyed dictionary doesn't know — and so two custom columns get distinct
	 * keys instead of colliding on "product_custom".
	 *
	 * @param array $row A column/total setting (or header row) array.
	 * @return string Lookup key, or '' when the row has no type.
	 */
	public function secondary_key( array $row ): string {
		$type = isset( $row['type'] ) ? (string) $row['type'] : '';
		if ( 'product_custom' === $type || 'item_meta' === $type ) {
			$field = isset( $row['field_name'] ) ? trim( (string) $row['field_name'] ) : '';
			return '' !== $field ? $field : $type;
		}
		if ( 'product_attribute' === $type ) {
			$attr = isset( $row['attribute_name'] ) ? trim( (string) $row['attribute_name'] ) : '';
			return '' !== $attr ? $attr : $type;
		}
		return $type;
	}

	/**
	 * Resolve a row's secondary label through the cascade: per-row `label_ar`
	 * override → global/dictionary lookup keyed by secondary_key(). Shared by the
	 * header and totals filters so columns and totals behave identically.
	 */
	protected function resolve_secondary( array $row, $document ): string {
		$per_row = isset( $row['label_ar'] ) ? trim( (string) $row['label_ar'] ) : '';
		if ( '' !== $per_row ) {
			return $per_row;
		}
		$key = $this->secondary_key( $row );
		return '' !== $key ? $this->secondary_label( $key, $document ) : '';
	}

	public function add_header_secondaries( array $headers, string $type, $document ): array {
		if ( ! $this->is_enabled( $document ) ) {
			return $headers;
		}
		foreach ( $headers as $key => $row ) {
			$secondary = $this->resolve_secondary( $row, $document );
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
			$secondary = $this->resolve_secondary( $row, $document );
			if ( '' !== $secondary ) {
				$totals[ $key ]['secondary'] = $secondary;
			}
		}
		return $totals;
	}

	/**
	 * Returns a key → English-label map for every dictionary key.
	 *
	 * This is the single canonical source for human-readable primary labels used
	 * in the settings UI.  Filterable so themes/plugins can rename entries.
	 *
	 * @return array<string,string>
	 */
	public function primary_labels(): array {
		$map = array(
			'document'          => __( 'Document title', 'woocommerce-orders-invoice-pdf' ),
			'document_number'   => __( 'Document number', 'woocommerce-orders-invoice-pdf' ),
			'document_date'     => __( 'Document date', 'woocommerce-orders-invoice-pdf' ),
			'document_due_date' => __( 'Due date', 'woocommerce-orders-invoice-pdf' ),
			'billing_address'   => __( 'Billing address', 'woocommerce-orders-invoice-pdf' ),
			'shipping_address'  => __( 'Shipping address', 'woocommerce-orders-invoice-pdf' ),
			'order_number'      => __( 'Order number', 'woocommerce-orders-invoice-pdf' ),
			'order_date'        => __( 'Order date', 'woocommerce-orders-invoice-pdf' ),
			'sku'               => __( 'SKU', 'woocommerce-orders-invoice-pdf' ),
			'description'       => __( 'Description', 'woocommerce-orders-invoice-pdf' ),
			'quantity'          => __( 'Quantity', 'woocommerce-orders-invoice-pdf' ),
			'uom'               => __( 'Unit of measure', 'woocommerce-orders-invoice-pdf' ),
			'price'             => __( 'Price', 'woocommerce-orders-invoice-pdf' ),
			'tax_rate'          => __( 'Tax rate', 'woocommerce-orders-invoice-pdf' ),
			'weight'            => __( 'Weight', 'woocommerce-orders-invoice-pdf' ),
			'subtotal'          => __( 'Subtotal', 'woocommerce-orders-invoice-pdf' ),
			'discount'          => __( 'Discount', 'woocommerce-orders-invoice-pdf' ),
			'shipping'          => __( 'Shipping', 'woocommerce-orders-invoice-pdf' ),
			'fees'              => __( 'Fees', 'woocommerce-orders-invoice-pdf' ),
			'vat'               => __( 'VAT', 'woocommerce-orders-invoice-pdf' ),
			'total'             => __( 'Total', 'woocommerce-orders-invoice-pdf' ),
		);
		return (array) apply_filters( 'woi_pdf_second_language_primary_labels', $map );
	}

	/** mPDF font-family keys we ship for the secondary (Arabic) script. */
	protected $secondary_fonts = array( 'xbriyaz', 'lateef' );

	/**
	 * The mPDF font-family used for secondary-language (Arabic) text.
	 *
	 * mPDF ships these fonts (XB Riyaz, Lateef) and shapes Arabic natively from
	 * their OpenType tables — no @font-face or synced TTFs required. The choice
	 * is a document setting (`second_language_font`), defaulting to XB Riyaz.
	 *
	 * @param object|null $document
	 * @return string
	 */
	public function font_family( $document = null ): string {
		$choice = '';
		if ( is_object( $document ) && is_callable( array( $document, 'get_setting' ) ) ) {
			$choice = strtolower( trim( (string) $document->get_setting( 'second_language_font' ) ) );
		}
		if ( ! in_array( $choice, $this->secondary_fonts, true ) ) {
			$choice = 'xbriyaz';
		}
		return (string) apply_filters( 'woi_pdf_second_language_font', $choice, $document );
	}

	public function font_css( $document ): string {
		if ( ! $this->is_enabled( $document ) ) {
			return '';
		}
		// mPDF resolves these font-family names against its bundled Arabic fonts
		// and shapes them natively, so no @font-face declaration is needed.
		$family = $this->font_family( $document );
		$dir    = $this->is_rtl( $document ) ? 'rtl' : 'ltr';
		$css    = ".woi-lbl-secondary { display: block; font-family: {$family}; direction: {$dir}; }\n";
		$css   .= ".woi-lbl-inline .woi-lbl-secondary { display: inline; }\n";
		$css   .= ".woi-bilingual-secondary { font-family: {$family}; direction: {$dir}; }\n";
		return $css;
	}
}
