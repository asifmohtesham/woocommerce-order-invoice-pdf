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
}
