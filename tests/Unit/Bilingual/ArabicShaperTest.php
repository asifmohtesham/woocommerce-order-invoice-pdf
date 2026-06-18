<?php
namespace WOI\PDF\Tests\Unit\Bilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Bilingual\ArabicShaper;

class ArabicShaperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Convert a shaped string into an array of uppercase hex codepoints. */
	private function cps( string $s ): array {
		$out = array();
		foreach ( preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY ) as $c ) {
			$out[] = sprintf( '%04X', mb_ord( $c, 'UTF-8' ) );
		}
		return $out;
	}

	public function test_three_letter_word_joins_initial_medial_final(): void {
		// بتم : Beh (initial) + Teh (medial) + Meem (final), then reversed for RTL.
		$this->assertSame(
			array( 'FEE2', 'FE98', 'FE91' ),
			$this->cps( ArabicShaper::shape( 'بتم' ) )
		);
	}

	public function test_no_isolated_forms_in_connected_word(): void {
		// الكمية should contain no isolated medial-capable letters; every interior
		// letter must be a joined (FB50-FEFF) presentation form.
		$shaped = ArabicShaper::shape( 'الكمية' );
		$this->assertDoesNotMatchRegularExpression( '/[\x{0600}-\x{06FF}]/u', $shaped, 'raw Arabic letters remain unshaped' );
		$this->assertMatchesRegularExpression( '/[\x{FB50}-\x{FEFF}]/u', $shaped );
	}

	public function test_lam_alef_ligature_is_emitted(): void {
		// الاستحقاق contains a Lam+Alef sequence that must collapse to one ligature glyph.
		$shaped = ArabicShaper::shape( 'الاستحقاق' );
		$this->assertStringContainsString( mb_chr( 0xFEFB, 'UTF-8' ), $shaped, 'expected isolated Lam-Alef ligature' );
	}

	public function test_latin_and_digits_kept_left_to_right(): void {
		// A trailing % must end up on the LEFT of the visual string (RTL base),
		// while any Latin/number run keeps its own left-to-right order.
		$shaped = ArabicShaper::shape( 'معدل الضريبة %' );
		$this->assertStringStartsWith( '%', $shaped );
	}

	public function test_shape_html_only_touches_arabic_text_nodes(): void {
		$html   = '<span class="x" dir="rtl">الكمية</span><td>AED 40.00</td>';
		$result = ArabicShaper::shape_html( $html );

		// Tags, class names and Latin content are untouched.
		$this->assertStringContainsString( '<span class="x" dir="rtl">', $result );
		$this->assertStringContainsString( '<td>AED 40.00</td>', $result );
		// The raw Arabic word is gone, replaced by presentation forms.
		$this->assertStringNotContainsString( 'الكمية', $result );
		$this->assertMatchesRegularExpression( '/[\x{FB50}-\x{FEFF}]/u', $result );
	}

	public function test_non_arabic_html_passes_through_unchanged(): void {
		$html = '<table class="order-details"><td>AED 960.00</td></table>';
		$this->assertSame( $html, ArabicShaper::shape_html( $html ) );
	}
}
