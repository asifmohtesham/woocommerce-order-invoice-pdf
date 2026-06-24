<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * woi_pdf_visual_options_css() emits the flat-selector override layer for the
 * visual document (mPDF + canvas). These guard the opt-in appearance toggles.
 */
class VisualOptionsCssTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function base(): array {
        return array(
            'accent' => 'navy', 'header' => 'center', 'density' => 'comfortable',
            'arabic' => 'on', 'thumbs' => 'on', 'font' => 'grotesque',
            'borders' => 'off', 'stripes' => 'off',
        );
    }

    public function test_borders_on_emits_column_gridlines(): void {
        $css = woi_pdf_visual_options_css( array( 'borders' => 'on' ) + $this->base() );
        $this->assertStringContainsString( '.order-details thead th,.order-details tbody td{border-left:0.5pt solid #D9D4C9', $css );
    }

    public function test_stripes_on_emits_zebra_background(): void {
        $css = woi_pdf_visual_options_css( array( 'stripes' => 'on' ) + $this->base() );
        $this->assertStringContainsString( '.order-details tbody tr:nth-child(even) td{background-color:#F6F3EC}', $css );
    }

    public function test_borders_and_stripes_off_by_default(): void {
        $css = woi_pdf_visual_options_css( $this->base() );
        $this->assertStringNotContainsString( 'nth-child(even)', $css );
        $this->assertStringNotContainsString( 'border-left:0.5pt', $css );
    }

    public function test_repeat_letterhead_on_emits_page_header_rule(): void {
        $css = woi_pdf_visual_options_css( array( 'repeat_letterhead' => 'on' ) + $this->base() );
        $this->assertStringContainsString( '@page { header: woiHeader;', $css );
        $this->assertStringContainsString( 'margin-top: 34mm', $css );
    }

    public function test_repeat_letterhead_off_emits_no_header_rule(): void {
        $css = woi_pdf_visual_options_css( $this->base() );
        $this->assertStringNotContainsString( 'woiHeader', $css );
    }

    public function test_row_color_set_colours_body_cells_and_overrides_muted_columns(): void {
        $css = woi_pdf_visual_options_css( array( 'row_color' => '#222222' ) + $this->base() );
        // Generic body cells (inner name/meta spans inherit the colour) plus a
        // higher-specificity override for the two muted-by-default columns.
        $this->assertStringContainsString( '.order-details tbody td,', $css );
        $this->assertStringContainsString( '.order-details tbody td.position', $css );
        $this->assertStringContainsString( '.order-details tbody td.tax_rate', $css );
        $this->assertStringContainsString( 'color:#222222', $css );
    }

    public function test_row_color_empty_emits_no_body_colour_rule(): void {
        $css = woi_pdf_visual_options_css( $this->base() );
        $this->assertStringNotContainsString( '.order-details tbody td.position', $css );
        $this->assertStringNotContainsString( '.order-details tbody td.tax_rate{color', $css );
    }
}
