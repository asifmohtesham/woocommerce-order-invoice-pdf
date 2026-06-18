<?php
namespace WOI\PDF\Tests\Unit;

use PHPUnit\Framework\TestCase;

class CssLengthTest extends TestCase {

	/**
	 * A unit-less number must gain a "cm" unit so mPDF honours the logo
	 * max-height (a bare number is an invalid CSS length that mPDF would
	 * otherwise ignore, leaving the logo at full size).
	 *
	 * @dataProvider lengths
	 */
	public function test_normalize_css_length( string $in, string $expected ): void {
		$this->assertSame( $expected, woi_pdf_normalize_css_length( $in ) );
	}

	public function lengths(): array {
		return array(
			'bare integer'  => array( '3', '3cm' ),
			'bare decimal'  => array( '3.5', '3.5cm' ),
			'already cm'    => array( '3cm', '3cm' ),
			'millimetres'   => array( '25mm', '25mm' ),
			'pixels'        => array( '40px', '40px' ),
			'percent'       => array( '50%', '50%' ),
			'empty'         => array( '', '' ),
		);
	}
}
