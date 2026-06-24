<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

// The pure helpers live in a dependency-free file; require it directly because
// the harness loads composer's autoloader before ABSPATH, which makes
// woi-pdf-functions.php's own require_once a no-op (see project memory).
require_once dirname( __DIR__, 2 ) . '/includes/amount-in-words.php';

/**
 * Covers the pure-PHP amount-in-words helpers that back the default
 * `woi_pdf_amount_in_words` filter (woi-pdf-functions.php).
 */
class AmountInWordsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// woi_pdf_amount_to_words runs the currency-unit map through a filter.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @dataProvider integerProvider */
	public function test_integer_to_words( int $n, string $expected ): void {
		$this->assertSame( $expected, woi_pdf_integer_to_words( $n ) );
	}

	public function integerProvider(): array {
		return array(
			array( 0, 'zero' ),
			array( 7, 'seven' ),
			array( 10, 'ten' ),
			array( 19, 'nineteen' ),
			array( 20, 'twenty' ),
			array( 21, 'twenty-one' ),
			array( 100, 'one hundred' ),
			array( 105, 'one hundred five' ),
			array( 710, 'seven hundred ten' ),
			array( 1000, 'one thousand' ),
			array( 1710, 'one thousand seven hundred ten' ),
			array( 1000000, 'one million' ),
			array( 1234567, 'one million two hundred thirty-four thousand five hundred sixty-seven' ),
		);
	}

	public function test_aed_amount_with_zero_fils(): void {
		$this->assertSame(
			'UAE Dirhams One Thousand Seven Hundred Ten and Zero Fils only',
			woi_pdf_amount_to_words( 1710.00, 'AED' )
		);
	}

	public function test_aed_amount_with_fils(): void {
		$this->assertSame(
			'UAE Dirhams Forty-Two and Fifty Fils only',
			woi_pdf_amount_to_words( 42.50, 'AED' )
		);
	}

	public function test_rounds_to_subunit_with_carry(): void {
		// 9.999 rounds up to 10.00 — the carry must roll into the whole part.
		$this->assertSame(
			'UAE Dirhams Ten and Zero Fils only',
			woi_pdf_amount_to_words( 9.999, 'AED' )
		);
	}

	public function test_usd_currency_uses_dollars_and_cents(): void {
		$this->assertSame(
			'US Dollars Five and Ninety-Nine Cents only',
			woi_pdf_amount_to_words( 5.99, 'USD' )
		);
	}

	public function test_unknown_currency_falls_back_to_code(): void {
		$this->assertSame(
			'XYZ One Hundred and Zero Cents only',
			woi_pdf_amount_to_words( 100, 'XYZ' )
		);
	}

	public function test_default_filter_respects_explicit_override(): void {
		$doc = new \stdClass();
		$this->assertSame( 'custom', woi_pdf_default_amount_in_words( 'custom', $doc ) );
	}

	public function test_default_filter_spells_order_total(): void {
		$order = new class {
			public function get_total() { return '1710.00'; }
			public function get_currency() { return 'AED'; }
		};
		$doc = new \stdClass();
		$doc->order = $order;
		$this->assertSame(
			'UAE Dirhams One Thousand Seven Hundred Ten and Zero Fils only',
			woi_pdf_default_amount_in_words( '', $doc )
		);
	}

	public function test_default_filter_no_order_returns_empty(): void {
		$doc = new \stdClass();
		$this->assertSame( '', woi_pdf_default_amount_in_words( '', $doc ) );
	}
}
