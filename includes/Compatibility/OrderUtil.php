<?php
namespace WOI\PDF\Compatibility;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Compatibility\\OrderUtil' ) ) :

/**
 * WooCommerce OrderUtil compatibility class.
 *
 * Thin wrapper around \Automattic\WooCommerce\Utilities\OrderUtil that
 * degrades gracefully when WooCommerce (or HPOS) is not available.
 */
class OrderUtil {

	public $wc_order_util_class_object;

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		$this->wc_order_util_class_object = $this->get_wc_order_util_class();
	}

	public function get_wc_order_util_class() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::class;
		} else {
			return false;
		}
	}

	public function get_order_type( $order_id ) {
		if ( $this->wc_order_util_class_object && is_callable( array( $this->wc_order_util_class_object, 'get_order_type' ) ) ) {
			return $this->wc_order_util_class_object::get_order_type( intval( $order_id ) );
		} else {
			return get_post_type( intval( $order_id ) );
		}
	}

	public function custom_orders_table_usage_is_enabled(): bool {
		if ( $this->wc_order_util_class_object && is_callable( array( $this->wc_order_util_class_object, 'custom_orders_table_usage_is_enabled' ) ) ) {
			return (bool) $this->wc_order_util_class_object::custom_orders_table_usage_is_enabled();
		} else {
			return false;
		}
	}

	public function is_wc_admin_page(): bool {
		return class_exists( 'Automattic\WooCommerce\Admin\PageController' ) &&
			is_callable( array( '\\Automattic\\WooCommerce\\Admin\\PageController', 'is_admin_or_embed_page' ) ) &&
			\Automattic\WooCommerce\Admin\PageController::is_admin_or_embed_page();
	}

}

endif; // class_exists
