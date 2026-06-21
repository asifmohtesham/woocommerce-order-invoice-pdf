<?php
namespace WOI\PDF\Visual;

use WOI\PDF\Bilingual\BilingualEngine;
use function esc_html;
use function esc_attr;
use function wp_kses_post;
use function woi_pdf_templates_get_table_headers;
use function woi_pdf_templates_get_table_body;
use function woi_pdf_templates_get_totals;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\TemplateTokens' ) ) :

class TemplateTokens {

    /**
     * Build the {{token}} => replacement map for a document.
     *
     * Plain-text scalar tokens (shop name, labels, titles, numbers) are
     * esc_html()'d. Block tokens carry trusted HTML produced by existing
     * renderers: the shop address already carries <br/> line breaks, the
     * logo/billing address are full markup, and line-item / totals cells embed
     * product markup (item name, thumbnail, meta) and wc_price() spans. Those
     * are passed through wp_kses_post() so the markup renders (not shown as raw
     * text) while scripts/unsafe attributes are still stripped. The raw
     * secondary (Arabic) shop address is esc_html()'d then nl2br()'d so its
     * line breaks render without trusting arbitrary markup.
     *
     * @param object $document An OrderDocument (or compatible stub).
     * @return array<string,string>
     */
    public function map( $document ): array {
        $engine = BilingualEngine::instance();

        return array(
            '{{logo}}'             => $document->has_header_logo() ? $this->capture( array( $document, 'header_logo' ) ) : '',
            '{{shop_name}}'        => esc_html( (string) $document->get_shop_name() ),
            '{{shop_address}}'     => wp_kses_post( (string) $document->get_shop_address() ),
            '{{shop_name_ar}}'     => esc_html( $engine->secondary_shop_name() ),
            '{{shop_address_ar}}'  => nl2br( esc_html( (string) $engine->secondary_shop_address() ) ),
            '{{document_title}}'   => esc_html( $document->get_title() ),
            '{{document_title_ar}}'=> esc_html( $engine->secondary_label( 'document', $document ) ),
            '{{trn}}'              => esc_html( (string) $document->get_shop_vat_number() ),
            '{{shop_phone}}'       => esc_html( (string) $document->get_shop_phone_number() ),
            '{{shop_email}}'       => esc_html( (string) $document->get_shop_email_address() ),
            '{{invoice_number}}'   => $this->capture( fn() => $document->number( $document->get_type() ) ),
            '{{invoice_date}}'     => $this->capture( fn() => $document->date( $document->get_type() ) ),
            '{{order_number}}'     => esc_html( (string) $document->get_order_number() ),
            '{{payment_method}}'   => esc_html( (string) $document->get_payment_method() ),
            '{{billing_address}}'  => (string) $document->get_billing_address(),
            '{{shipping_address}}' => $this->shipping_address( $document ),
            '{{recipient_trn}}'    => $this->recipient_trn( $document ),
            '{{shop_website}}'     => esc_html( $this->shop_website() ),
            '{{bank_details}}'     => $this->render_bank_details(),
            '{{amount_words}}'     => esc_html( (string) apply_filters( 'woi_pdf_amount_in_words', '', $document ) ),
            '{{qr_code}}'          => $this->render_qr_code( $document ),
            '{{line_items}}'       => $this->render_line_items( $document ),
            '{{totals}}'           => $this->render_totals( $document ),
            // Section tokens: each emits a whole canonical visual-document.css
            // section. The block editor exposes these as section blocks so a
            // reset can seed the full redesign; the GrapesJS starter can use them
            // too. They wrap the same leaf values, so merge() resolves them in one
            // pass (no nested {{tokens}} left inside).
            '{{letterhead}}'       => $this->section_letterhead( $document, $engine ),
            '{{contact_strip}}'    => $this->section_contact_strip( $document ),
            '{{title_meta}}'       => $this->section_title_meta( $document, $engine ),
            '{{parties}}'          => $this->section_parties( $document ),
            '{{lower}}'            => $this->section_lower( $document ),
            '{{signature}}'        => $this->section_signature( $document ),
            '{{footer}}'           => $this->section_footer( $document ),
        );
    }

