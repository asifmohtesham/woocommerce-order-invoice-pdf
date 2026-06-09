<?php
namespace WOI\PDF;

use function apply_filters;
use function locate_template;
use function trailingslashit;
use function file_exists;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\TemplateLoader' ) ) :

class TemplateLoader {

    private string $plugin_path;

    public function __construct( string $plugin_path ) {
        $this->plugin_path = $plugin_path;
    }

    /**
     * Locate the template file for a given document type, template name, and template folder.
     * Checks the active theme first, then falls back to the plugin's templates/ directory.
     *
     * @param string $document_type   e.g. 'invoice'
     * @param string $template_name   e.g. 'invoice.php'
     * @param string $template_folder e.g. 'Simple'
     * @return string Absolute file path, or empty string if not found.
     */
    public function locate( string $document_type, string $template_name, string $template_folder ): string {
        $template_folder = apply_filters( 'woi_pdf_template_folder', $template_folder, $document_type );
        $template_name   = apply_filters( 'woi_pdf_template_name', $template_name, $document_type );

        // 1. Theme override: {theme}/woocommerce-orders-invoice-pdf/{folder}/{file}
        $theme_path = locate_template( array(
            trailingslashit( 'woocommerce-orders-invoice-pdf/' . $template_folder ) . $template_name,
        ) );

        if ( $theme_path ) {
            return apply_filters( 'woi_pdf_template_path', $theme_path, $document_type, $template_name );
        }

        // 2. Plugin templates/ directory
        $plugin_template = trailingslashit( $this->plugin_path . '/templates/' . $template_folder ) . $template_name;

        if ( file_exists( $plugin_template ) ) {
            return apply_filters( 'woi_pdf_template_path', $plugin_template, $document_type, $template_name );
        }

        return '';
    }
}

endif;
