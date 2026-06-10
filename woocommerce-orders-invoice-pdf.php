<?php
/**
 * Plugin Name:          WooCommerce Orders Invoice PDF
 * Plugin URI:           https://example.com/woocommerce-orders-invoice-pdf
 * Description:          PDF invoices, packing slips, proforma, credit notes, receipts and summaries for WooCommerce — standalone merged plugin.
 * Version:              1.0.1
 * Author:               Muhammad Asif Mohtesham
 * Author URI:           https://github.com/asifmohtesham
 * License:              GPLv2 or later
 * License URI:          https://opensource.org/licenses/gpl-license.php
 * Text Domain:          woocommerce-orders-invoice-pdf
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * WC requires at least: 3.3
 * WC tested up to:      9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WOI_PDF' ) ) :

class WOI_PDF {

	public string $version     = '1.0.1';
	public string $version_php = '7.4';
	public string $version_woo = '3.3';
	public string $version_wp  = '6.0';

	public \WOI\PDF\Documents        $documents;
	public \WOI\PDF\Settings         $settings;
	public \WOI\PDF\Main             $main;
	public \WOI\PDF\Admin            $admin;
	public \WOI\PDF\Frontend         $frontend;
	public \WOI\PDF\Assets           $assets;
	public \WOI\PDF\Endpoint         $endpoint;
	public \WOI\PDF\Install          $install;
	public \WOI\PDF\FontSynchronizer $font_synchronizer;
	public \WOI\PDF\Rest             $rest;
	public \WOI\PDF\DocumentRenderer $renderer;
	public \WOI\PDF\TemplateLoader   $template_loader;
	public \WOI\PDF\Editor\EditorSettings    $editor_settings;
	public \WOI\PDF\Editor\EditorMain        $editor_main;
	public \WOI\PDF\Editor\PriceStorage      $price_storage;
	public \WOI\PDF\Compatibility\FileSystem $file_system;

	public string $plugin_basename;

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		require_once __DIR__ . '/vendor/autoload.php';
		require_once __DIR__ . '/vendor/strauss/autoload.php';

		$this->define( 'WOI_PDF_VERSION',     $this->version );
		$this->define( 'WOI_PDF_PLUGIN_FILE', __FILE__ );
		$this->define( 'WOI_PDF_PLUGIN_PATH', __DIR__ );
		$this->define( 'WOI_PDF_PLUGIN_URL',  untrailingslashit( plugins_url( '/', __FILE__ ) ) );

		$this->plugin_basename = plugin_basename( __FILE__ );

		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'plugins_loaded',          array( $this, 'init' ), 0 );
	}

	public function declare_hpos_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WOI_PDF_PLUGIN_FILE
			);
		}
	}

	public function init(): void {
		$this->file_system     = \WOI\PDF\Compatibility\FileSystem::instance();
		$this->renderer        = new \WOI\PDF\DocumentRenderer();
		$this->template_loader = new \WOI\PDF\TemplateLoader( WOI_PDF_PLUGIN_PATH );
		$this->settings        = \WOI\PDF\Settings::instance();
		$this->documents       = \WOI\PDF\Documents::instance();
		$this->install         = \WOI\PDF\Install::instance();
		$this->font_synchronizer = \WOI\PDF\FontSynchronizer::instance();
		$this->main            = \WOI\PDF\Main::instance();
		$this->endpoint        = \WOI\PDF\Endpoint::instance();
		$this->assets          = \WOI\PDF\Assets::instance();
		$this->admin           = \WOI\PDF\Admin::instance();
		$this->frontend        = \WOI\PDF\Frontend::instance();
		$this->rest            = new \WOI\PDF\Rest();
		$this->editor_main     = \WOI\PDF\Editor\EditorMain::instance();
		$this->editor_settings = \WOI\PDF\Editor\EditorSettings::instance();
		$this->price_storage   = \WOI\PDF\Editor\PriceStorage::instance();

		add_action( 'woi_pdf_init_documents', function(): void {
			foreach ( $this->documents->get_documents( 'all' ) as $document ) {
				$this->settings->register_document_tab( $document );
			}
		} );

		do_action( 'woi_pdf_init' );
	}

	private function define( string $name, $value ): void {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	public function plugin_url(): string {
		return WOI_PDF_PLUGIN_URL;
	}

	public function plugin_path(): string {
		return WOI_PDF_PLUGIN_PATH;
	}

	public function is_woocommerce_activated(): bool {
		return class_exists( 'WooCommerce' );
	}
}

endif;

/**
 * Global singleton accessor.
 *
 * @return WOI_PDF
 */
function WOI_PDF(): WOI_PDF {
	return WOI_PDF::instance();
}

WOI_PDF();
