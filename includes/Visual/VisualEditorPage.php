<?php
namespace WOI\PDF\Visual;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\VisualEditorPage' ) ) :

class VisualEditorPage {

    /** Admin page slug for the dedicated full-screen editor. */
    private const PAGE_SLUG = 'woi-pdf-visual';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
        // Visually hide the standalone sidebar link — the editor is reached via
        // the "Visual Template" tab in the PDF Invoices nav. The page MUST stay
        // registered under 'woocommerce' (removing it via remove_submenu_page
        // breaks WP's parent resolution and 403s the page), so we hide only the
        // menu link with CSS, which never touches access control.
        add_action( 'admin_head', array( $this, 'hide_standalone_menu_item_css' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        // Add the tab to the PDF Invoices settings shell; it links to this page.
        add_filter( 'woi_pdf_settings_tabs', array( $this, 'add_settings_tab' ) );
    }

    public function register_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Visual Template', 'woocommerce-orders-invoice-pdf' ),
            __( 'Visual Template', 'woocommerce-orders-invoice-pdf' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Hide the standalone WooCommerce submenu LINK with CSS (the page stays
     * registered and accessible). Targets the link by its href so it works
     * regardless of how WordPress renders the submenu markup.
     */
    public function hide_standalone_menu_item_css(): void {
        echo '<style id="woi-hide-visual-menu">'
            . '#adminmenu .wp-submenu a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '"]{display:none}'
            . '#adminmenu .wp-submenu li:has(> a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '"]){display:none}'
            . '</style>';
    }

    /**
     * Register a "Visual Template" tab in the PDF Invoices settings nav that
     * links to the dedicated full-screen editor page (rather than rendering
     * in-shell, which GrapesJS is too wide for).
     *
     * @param array $tabs
     * @return array
     */
    public function add_settings_tab( $tabs ) {
        if ( ! is_array( $tabs ) ) {
            return $tabs;
        }
        $tabs['visual'] = array(
            'title' => __( 'Visual Template', 'woocommerce-orders-invoice-pdf' ),
            'href'  => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
        );
        return $tabs;
    }

    public function enqueue( string $hook ): void {
        if ( false === strpos( $hook, 'woi-pdf-visual' ) ) {
            return;
        }
        $base = WOI_PDF()->plugin_url() . '/assets/visual-editor';
        wp_enqueue_style( 'woi-grapesjs', $base . '/grapesjs/grapes.min.css', array(), WOI_PDF_VERSION );
        wp_enqueue_script( 'woi-grapesjs', $base . '/grapesjs/grapes.min.js', array(), WOI_PDF_VERSION, true );
        wp_enqueue_script( 'woi-visual-editor', $base . '/app.js', array( 'woi-grapesjs' ), WOI_PDF_VERSION, true );

        $store = new VisualTemplateStore();
        wp_localize_script( 'woi-visual-editor', 'woiVisual', array(
            'restUrl'    => esc_url_raw( rest_url( 'woi-pdf/v1/visual-template' ) ),
            'ajaxUrl'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
            'previewUrl' => esc_url_raw( admin_url( 'admin-ajax.php?action=woi_pdf_preview' ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'previewNonce' => wp_create_nonce( 'woi_pdf_preview' ),
            'docType'      => 'invoice',
            'stored'          => $store->get( 'invoice' ),
            'starter'         => $this->starter_html(),
            'sampleData'      => $this->sample_data(),
            'previewDataUrl'  => esc_url_raw( rest_url( 'woi-pdf/v1/visual-preview-data' ) ),
            'orderSearchAction' => 'woi_pdf_preview_order_search',
        ) );
    }

    public function render_page(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Visual Invoice Template', 'woocommerce-orders-invoice-pdf' ) . '</h1>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=woi_pdf_options_page' ) ) . '">&larr; ' . esc_html__( 'Back to PDF Invoices', 'woocommerce-orders-invoice-pdf' ) . '</a></p>';
        echo '<p>' . esc_html__( 'Design with table/block layout for best mPDF fidelity. Use "Preview real PDF" to verify Arabic and pagination. Note: real-PDF preview reflects the saved design and only renders the visual template when "Visual template (invoice)" is enabled in Invoice Settings.', 'woocommerce-orders-invoice-pdf' ) . '</p>';
        echo '<div class="woi-order-bar" style="margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '<label for="woi-order-search"><strong>' . esc_html__( 'Preview order:', 'woocommerce-orders-invoice-pdf' ) . '</strong></label>';
        echo '<input type="text" id="woi-order-search" class="regular-text" placeholder="' . esc_attr__( 'Order #, email or name (blank = last order)', 'woocommerce-orders-invoice-pdf' ) . '" style="max-width:280px">';
        echo '<button type="button" class="button" id="woi-order-search-btn">' . esc_html__( 'Find', 'woocommerce-orders-invoice-pdf' ) . '</button>';
        echo '<select id="woi-order-results" style="display:none;max-width:320px"></select>';
        echo '<button type="button" class="button button-primary" id="woi-preview-real-order">' . esc_html__( 'Preview real order', 'woocommerce-orders-invoice-pdf' ) . '</button>';
        echo '<span id="woi-order-current" style="color:#555"></span>';
        echo '</div>';
        echo '<div id="woi-visual-editor"></div></div>';
    }

    private function starter_html(): string {
        $file = WOI_PDF()->plugin_path() . '/assets/visual-editor/starter-invoice.html';
        return is_readable( $file ) ? (string) file_get_contents( $file ) : '';
    }

    /** Static sample values for the in-editor preview (browser-only, approximate). */
    private function sample_data(): array {
        return array(
            '{{shop_name}}'         => 'Acme Trading LLC',
            '{{shop_address}}'      => 'Office 12, Dubai, UAE',
            '{{shop_name_ar}}'      => 'أكمي للتجارة',
            '{{shop_address_ar}}'   => 'مكتب ١٢، دبي',
            '{{trn}}'               => '100123456700003',
            '{{shop_phone}}'        => '+971 4 000 0000',
            '{{shop_email}}'        => 'billing@acme.example',
            '{{logo}}'              => '',
            '{{document_title}}'    => 'Tax Invoice',
            '{{document_title_ar}}' => 'فاتورة ضريبية',
            '{{billing_address}}'   => 'John Buyer<br>Abu Dhabi, UAE',
            '{{invoice_number}}'    => 'INV-001',
            '{{invoice_date}}'      => '18 June 2026',
            '{{order_number}}'      => '4242',
            '{{payment_method}}'    => 'Credit Card',
            '{{line_items}}'        => '<table class="order-details"><thead><tr><th>Item</th><th>Qty</th><th>Price</th></tr></thead><tbody><tr><td>Widget</td><td>2</td><td>AED 50</td></tr></tbody></table>',
            '{{totals}}'            => '<table class="totals-table"><tr><th>Total</th><td>AED 100</td></tr></table>',
        );
    }
}

endif;
