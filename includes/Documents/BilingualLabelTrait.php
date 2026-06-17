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
				echo '<div class="shop-address">' . $wrapped . '</div>';
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
		$dir = $this->bilingual_rtl() ? 'rtl' : 'ltr';
		echo '<table class="bilingual-address"><tr>';
		echo '<td class="bilingual-primary"><p>'; $this->$emit(); echo '</p></td>';
		echo '<td class="bilingual-secondary woi-bilingual-secondary" dir="' . esc_attr( $dir ) . '"><p>';
		$this->$emit(); // same Latin content; surrounding labels come from render_label
		echo '</p></td></tr></table>';
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
