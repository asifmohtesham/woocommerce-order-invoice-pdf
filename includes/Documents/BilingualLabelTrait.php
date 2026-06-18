<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

trait BilingualLabelTrait {

	protected function bilingual_enabled(): bool {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->is_enabled( $this );
	}

	protected function bilingual_secondary( string $slug ): string {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->secondary_label( $slug, $this );
	}

	protected function bilingual_rtl(): bool {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->is_rtl( $this );
	}

	protected function secondary_shop_name(): string {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->secondary_shop_name();
	}
	protected function secondary_shop_address(): string {
		return \WOI\PDF\Bilingual\BilingualEngine::instance()->secondary_shop_address();
	}

	/**
	 * Echo the shop name + address block, bilingual when enabled.
	 *
	 * @param bool $wrap_shop_address When true, wraps shop_address() in
	 *   <div class="shop-address"> (Modern/Simple-style templates). When false
	 *   (default, Business-style), shop_address() is emitted raw — the caller's
	 *   outer <div class="shop-address"> already provides the wrapper.
	 *
	 * @internal This flag is an internal marker for template-markup variants
	 *   (Business raw vs Modern/Simple/SP wrapped) and is not part of the
	 *   stable public API. Callers must not rely on this signature remaining
	 *   unchanged across plugin versions.
	 */
	public function bilingual_shop_block( bool $wrap_shop_address = false ): void {
		if ( ! $this->bilingual_enabled() ) {
			echo '<div class="shop-name"><h3>'; $this->shop_name(); echo '</h3></div>';
			if ( $wrap_shop_address ) {
				echo '<div class="shop-address">'; $this->shop_address(); echo '</div>';
			} else {
				$this->shop_address();
			}
			return;
		}
		$dir      = $this->bilingual_rtl() ? 'rtl' : 'ltr';
		$sec_name = $this->secondary_shop_name();
		$sec_addr = $this->secondary_shop_address();
		echo '<table class="bilingual-shop"><tr>';
		echo '<td class="bilingual-primary">';
		echo '<div class="shop-name"><h3>'; $this->shop_name(); echo '</h3></div>';
		if ( $wrap_shop_address ) {
			echo '<div class="shop-address">'; $this->shop_address(); echo '</div>';
		} else {
			$this->shop_address();
		}
		echo '</td>';
		echo '<td class="bilingual-secondary woi-bilingual-secondary" dir="' . esc_attr( $dir ) . '">';
		if ( '' !== $sec_name ) {
			echo '<div class="shop-name"><h3>' . esc_html( $sec_name ) . '</h3></div>';
		} else {
			echo '<div class="shop-name"><h3>'; $this->shop_name(); echo '</h3></div>';
		}
		if ( '' !== $sec_addr ) {
			$wrapped = nl2br( esc_html( $sec_addr ) );
			if ( $wrap_shop_address ) {
				echo '<div class="shop-address">' . $wrapped . '</div>';
			} else {
				echo $wrapped;
			}
		} else {
			if ( $wrap_shop_address ) {
				echo '<div class="shop-address">'; $this->shop_address(); echo '</div>';
			} else {
				$this->shop_address();
			}
		}
		echo '</td></tr></table>';
	}

	/**
	 * Whether a distinct secondary (e.g. Arabic) shop block is available.
	 *
	 * True only when bilingual mode is on AND a secondary shop name or address
	 * has been configured — so templates can lay out a three-column
	 * "primary | logo | secondary" header without echoing a duplicate Latin
	 * block when no translation exists.
	 */
	public function has_secondary_shop(): bool {
		return $this->bilingual_enabled()
			&& ( '' !== $this->secondary_shop_name() || '' !== $this->secondary_shop_address() );
	}

	/**
	 * Echo the PRIMARY (e.g. English) shop name + address only.
	 *
	 * Unlike bilingual_shop_block() this does not mirror the secondary column —
	 * it is meant for layouts that place the secondary block in a separate cell.
	 */
	public function shop_block_primary(): void {
		echo '<div class="shop-name"><h3>'; $this->shop_name(); echo '</h3></div>';
		$this->shop_address();
	}

	/**
	 * Echo the SECONDARY (e.g. Arabic) shop name + address only.
	 *
	 * Falls back to the primary value for whichever of name/address has no
	 * configured translation. Arabic is shaped downstream in PDFMaker.
	 */
	public function shop_block_secondary(): void {
		$sec_name = $this->secondary_shop_name();
		$sec_addr = $this->secondary_shop_address();
		if ( '' !== $sec_name ) {
			echo '<div class="shop-name"><h3>' . esc_html( $sec_name ) . '</h3></div>';
		} else {
			echo '<div class="shop-name"><h3>'; $this->shop_name(); echo '</h3></div>';
		}
		if ( '' !== $sec_addr ) {
			echo nl2br( esc_html( $sec_addr ) );
		} else {
			$this->shop_address();
		}
	}

