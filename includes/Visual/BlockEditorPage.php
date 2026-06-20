<?php
namespace WOI\PDF\Visual;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\BlockEditorPage' ) ) :

class BlockEditorPage {

    private const PAGE_SLUG = 'woi-pdf-blocks';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
        add_action( 'admin_head', array( $this, 'hide_standalone_menu_item_css' ) );
        add_action( 'admin_head', array( $this, 'suppress_admin_notices' ), 1 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_filter( 'woi_pdf_settings_tabs', array( $this, 'add_settings_tab' ) );
    }

    public function register_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Block Editor', 'woocommerce-orders-invoice-pdf' ),
            __( 'Block Editor', 'woocommerce-orders-invoice-pdf' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function hide_standalone_menu_item_css(): void {
        echo '<style id="woi-hide-blocks-menu">'
            . '#adminmenu .wp-submenu a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '"]{display:none}'
            . '#adminmenu .wp-submenu li:has(> a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '"]){display:none}'
            . '</style>';
    }

    public function add_settings_tab( $tabs ) {
        if ( ! is_array( $tabs ) ) { return $tabs; }
        $tabs['blocks'] = array(
            'title' => __( 'Block Editor', 'woocommerce-orders-invoice-pdf' ),
            'href'  => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
        );
        return $tabs;
    }

    public function enqueue( string $hook ): void {
        if ( false === strpos( $hook, self::PAGE_SLUG ) ) { return; }

        $asset_path = WOI_PDF()->plugin_path() . '/assets/js/block-editor/index.asset.php';
        $asset = is_readable( $asset_path ) ? require $asset_path : array( 'dependencies' => array(), 'version' => WOI_PDF_VERSION );

        wp_enqueue_script(
            'woi-block-editor',
            WOI_PDF()->plugin_url() . '/assets/js/block-editor/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
        // Core block-editor styles for the canvas + components.
        wp_enqueue_style( 'wp-edit-blocks' );
        wp_enqueue_style( 'wp-components' );
        wp_enqueue_style( 'wp-format-library' );

        $store = new VisualTemplateStore();
        wp_localize_script( 'woi-block-editor', 'woiBlocks', array(
            'restUrl'           => esc_url_raw( rest_url( 'woi-pdf/v1' ) ),
            'nonce'             => wp_create_nonce( 'wp_rest' ),
            'docType'           => 'invoice',
            'storedMarkup'      => $store->get_blocks_markup( 'invoice' ),
            'activeSource'      => $store->get_active_source(),
            'backUrl'           => esc_url_raw( admin_url( 'admin.php?page=woi_pdf_options_page' ) ),
            // --- Live preview (Slice 3A) ---
            'ajaxUrl'           => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
            'previewNonce'      => wp_create_nonce( 'woi_pdf_preview' ),
            'previewCss'        => woi_pdf_visual_document_css(),
            'sampleData'        => woi_pdf_visual_sample_data(),
            'previewDataUrl'    => esc_url_raw( rest_url( 'woi-pdf/v1/visual-preview-data' ) ),
            'orderSearchAction' => 'woi_pdf_preview_order_search',
        ) );
    }

    public function render_page(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Block Invoice Template', 'woocommerce-orders-invoice-pdf' ) . '</h1>';
        echo '<p>' . esc_html__( 'Design the invoice with WordPress blocks. Set this as the active template source to render the PDF from this design. Requires "Visual template (invoice)" enabled in Invoice Settings.', 'woocommerce-orders-invoice-pdf' ) . '</p>';
        echo '<div id="woi-block-editor-root"></div>';
        echo '</div>';
    }

    public function is_block_editor_screen(): bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        return $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, self::PAGE_SLUG );
    }

    public function suppress_admin_notices(): void {
        if ( ! $this->is_block_editor_screen() ) { return; }
        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
        remove_all_actions( 'user_admin_notices' );
    }
}

endif;
