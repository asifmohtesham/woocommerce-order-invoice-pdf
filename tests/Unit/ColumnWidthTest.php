<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ColumnWidthTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @dataProvider normalize_provider */
    public function test_normalize_column_width( $input, string $expected ): void {
        $this->assertSame( $expected, woi_pdf_templates_normalize_column_width( $input ) );
    }

    public function normalize_provider(): array {
        return array(
            'integer string'      => array( '20', '20' ),
            'decimal string'      => array( '20.5', '20.5' ),
            'trailing zeros'      => array( '50.00', '50' ),
            'half trailing zero'  => array( '12.50', '12.5' ),
            'float type'          => array( 12.5, '12.5' ),
            'max boundary'        => array( '100', '100' ),
            'zero is unset'       => array( '0', '' ),
            'over 100 is unset'   => array( '150', '' ),
            'negative is unset'   => array( '-10', '' ),
            'non-numeric is unset'=> array( 'abc', '' ),
            'empty is unset'      => array( '', '' ),
        );
    }

    private function stub_wp(): void {
        // esc_attr passes through; sanitize helper is a pure function in the same file.
        Functions\when( 'esc_attr' )->returnArg();
    }

    public function test_width_only_applies_to_header_and_cells(): void {
        $this->stub_wp();
        $column = array( 'width' => '20' );
        $this->assertSame( ' style="width: 20%;"', woi_pdf_templates_maybe_apply_column_styles( $column, 'header' ) );
        $this->assertSame( ' style="width: 20%;"', woi_pdf_templates_maybe_apply_column_styles( $column, 'cells' ) );
    }

    public function test_width_ignores_style_target(): void {
        $this->stub_wp();
        // Freeform style targets header only; width must still reach cells.
        $column = array(
            'style'        => 'color:#000000;',
            'style_target' => 'header',
            'width'        => '30',
        );
        $cells = woi_pdf_templates_maybe_apply_column_styles( $column, 'cells' );
        $this->assertStringContainsString( 'width: 30%;', $cells );
        $this->assertStringNotContainsString( 'color', $cells ); // freeform color gated out for cells
    }

    public function test_dedicated_width_wins_over_freeform_width(): void {
        $this->stub_wp();
        $column = array(
            'style'        => 'width:99%; color:#000000;',
            'style_target' => 'both',
            'width'        => '30',
        );
        $result = woi_pdf_templates_maybe_apply_column_styles( $column, 'cells' );
        $this->assertStringContainsString( 'width: 30%;', $result );
        $this->assertStringNotContainsString( '99%', $result );
        $this->assertStringContainsString( 'color: #000000;', $result );
    }

    public function test_invalid_width_with_no_style_returns_empty(): void {
        $this->stub_wp();
        $this->assertSame( '', woi_pdf_templates_maybe_apply_column_styles( array( 'width' => '150' ), 'cells' ) );
    }

    public function test_no_width_no_style_returns_empty(): void {
        $this->stub_wp();
        $this->assertSame( '', woi_pdf_templates_maybe_apply_column_styles( array(), 'cells' ) );
    }

    public function test_freeform_style_unchanged_when_no_width(): void {
        $this->stub_wp();
        $column = array( 'style' => 'color:#000000;', 'style_target' => 'both' );
        $this->assertSame( ' style="color: #000000;"', woi_pdf_templates_maybe_apply_column_styles( $column, 'cells' ) );
    }
}
