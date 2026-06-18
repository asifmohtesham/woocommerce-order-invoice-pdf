<?php
namespace WOI\PDF\Visual;

use function get_option;
use function update_option;
use function wp_kses;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\VisualTemplateStore' ) ) :

class VisualTemplateStore {

    public function option_name( string $doc_type ): string {
        return 'woi_pdf_visual_template_' . preg_replace( '/[^a-z0-9_]/', '', $doc_type );
    }

    public function get( string $doc_type ): string {
        $stored = get_option( $this->option_name( $doc_type ), '' );
        return is_string( $stored ) ? $stored : '';
    }

    public function save( string $doc_type, string $html ): void {
        $clean = wp_kses( $html, $this->allowed_html() );
        update_option( $this->option_name( $doc_type ), $clean, false );
    }

    /**
     * kses allowlist for GrapesJS output. Covers tables, common block/inline
     * tags, images, and a <style> element. The {{token}} brace syntax is plain
     * text content/attribute data, which kses leaves untouched.
     *
     * @return array<string,array<string,bool>>
     */
    public function allowed_html(): array {
        $common = array(
            'class' => true,
            'id'    => true,
            'style' => true,
            'dir'   => true,
        );

        return array(
            'table' => $common, 'thead' => $common, 'tbody' => $common, 'tfoot' => $common,
            'tr' => $common, 'td' => $common + array( 'colspan' => true, 'rowspan' => true ),
            'th' => $common + array( 'colspan' => true, 'rowspan' => true ),
            'div' => $common, 'span' => $common, 'p' => $common,
            'h1' => $common, 'h2' => $common, 'h3' => $common,
            'h4' => $common, 'h5' => $common, 'h6' => $common,
            'strong' => $common, 'em' => $common, 'b' => $common, 'i' => $common,
            'br' => array(), 'hr' => $common,
            'ul' => $common, 'ol' => $common, 'li' => $common,
            'img' => $common + array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true ),
            'style' => array( 'type' => true ),
        );
    }
}

endif;
