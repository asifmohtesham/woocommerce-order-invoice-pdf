<?php
namespace WOI\PDF\Visual;

use WOI\PDF\Bilingual\BilingualEngine;
use function esc_html;
use function esc_attr;
use function woi_pdf_templates_get_table_headers;
use function woi_pdf_templates_get_table_body;
use function woi_pdf_templates_get_totals;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\TemplateTokens' ) ) :

class TemplateTokens {

    /**
     * Build the {{token}} => replacement map for a document.
     *
     * Scalar tokens are esc_html()'d. Block tokens (logo, billing_address,
     * line_items, totals) are trusted HTML produced by existing renderers.
     *
     * @param object $document An OrderDocument (or compatible stub).
     * @return array<string,string>
     */
    public function map( $document ): array {
        $engine = BilingualEngine::instance();

        return array(
            '{{logo}}'             => $document->has_header_logo() ? $this->capture( array( $document, 'header_logo' ) ) : '',
            '{{shop_name}}'        => esc_html( (string) $document->get_shop_name() ),
            '{{shop_address}}'     => esc_html( (string) $document->get_shop_address() ),
            '{{shop_name_ar}}'     => esc_html( $engine->secondary_shop_name() ),
            '{{shop_address_ar}}'  => esc_html( $engine->secondary_shop_address() ),
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
            '{{line_items}}'       => $this->render_line_items( $document ),
            '{{totals}}'           => $this->render_totals( $document ),
        );
    }

    /**
     * Replace all known tokens, then strip any leftover {{...}} so stray
     * braces never reach the PDF.
     */
    public function merge( string $html, $document ): string {
        $html = strtr( $html, $this->map( $document ) );
        return (string) preg_replace( '/\{\{[^}]*\}\}/', '', $html );
    }

    /** Capture the output of an echo-style callback. */
    private function capture( callable $callback ): string {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }

    /** Build the line-items table, mirroring the Standard UAE invoice markup. */
    private function render_line_items( $document ): string {
        $headers = (array) woi_pdf_templates_get_table_headers( $document );
        $body    = (array) woi_pdf_templates_get_table_body( $document );

        $html = '<table class="order-details"><thead><tr>';
        foreach ( $headers as $header_data ) {
            $html .= '<th class="' . esc_attr( $header_data['class'] ?? '' ) . '">' . esc_html( $header_data['title'] ?? '' );
            if ( ! empty( $header_data['secondary'] ) ) {
                $html .= '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $header_data['secondary'] ) . '</span>';
            }
            $html .= '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $body as $item_columns ) {
            $html .= '<tr>';
            foreach ( (array) $item_columns as $column_data ) {
                $html .= '<td class="' . esc_attr( $column_data['class'] ?? '' ) . '"><span>' . esc_html( $column_data['data'] ?? '' ) . '</span></td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** Build the totals table, mirroring the Standard UAE invoice markup. */
    private function render_totals( $document ): string {
        $totals = (array) woi_pdf_templates_get_totals( $document );

        $html = '<table class="totals-table">';
        foreach ( $totals as $total_data ) {
            $html .= '<tr class="' . esc_attr( $total_data['class'] ?? '' ) . '">';
            $html .= '<th class="description"><span>' . esc_html( $total_data['label'] ?? '' );
            if ( ! empty( $total_data['secondary'] ) ) {
                $html .= '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $total_data['secondary'] ) . '</span>';
            }
            $html .= '</span></th>';
            $html .= '<td class="price"><span class="totals-price">' . esc_html( $total_data['value'] ?? '' ) . '</span></td>';
            $html .= '</tr>';
        }
        return $html . '</table>';
    }
}

endif;