    /** Shipping address markup (guarded — not every document stub exposes it). */
    private function shipping_address( $document ): string {
        if ( ! method_exists( $document, 'get_shipping_address' ) ) {
            return '';
        }
        return wp_kses_post( (string) $document->get_shipping_address() );
    }

    /**
     * Recipient (buyer) TRN line for the Bill To card. Resolves from the order
     * via the existing helper; returns '' when there is no order / no TRN so the
     * card simply omits the line.
     */
    private function recipient_trn( $document ): string {
        $order = $document->order ?? null;
        if ( empty( $order ) || ! function_exists( 'woi_pdf_get_recipient_trn' ) ) {
            return '';
        }
        $trn = (string) woi_pdf_get_recipient_trn( $order );
        if ( '' === $trn ) {
            return '';
        }
        $label = (string) apply_filters( 'woi_pdf_recipient_trn_label', 'TRN' );
        return '<div class="woi-party-trn">' . esc_html( $label ) . '&nbsp; ' . esc_html( $trn ) . '</div>';
    }

    /** Host of the site URL for the footer (e.g. "shop.example.com"). */
    private function shop_website(): string {
        $url = function_exists( 'home_url' ) ? (string) home_url() : '';
        return $url ? (string) preg_replace( '#^https?://#', '', rtrim( $url, '/' ) ) : '';
    }

