<?php
namespace WOI\PDF\Visual;

if ( ! defined( 'ABSPATH' ) ) { return; }

/**
 * Whether the visual template path should be used for a given document.
 * Invoice-only in slice 1; requires the toggle ON and a non-empty stored template.
 *
 * @param string $doc_type   The document type (e.g. 'invoice').
 * @param bool   $toggle_on  Whether the enable_visual_template_invoice setting is truthy.
 * @param string $stored_html The HTML stored in VisualTemplateStore for this doc type.
 * @return bool
 */
function visual_template_active( string $doc_type, bool $toggle_on, string $stored_html ): bool {
    return 'invoice' === $doc_type && $toggle_on && '' !== trim( $stored_html );
}

/**
 * Static sample token map for the in-editor preview (browser-only, approximate).
 * Keyed by {{token}} braces to match TemplateTokens::map output, so the same
 * client-side token-merge works against sample data and real order data alike.
 *
 * @return array<string,string>
 */
function woi_pdf_visual_sample_data(): array {
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
        // Redesign leaf tokens (sample values for the in-editor preview).
        '{{shipping_address}}'  => 'John Buyer<br>Abu Dhabi, UAE',
        '{{recipient_trn}}'     => '<div class="woi-party-trn">TRN&nbsp; 100123456700003</div>',
        '{{bank_details}}'      => '<table class="woi-bank"><tbody><tr><th>Bank</th><td>Sample Bank</td></tr><tr><th>IBAN</th><td class="mono">AE00 0000 0000 0000</td></tr></tbody></table>',
        '{{qr_code}}'           => '<div class="woi-qr-placeholder">QR</div>',
        '{{amount_words}}'      => 'UAE Dirham One Hundred only.',
        '{{shop_website}}'      => 'www.acme.example',
    );
}
