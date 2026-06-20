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
        $stored = get_option( $this->option_name( $doc_type ) );
        return is_string( $stored ) ? $stored : '';
    }

    public function save( string $doc_type, string $html ): void {
        $clean = wp_kses( $html, $this->allowed_html() );
        update_option( $this->option_name( $doc_type ), $clean, false );
    }

    public function blocks_markup_option_name( string $doc_type ): string {
        return 'woi_pdf_visual_blocks_' . preg_replace( '/[^a-z0-9_]/', '', $doc_type );
    }

    public function blocks_html_option_name( string $doc_type ): string {
        return 'woi_pdf_visual_blocks_html_' . preg_replace( '/[^a-z0-9_]/', '', $doc_type );
    }

    public function active_source_option_name(): string {
        return 'woi_pdf_visual_active_source';
    }

    public function get_blocks_markup( string $doc_type ): string {
        $stored = get_option( $this->blocks_markup_option_name( $doc_type ) );
        return is_string( $stored ) ? $stored : '';
    }

    public function get_blocks_html( string $doc_type ): string {
        $stored = get_option( $this->blocks_html_option_name( $doc_type ) );
        return is_string( $stored ) ? $stored : '';
    }

    /**
     * Store both the round-trip block markup (raw) and the rendered HTML
     * (kses-cleaned, tokens preserved). Both unautoloaded.
     */
    public function save_blocks( string $doc_type, string $markup, string $rendered_html ): void {
        update_option( $this->blocks_markup_option_name( $doc_type ), $markup, false );
        $clean = wp_kses( $rendered_html, $this->allowed_html() );
        update_option( $this->blocks_html_option_name( $doc_type ), $clean, false );
    }

    /** 'grapesjs' (default) or 'blocks'. */
    public function get_active_source(): string {
        $source = get_option( $this->active_source_option_name() );
        return ( 'blocks' === $source ) ? 'blocks' : 'grapesjs';
    }

    /** Silently ignores anything other than the two valid sources. */
    public function set_active_source( string $source ): void {
        if ( 'grapesjs' === $source || 'blocks' === $source ) {
            update_option( $this->active_source_option_name(), $source, false );
        }
    }

    /** Rendered HTML for whichever source is active (what the render path consumes). */
    public function get_active( string $doc_type ): string {
        return ( 'blocks' === $this->get_active_source() )
            ? $this->get_blocks_html( $doc_type )
            : $this->get( $doc_type );
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
            'colgroup' => $common, 'col' => $common + array( 'span' => true, 'width' => true ),
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
