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
