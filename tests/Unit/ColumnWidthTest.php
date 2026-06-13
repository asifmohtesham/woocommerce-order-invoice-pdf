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
}