    /**
     * Bank details table from WooCommerce BACS accounts (first configured
     * account). Returns '' when none is set so the section collapses cleanly.
     */
    private function render_bank_details(): string {
        $accounts = get_option( 'woocommerce_bacs_accounts', array() );
        if ( empty( $accounts ) || ! is_array( $accounts ) ) {
            return '';
        }
        $account = null;
        foreach ( $accounts as $candidate ) {
            if ( is_array( $candidate ) ) { $account = $candidate; break; }
        }
        if ( null === $account ) {
            return '';
        }

        $rows = array(
            array( 'Bank',         (string) ( $account['bank_name'] ?? '' ),      false ),
            array( 'Account Name', (string) ( $account['account_name'] ?? '' ),   false ),
            array( 'IBAN',         (string) ( $account['iban'] ?? '' ),           true ),
            array( 'Account No.',  (string) ( $account['account_number'] ?? '' ), true ),
            array( 'SWIFT',        (string) ( $account['bic'] ?? '' ),            true ),
        );

        $html = '<table class="woi-bank"><tbody>';
        foreach ( $rows as $row ) {
            if ( '' === $row[1] ) { continue; }
            $td_class = $row[2] ? ' class="mono"' : '';
            $html .= '<tr><th>' . esc_html( $row[0] ) . '</th><td' . $td_class . '>' . esc_html( $row[1] ) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /**
     * QR slot. Emits mPDF's native <barcode type="QR"> when the mpdf/qrcode
     * package is installed (composer require mpdf/qrcode) AND a payload is
     * available; otherwise a styled placeholder so the document never fatals
     * (an unguarded <barcode> with the package missing throws during render).
     * Override the payload with the `woi_pdf_qr_payload` filter.
     */
    private function render_qr_code( $document ): string {
        $payload  = (string) apply_filters( 'woi_pdf_qr_payload', $this->default_qr_payload( $document ), $document );
        // The (Strauss-prefixed) mpdf/qrcode package must be present; an unguarded
        // <barcode type="QR"> throws an MpdfException during render when it isn't.
        $has_qr   = class_exists( '\\WOI\\PDF\\Vendor\\Mpdf\\QrCode\\QrCode' ) || class_exists( '\\Mpdf\\QrCode\\QrCode' );
        if ( $has_qr && '' !== $payload ) {
            return '<barcode code="' . esc_attr( $payload ) . '" type="QR" error="M" size="0.5" disableborder="1" />';
        }
        return '<div class="woi-qr-placeholder">QR</div>';
    }

    /** Default QR payload: supplier TRN + invoice number (FTA-style minimal). */
    private function default_qr_payload( $document ): string {
        $inv = trim( $this->capture( fn() => $document->number( $document->get_type() ) ) );
        $trn = (string) $document->get_shop_vat_number();
        if ( '' === $inv && '' === $trn ) {
            return '';
        }
        return trim( 'TRN:' . $trn . ' INV:' . $inv );
    }

    /* ===================== Section builders =====================
     * Each returns one whole canonical visual-document.css section, with leaf
     * values already resolved (no nested {{tokens}}). Mirrors the structure in
     * assets/visual-editor/starter-invoice.html. Exposed as section tokens so the
     * block editor can offer them as section blocks (the redesign default). */

    /** Bilingual label pair, EN over AR via a real <br> (mPDF stacks on <br>). */
    private function bilabel_br( string $en, string $ar ): string {
        return '<span class="woi-lbl-primary">' . esc_html( $en ) . '</span><br>'
            . '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $ar ) . '</span>';
    }

    /** Bilingual label pair, EN then AR inline (a space between). */
    private function bilabel( string $en, string $ar ): string {
        return '<span class="woi-lbl-primary">' . esc_html( $en ) . '</span> '
            . '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $ar ) . '</span>';
    }

    private function section_letterhead( $document, $engine ): string {
        $logo            = $document->has_header_logo() ? $this->capture( array( $document, 'header_logo' ) ) : '';
        $shop_name       = esc_html( (string) $document->get_shop_name() );
        $shop_address    = wp_kses_post( (string) $document->get_shop_address() );
        $shop_name_ar    = esc_html( $engine->secondary_shop_name() );
        $shop_address_ar = nl2br( esc_html( (string) $engine->secondary_shop_address() ) );
        return '<table class="woi-letterhead"><tr>'
            . '<td class="woi-lh-en"><div class="woi-co-name">' . $shop_name . '</div><div class="woi-co-lines">' . $shop_address . '</div></td>'
            . '<td class="woi-lh-mark">' . $logo . '</td>'
            . '<td class="woi-lh-ar" dir="rtl"><div class="woi-co-name">' . $shop_name_ar . '</div><div class="woi-co-lines">' . $shop_address_ar . '</div></td>'
            . '</tr></table>';
    }

    private function section_contact_strip( $document ): string {
        $trn   = esc_html( (string) $document->get_shop_vat_number() );
        $phone = esc_html( (string) $document->get_shop_phone_number() );
        $email = esc_html( (string) $document->get_shop_email_address() );
        return '<table class="woi-contact"><tr>'
            . '<td><span class="woi-contact-k">TRN</span> <span class="woi-contact-v">' . $trn . '</span></td>'
            . '<td class="woi-contact-mid"><span class="woi-contact-k">Tel</span> <span class="woi-contact-v">' . $phone . '</span></td>'
            . '<td class="woi-contact-end"><span class="woi-contact-k">Email</span> <span class="woi-contact-v">' . $email . '</span></td>'
            . '</tr></table>';
    }

    private function section_title_meta( $document, $engine ): string {
        $title    = esc_html( $document->get_title() );
        $title_ar = esc_html( $engine->secondary_label( 'document', $document ) );
        $inv      = $this->capture( fn() => $document->number( $document->get_type() ) );
        $order    = esc_html( (string) $document->get_order_number() );
        $date     = $this->capture( fn() => $document->date( $document->get_type() ) );
        return '<table class="woi-titlebar"><tr>'
            . '<td class="woi-title-cell"><div class="woi-title-en">' . $title . '</div><div class="woi-title-ar" dir="rtl">' . $title_ar . '</div></td>'
            . '<td class="woi-meta-cell"><table class="woi-meta"><tbody>'
            . '<tr><th>' . $this->bilabel_br( 'Invoice No.', 'رقم الفاتورة' ) . '</th><td>' . $inv . '</td></tr>'
            . '<tr><th>' . $this->bilabel_br( 'Order No.', 'رقم الطلب' ) . '</th><td>' . $order . '</td></tr>'
            . '<tr><th>' . $this->bilabel_br( 'Issue Date', 'تاريخ الإصدار' ) . '</th><td>' . $date . '</td></tr>'
            . '</tbody></table></td></tr></table>';
    }

    private function section_parties( $document ): string {
        $billing       = (string) $document->get_billing_address();
        $recipient_trn = $this->recipient_trn( $document );
        $shipping      = $this->shipping_address( $document );
        return '<table class="woi-parties"><tr>'
            . '<td class="woi-party"><div class="woi-party-label">' . $this->bilabel_br( 'Bill To', 'فاتورة إلى' ) . '</div><div class="woi-party-body">' . $billing . $recipient_trn . '</div></td>'
            . '<td class="woi-party-gap"></td>'
            . '<td class="woi-party"><div class="woi-party-label">' . $this->bilabel_br( 'Ship To', 'الشحن إلى' ) . '</div><div class="woi-party-body">' . $shipping . '</div></td>'
            . '</tr></table>';
    }

    private function section_lower( $document ): string {
        $bank      = $this->render_bank_details();
        $shop_name = esc_html( (string) $document->get_shop_name() );
        $totals    = $this->render_totals( $document );
        $words     = esc_html( (string) apply_filters( 'woi_pdf_amount_in_words', '', $document ) );
        return '<table class="woi-lower"><tr>'
            . '<td class="woi-lower-left">'
            . '<div class="woi-sub-label">' . $this->bilabel( 'Bank Details', 'تفاصيل البنك' ) . '</div>'
            . $bank
            . '<div class="woi-terms">'
            . '<div class="woi-sub-label">' . $this->bilabel( 'Payment Terms', 'شروط الدفع' ) . '</div>'
            . '<p>Payment due within 30 days of invoice date. Goods remain the property of ' . $shop_name . ' until paid in full.</p>'
            . '<p class="ar" dir="rtl">يُستحق الدفع خلال ٣٠ يوماً من تاريخ الفاتورة. تبقى البضاعة ملكاً للشركة حتى سداد كامل المبلغ.</p>'
            . '</div>'
            . '</td>'
            . '<td class="woi-lower-right">'
            . $totals
            . '<div class="woi-amount-words"><span class="woi-sub-label">' . $this->bilabel( 'Amount in words', 'المبلغ كتابةً' ) . '</span><span>' . $words . '</span></div>'
            . '</td>'
            . '</tr></table>';
    }

    private function section_signature( $document ): string {
        $qr        = $this->render_qr_code( $document );
        $shop_name = esc_html( (string) $document->get_shop_name() );
        return '<table class="woi-sign"><tr>'
            . '<td class="woi-qr">' . $qr . '<div class="woi-qr-cap">' . $this->bilabel( 'Scan to verify', 'امسح للتحقق' ) . '</div></td>'
            . '<td class="woi-stamp"><div class="woi-stamp-ring">Company<br>Stamp</div></td>'
            . '<td class="woi-signature"><div class="woi-sig-line"></div><div class="woi-sig-cap">' . $this->bilabel( 'Authorised Signatory', 'المُوقّع المُفوّض' ) . '</div><div class="woi-sig-co">For ' . $shop_name . '</div></td>'
            . '</tr></table>';
    }

    /**
     * mPDF running page-footer (accurate "Page X of Y" on every page). Emitted by
     * the wrapper BEFORE the content so it applies from page 1. mPDF substitutes
     * {PAGENO}/{nbpg} per page; the tags produce no inline output. The browser
     * canvas never sees this (it doesn't use the wrapper).
     */
    public function running_footer( $document ): string {
        $shop_name = esc_html( (string) $document->get_shop_name() );
        $trn       = esc_html( (string) $document->get_shop_vat_number() );
        $website   = esc_html( $this->shop_website() );
        $page      = '<span class="woi-lbl-primary">Page {PAGENO} of {nbpg}</span> '
            . '<span class="woi-lbl-secondary" dir="rtl">صفحة {PAGENO} من {nbpg}</span>';
        $line = '<div class="woi-footer woi-running-footer">'
            . '<span>' . $shop_name . '</span> <span class="woi-dot">•</span> '
            . '<span>TRN ' . $trn . '</span> <span class="woi-dot">•</span> '
            . '<span>' . $website . '</span> <span class="woi-dot">•</span> '
            . '<span>' . $page . '</span>'
            . '</div>';
        // Define the named footer; visual-document.css assigns it to every page
        // via @page { footer: woiFooter } (the reliable all-pages method — the
        // <sethtmlpagefooter> tag only applies from the current page forward).
        return '<htmlpagefooter name="woiFooter">' . $line . '</htmlpagefooter>';
    }

    private function section_footer( $document ): string {
        $shop_name = esc_html( (string) $document->get_shop_name() );
        $trn       = esc_html( (string) $document->get_shop_vat_number() );
        $website   = esc_html( $this->shop_website() );
        return '<div class="woi-footer">'
            . '<span>' . $shop_name . '</span> <span class="woi-dot">•</span> '
            . '<span>TRN ' . $trn . '</span> <span class="woi-dot">•</span> '
            . '<span>' . $website . '</span> <span class="woi-dot">•</span> '
            . '<span>' . $this->bilabel( 'Page 1 of 1', 'صفحة ١ من ١' ) . '</span>'
            . '</div>';
    }

    /**
     * Replace all known tokens, then strip any leftover {{...}} so stray
     * braces never reach the PDF.
     */
    public function merge( string $html, $document ): string {
        $html = strtr( $html, $this->map( $document ) );
        return (string) preg_replace( '/\{\{[^}]*\}\}/', '', $html );
    }

    /**
     * Capture the output of an echo-style callback.
     * Returns '' and cleans the buffer if the callback throws, so one broken
     * renderer cannot fatal the whole PDF.
     */
    private function capture( callable $callback ): string {
        ob_start();
        try {
            $callback();
        } catch ( \Throwable $e ) {
            ob_end_clean();
            return '';
        }
        return (string) ob_get_clean();
    }

    /**
     * Public: render just the {{line_items}} table for a document. Lets the
     * column-save endpoint return only the part a column change affects, so the
     * editor canvas live-updates without re-rendering the whole document.
     */
    public function line_items( $document ): string {
        return $this->render_line_items( $document );
    }

    /** Overridable seam: fetch table headers (testable without Patchwork). */
    protected function fetch_table_headers( $document ): array { return (array) woi_pdf_templates_get_table_headers( $document ); }

    /** Overridable seam: fetch table body rows (testable without Patchwork). */
    protected function fetch_table_body( $document ): array { return (array) woi_pdf_templates_get_table_body( $document ); }

    /** Overridable seam: fetch totals rows (testable without Patchwork). */
    protected function fetch_totals( $document ): array { return (array) woi_pdf_templates_get_totals( $document ); }

    /** Whether the visual document's thumbnail column is enabled (author option). */
    private function show_thumbnails( $document ): bool {
        if ( ! function_exists( 'woi_pdf_visual_doc_options' ) ) {
            return true;
        }
        $type = method_exists( $document, 'get_type' ) ? (string) $document->get_type() : 'invoice';
        $opts = woi_pdf_visual_doc_options( $type );
        return 'off' !== ( $opts['thumbs'] ?? 'on' );
    }

    /** True when a column class list designates the product-thumbnail column. */
    private function is_thumbnail_class( $class ): bool {
        return false !== strpos( (string) $class, 'thumbnail' );
    }

    /**
     * Build the line-items table, mirroring the Standard UAE invoice markup.
     * Returns '' if any renderer throws so one error cannot fatal the whole PDF.
     */
    private function render_line_items( $document ): string {
        try {
            $headers = $this->fetch_table_headers( $document );
            $body    = $this->fetch_table_body( $document );

            // Thumbnails author option: mPDF cannot display:none a table column,
            // so drop the thumbnail header + cells at the source when it is off.
            // Scoped to the visual document (the classic template is untouched).
            $show_thumbs = $this->show_thumbnails( $document );

            $html = '<table class="order-details"><thead><tr>';
            foreach ( $headers as $header_data ) {
                if ( ! $show_thumbs && $this->is_thumbnail_class( $header_data['class'] ?? '' ) ) {
                    continue;
                }
                // Emit the configured column width / freeform style (same helper
                // the classic templates use) so per-column widths from the editor
                // are authoritative — without this the th only carries a class and
                // the width falls back to the static CSS, so an editor width edit
                // visibly "collapses back" once the server render replaces the
                // optimistic patch. table-layout:fixed makes these widths stick.
                $th_style = function_exists( 'woi_pdf_templates_maybe_apply_column_styles' )
                    ? woi_pdf_templates_maybe_apply_column_styles( $header_data, 'header' ) : '';
                $html .= '<th class="' . esc_attr( $header_data['class'] ?? '' ) . '"' . $th_style . '><span class="woi-lbl-primary">' . esc_html( $header_data['title'] ?? '' ) . '</span>';
                if ( ! empty( $header_data['secondary'] ) ) {
                    // <br> (not display:block) is what makes mPDF stack the pair —
                    // mPDF ignores display:block on inline <span>s inside a <th>.
                    $html .= '<br><span class="woi-lbl-secondary" dir="rtl">' . esc_html( $header_data['secondary'] ) . '</span>';
                }
                $html .= '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ( $body as $item_columns ) {
                $html .= '<tr>';
                foreach ( (array) $item_columns as $column_data ) {
                    if ( ! $show_thumbs && $this->is_thumbnail_class( $column_data['class'] ?? '' ) ) {
                        continue;
                    }
                    $td_style = function_exists( 'woi_pdf_templates_maybe_apply_column_styles' )
                        ? woi_pdf_templates_maybe_apply_column_styles( $column_data, 'cells' ) : '';
                    $html .= '<td class="' . esc_attr( $column_data['class'] ?? '' ) . '"' . $td_style . '><span>' . wp_kses_post( (string) ( $column_data['data'] ?? '' ) ) . '</span></td>';
                }
                $html .= '</tr>';
            }
            return $html . '</tbody></table>';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    /**
     * Build the totals table, mirroring the Standard UAE invoice markup.
     * Returns '' if any renderer throws so one error cannot fatal the whole PDF.
     */
    private function render_totals( $document ): string {
        try {
            $totals = $this->fetch_totals( $document );

            $html = '<table class="totals-table">';
            foreach ( $totals as $total_data ) {
                $html .= '<tr class="' . esc_attr( $total_data['class'] ?? '' ) . '">';
                $html .= '<th class="description"><span><span class="woi-lbl-primary">' . esc_html( $total_data['label'] ?? '' ) . '</span>';
                if ( ! empty( $total_data['secondary'] ) ) {
                    // <br> stacks the pair in mPDF (display:block on the span is ignored there).
                    $html .= '<br><span class="woi-lbl-secondary" dir="rtl">' . esc_html( $total_data['secondary'] ) . '</span>';
                }
                $html .= '</span></th>';
                $html .= '<td class="price"><span class="totals-price">' . wp_kses_post( (string) ( $total_data['value'] ?? '' ) ) . '</span></td>';
                $html .= '</tr>';
            }
            return $html . '</table>';
        } catch ( \Throwable $e ) {
            return '';
        }
    }
}

endif;