	/**
	 * Emit the shop-NAME slot.
	 *
	 * DISABLED: emits only the shop-name div — identical to the original markup.
	 * ENABLED:  emits the full two-column mirror table (name + address combined),
	 *           because the mirror must be output in the name slot so custom blocks
	 *           attached to woi_pdf_after_shop_name / woi_pdf_before_shop_address
	 *           bracket it correctly.
	 *
	 * @param bool $wrap_shop_address Passed through to bilingual_shop_block() in
	 *   enabled mode; controls whether shop_address() is wrapped in a div.
	 */
	public function bilingual_shop_name_slot( bool $wrap_shop_address = false ): void {
		if ( ! $this->bilingual_enabled() ) {
			echo '<div class="shop-name"><h3>'; $this->shop_name(); echo '</h3></div>';
			return;
		}
		// Enabled: emit the full mirror here; address slot will be a no-op.
		$this->bilingual_shop_block( $wrap_shop_address );
	}

	/**
	 * Emit the shop-ADDRESS slot.
	 *
	 * DISABLED: emits shop_address() with optional wrapper — identical to original.
	 * ENABLED:  no-op; the address was already emitted by bilingual_shop_name_slot().
	 *
	 * @param bool $wrap_shop_address When true, wraps address in
	 *   <div class="shop-address"> (Modern/Simple-style).
	 */
	public function bilingual_shop_address_slot( bool $wrap_shop_address = false ): void {
		if ( $this->bilingual_enabled() ) {
			return; // mirror already emitted address in the name slot
		}
		if ( $wrap_shop_address ) {
			echo '<div class="shop-address">'; $this->shop_address(); echo '</div>';
		} else {
			$this->shop_address();
		}
	}

	/**
	 * Echo a billing or shipping address block, bilingual when enabled.
	 *
	 * @param string $type 'billing' or 'shipping'.
	 */
	public function bilingual_address_block( string $type ): void {
		$emit = ( 'shipping' === $type ) ? 'shipping_address' : 'billing_address';
		if ( ! $this->bilingual_enabled() ) {
			echo '<p>'; $this->$emit(); echo '</p>';
			return;
		}

		// WooCommerce stores only the Latin address; there is no built-in Arabic
		// translation. Without a real secondary we must NOT echo the same Latin
		// block in both columns — that produces the confusing duplicate seen on
		// the UAE invoice. A site can supply a translated address through this
		// filter (e.g. from order meta); when it returns empty we render the
		// single-column block instead of a mirror.
		$primary   = $this->capture( array( $this, $emit ) );
		$secondary = (string) apply_filters( 'woi_pdf_bilingual_address_secondary', '', $type, $this, $primary );

		if ( '' === trim( $secondary ) ) {
			echo '<p>' . $primary . '</p>';
			return;
		}

		$dir = $this->bilingual_rtl() ? 'rtl' : 'ltr';
		echo '<table class="bilingual-address"><tr>';
		echo '<td class="bilingual-primary"><p>' . $primary . '</p></td>';
		echo '<td class="bilingual-secondary woi-bilingual-secondary" dir="' . esc_attr( $dir ) . '"><p>'
			. nl2br( esc_html( $secondary ) ) . '</p></td></tr></table>';
	}

	/**
	 * Run an echoing callback and return its output instead of printing it.
	 *
	 * @param callable $callback Callable that echoes markup.
	 * @return string
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * Echo a label, bilingual when enabled. $slug must match get_title_for().
	 */
	public function render_label( string $slug ): void {
		$primary = $this->get_title_for( $slug );
		if ( ! $this->bilingual_enabled() ) {
			echo esc_html( $primary );
			return;
		}
		$secondary = $this->bilingual_secondary( $slug );
		if ( '' === $secondary ) {
			echo esc_html( $primary );
			return;
		}
		$dir = $this->bilingual_rtl() ? 'rtl' : 'ltr';
		printf(
			'<span class="woi-lbl-primary">%s</span><span class="woi-lbl-secondary" dir="%s">%s</span>',
			esc_html( $primary ),
			esc_attr( $dir ),
			esc_html( $secondary )
		);
	}
}
