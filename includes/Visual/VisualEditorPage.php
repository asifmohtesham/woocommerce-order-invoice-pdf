<?php
namespace WOI\PDF\Visual;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\VisualEditorPage' ) ) :

class VisualEditorPage {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    public function register_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Visual Template', 'woocommerce-orders-invoice-pdf' ),
            __( 'Visual Template', 'woocommerce-orders-invoice-pdf' ),
            'manage_woocommerce',
            'woi-pdf-visual',
            array( $this, 'render_page' )
        );
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
            'previewUrl' => esc_url_raw( admin_url( 'admin-ajax.php?action=woi_pdf_preview' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'docType'    => 'invoice',
            'stored'     => $store->get( 'invoice' ),
            'starter'    => $this->starter_html(),
            'sampleData' => $this->sample_data(),
        ) );
    }

    public function render_page(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Visual Invoice Template', 'woocommerce-orders-invoice-pdf' ) . '</h1>';
        echo '<p>' . esc_html__( 'Design with table/block layout for best mPDF fidelity. Use "Preview real PDF" to verify Arabic and pagination.', 'woocommerce-orders-invoice-pdf' ) . '</p>';
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
