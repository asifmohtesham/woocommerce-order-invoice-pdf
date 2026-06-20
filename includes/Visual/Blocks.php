<?php
namespace WOI\PDF\Visual;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\Blocks' ) ) :

/**
 * Registers the custom invoice blocks server-side so do_blocks() renders
 * them canonically. Slice 1 ships a minimal set; later slices extend it.
 */
class Blocks {

	/** @var string[] Block names registered for the visual editor. */
	private const NAMES = array(
		'woi/text',
		'woi/logo', 'woi/shop-name', 'woi/shop-address', 'woi/shop-name-ar', 'woi/shop-address-ar',
		'woi/trn', 'woi/shop-phone', 'woi/shop-email',
		'woi/document-title', 'woi/document-title-ar', 'woi/invoice-number', 'woi/invoice-date',
		'woi/order-number', 'woi/payment-method', 'woi/billing-address',
		'woi/line-items', 'woi/totals',
		'woi/spacer', 'woi/divider', 'woi/heading', 'woi/page-break',
		'woi/columns', 'woi/column',
	);

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'block_categories_all', array( $this, 'add_category' ) );
	}

	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		foreach ( self::NAMES as $name ) {
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}
			// Static blocks: no render_callback; inner HTML (the {{token}}) passes through.
			register_block_type( $name, array(
				'api_version' => 2,
				'category'    => 'woi-invoice',
			) );
		}
	}

	public function add_category( $categories ) {
		if ( ! is_array( $categories ) ) { return $categories; }
		array_unshift( $categories, array(
			'slug'  => 'woi-invoice',
			'title' => __( 'Invoice', 'woocommerce-orders-invoice-pdf' ),
		) );
		return $categories;
	}
}

endif;
