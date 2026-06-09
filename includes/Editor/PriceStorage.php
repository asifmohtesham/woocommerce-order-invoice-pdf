<?php
namespace WOI\PDF\Editor;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Editor\\PriceStorage' ) ) :

class PriceStorage {

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_regular_item_price' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta',    array( $this, 'hide_regular_price_itemmeta' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_tax_rate_percentage' ), 10, 2 );
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'save_tax_rate_on_recalculate' ), 10, 2 );
	}

	/**
	 * Save the pre-discount (regular) unit price against each order line item.
	 *
	 * @param int   $order_id
	 * @param array $posted_data
	 */
	public function save_regular_item_price( int $order_id, array $posted_data ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$regular_price = $product->get_regular_price();
			if ( '' !== $regular_price ) {
				wc_update_order_item_meta( $item_id, '_woi_pdf_regular_price', $regular_price );
			}
		}
	}

	/**
	 * Hide stored price meta from WooCommerce order item meta display.
	 *
	 * @param array $hidden
	 * @return array
	 */
	public function hide_regular_price_itemmeta( array $hidden ): array {
		$hidden[] = '_woi_pdf_regular_price';
		$hidden[] = '_woi_pdf_tax_rate_percentage';
		return $hidden;
	}

	/**
	 * Save effective tax rate percentage at checkout.
	 *
	 * @param int   $order_id
	 * @param array $posted_data
	 */
	public function save_tax_rate_percentage( int $order_id, array $posted_data ): void {
		$this->store_tax_rate_percentage( wc_get_order( $order_id ) );
	}

	/**
	 * Re-save tax rates when order totals are recalculated in admin.
	 *
	 * @param bool      $and_taxes
	 * @param \WC_Order $order
	 */
	public function save_tax_rate_on_recalculate( bool $and_taxes, \WC_Order $order ): void {
		if ( $and_taxes ) {
			$this->store_tax_rate_percentage( $order );
		}
	}

	private function store_tax_rate_percentage( ?\WC_Order $order ): void {
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_taxes() as $item_id => $tax_item ) {
			$rate_id    = $tax_item->get_rate_id();
			$rate       = \WC_Tax::_get_tax_rate( $rate_id );
			$percentage = isset( $rate['tax_rate'] ) ? (float) $rate['tax_rate'] : null;
			if ( null !== $percentage ) {
				wc_update_order_item_meta( $item_id, '_woi_pdf_tax_rate_percentage', $percentage );
			}
		}
	}
}

endif;
