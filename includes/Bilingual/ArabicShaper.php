<?php
namespace WOI\PDF\Bilingual;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Pre-shapes Arabic text into Unicode presentation forms (glyph joining) and
 * reorders it for visual (right-to-left) display.
 *
 * WHY THIS EXISTS
 * ---------------
 * Dompdf has no Arabic glyph-shaping or bidirectional (bidi) engine. It draws
 * each codepoint in its *isolated* form, left-to-right, in logical (storage)
 * order. The result is disconnected, reversed Arabic — even with a correct
 * Naskh font loaded. mPDF shapes natively, but this plugin renders with Dompdf
 * (see the engine note in project memory), so the text must be shaped *before*
 * it reaches Dompdf.
 *
 * This class converts each Arabic letter to its correct contextual presentation
 * form (isolated / initial / medial / final, plus the Lam-Alef ligatures) and
 * then reverses the run so Dompdf's left-to-right glyph placement produces a
 * visually correct right-to-left word.
 *
 * SCOPE
 * -----
 * Invoked from PDFMaker only (the PDF/preview path). HTML and email output go
 * straight to the browser, which does its own shaping, so they are never passed
 * through here — double-shaping would corrupt them.
 */
if ( ! class_exists( '\\WOI\\PDF\\Bilingual\\ArabicShaper' ) ) :

class ArabicShaper {

	/**
	 * base codepoint => [ isolated, initial, medial, final ] presentation forms.
	 *
	 * Letters that never connect to the following letter still keep their
	 * initial/medial slots populated (with the isolated/final glyph) so the
	 * lookup is always safe; those slots are simply never selected for them.
	 */
	private const FORMS = array(
		0x0621 => array( 0xFE80, 0xFE80, 0xFE80, 0xFE80 ), // Hamza (no joining)
		0x0622 => array( 0xFE81, 0xFE81, 0xFE82, 0xFE82 ), // Alef Madda
		0x0623 => array( 0xFE83, 0xFE83, 0xFE84, 0xFE84 ), // Alef Hamza above
		0x0624 => array( 0xFE85, 0xFE85, 0xFE86, 0xFE86 ), // Waw Hamza
		0x0625 => array( 0xFE87, 0xFE87, 0xFE88, 0xFE88 ), // Alef Hamza below
		0x0626 => array( 0xFE89, 0xFE8B, 0xFE8C, 0xFE8A ), // Yeh Hamza
		0x0627 => array( 0xFE8D, 0xFE8D, 0xFE8E, 0xFE8E ), // Alef
		0x0628 => array( 0xFE8F, 0xFE91, 0xFE92, 0xFE90 ), // Beh
		0x0629 => array( 0xFE93, 0xFE93, 0xFE94, 0xFE94 ), // Teh Marbuta
		0x062A => array( 0xFE95, 0xFE97, 0xFE98, 0xFE96 ), // Teh
		0x062B => array( 0xFE99, 0xFE9B, 0xFE9C, 0xFE9A ), // Theh
		0x062C => array( 0xFE9D, 0xFE9F, 0xFEA0, 0xFE9E ), // Jeem
		0x062D => array( 0xFEA1, 0xFEA3, 0xFEA4, 0xFEA2 ), // Hah
		0x062E => array( 0xFEA5, 0xFEA7, 0xFEA8, 0xFEA6 ), // Khah
		0x062F => array( 0xFEA9, 0xFEA9, 0xFEAA, 0xFEAA ), // Dal
		0x0630 => array( 0xFEAB, 0xFEAB, 0xFEAC, 0xFEAC ), // Thal
		0x0631 => array( 0xFEAD, 0xFEAD, 0xFEAE, 0xFEAE ), // Reh
		0x0632 => array( 0xFEAF, 0xFEAF, 0xFEB0, 0xFEB0 ), // Zain
		0x0633 => array( 0xFEB1, 0xFEB3, 0xFEB4, 0xFEB2 ), // Seen
		0x0634 => array( 0xFEB5, 0xFEB7, 0xFEB8, 0xFEB6 ), // Sheen
		0x0635 => array( 0xFEB9, 0xFEBB, 0xFEBC, 0xFEBA ), // Sad
		0x0636 => array( 0xFEBD, 0xFEBF, 0xFEC0, 0xFEBE ), // Dad
		0x0637 => array( 0xFEC1, 0xFEC3, 0xFEC4, 0xFEC2 ), // Tah
		0x0638 => array( 0xFEC5, 0xFEC7, 0xFEC8, 0xFEC6 ), // Zah
		0x0639 => array( 0xFEC9, 0xFECB, 0xFECC, 0xFECA ), // Ain
		0x063A => array( 0xFECD, 0xFECF, 0xFED0, 0xFECE ), // Ghain
		0x0641 => array( 0xFED1, 0xFED3, 0xFED4, 0xFED2 ), // Feh
		0x0642 => array( 0xFED5, 0xFED7, 0xFED8, 0xFED6 ), // Qaf
		0x0643 => array( 0xFED9, 0xFEDB, 0xFEDC, 0xFEDA ), // Kaf
		0x0644 => array( 0xFEDD, 0xFEDF, 0xFEE0, 0xFEDE ), // Lam
		0x0645 => array( 0xFEE1, 0xFEE3, 0xFEE4, 0xFEE2 ), // Meem
		0x0646 => array( 0xFEE5, 0xFEE7, 0xFEE8, 0xFEE6 ), // Noon
		0x0647 => array( 0xFEE9, 0xFEEB, 0xFEEC, 0xFEEA ), // Heh
		0x0648 => array( 0xFEED, 0xFEED, 0xFEEE, 0xFEEE ), // Waw
		0x0649 => array( 0xFEEF, 0xFEEF, 0xFEF0, 0xFEF0 ), // Alef Maksura
		0x064A => array( 0xFEF1, 0xFEF3, 0xFEF4, 0xFEF2 ), // Yeh
	);

	/** Letters that connect to the FOLLOWING letter. */
	private const JOIN_NEXT = array(
		0x0626, 0x0628, 0x062A, 0x062B, 0x062C, 0x062D, 0x062E,
		0x0633, 0x0634, 0x0635, 0x0636, 0x0637, 0x0638, 0x0639, 0x063A,
		0x0641, 0x0642, 0x0643, 0x0644, 0x0645, 0x0646, 0x0647, 0x064A,
	);

	/**
	 * Lam-Alef ligatures: alef codepoint => [ isolated, final ] glyph.
	 * Selected when a Lam (0x0644) is immediately followed by one of these.
	 */
	private const LAM_ALEF = array(
		0x0622 => array( 0xFEF5, 0xFEF6 ),
		0x0623 => array( 0xFEF7, 0xFEF8 ),
		0x0625 => array( 0xFEF9, 0xFEFA ),
		0x0627 => array( 0xFEFB, 0xFEFC ),
	);

	/** Combining marks (harakat / tashkeel) that are transparent to joining. */
	private static function is_mark( int $cp ): bool {
		return ( $cp >= 0x064B && $cp <= 0x0652 ) || 0x0670 === $cp || ( $cp >= 0x0653 && $cp <= 0x065F );
	}

	private static function joins_next( int $cp ): bool {
		return in_array( $cp, self::JOIN_NEXT, true );
	}

	/** Every shaped letter can join to a preceding letter except the bare Hamza. */
	private static function joins_prev( int $cp ): bool {
		return isset( self::FORMS[ $cp ] ) && 0x0621 !== $cp;
	}

	/**
	 * Shape every Arabic text run inside an HTML fragment, leaving tags,
	 * attributes and non-Arabic text untouched.
	 *
	 * Splits on tag boundaries and only rewrites text segments that actually
	 * contain Arabic, so CSS, class names and Latin content are never altered.
	 */
	public static function shape_html( string $html ): string {
		if ( ! self::has_arabic( $html ) ) {
			return $html;
		}
		if ( ! apply_filters( 'woi_pdf_shape_arabic', true ) ) {
			return $html;
		}

		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}

		foreach ( $parts as $i => $part ) {
			if ( '' === $part || '<' === $part[0] ) {
				continue; // tag — leave as-is
			}
			if ( ! self::has_arabic( $part ) ) {
				continue;
			}
			$parts[ $i ] = self::shape( $part );
		}

		return implode( '', $parts );
	}

	private static function has_arabic( string $text ): bool {
		return (bool) preg_match( '/[\x{0600}-\x{06FF}]/u', $text );
	}

	/**
	 * Shape a single logical string and return it in visual (RTL) order.
	 */
	public static function shape( string $text ): string {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $chars ) ) {
			return $text;
		}

		// Decompose into clusters: a base codepoint plus any trailing marks.
		$bases = array();
		$marks = array();
		foreach ( $chars as $ch ) {
			$cp = self::ord( $ch );
			if ( self::is_mark( $cp ) && ! empty( $bases ) ) {
				$marks[ count( $bases ) - 1 ] .= $ch;
				continue;
			}
			$bases[] = $cp;
			$marks[] = '';
		}

		$n   = count( $bases );
		$out = array(); // logical-order codepoints after shaping

		for ( $i = 0; $i < $n; $i++ ) {
			$cp = $bases[ $i ];

			if ( ! isset( self::FORMS[ $cp ] ) ) {
				$out[] = $cp; // non-Arabic letter: passthrough
				if ( '' !== $marks[ $i ] ) {
					foreach ( preg_split( '//u', $marks[ $i ], -1, PREG_SPLIT_NO_EMPTY ) as $m ) {
						$out[] = self::ord( $m );
					}
				}
				continue;
			}

			$prev_connects = $i > 0 && isset( self::FORMS[ $bases[ $i - 1 ] ] )
				&& self::joins_next( $bases[ $i - 1 ] ) && self::joins_prev( $cp );

			// Lam-Alef ligature: consume the following Alef into one glyph.
			if ( 0x0644 === $cp && $i + 1 < $n && isset( self::LAM_ALEF[ $bases[ $i + 1 ] ] ) ) {
				$lig    = self::LAM_ALEF[ $bases[ $i + 1 ] ];
				$out[]  = $prev_connects ? $lig[1] : $lig[0];
				$i++; // skip the alef
				continue;
			}

			$next_connects = $i + 1 < $n && isset( self::FORMS[ $bases[ $i + 1 ] ] )
				&& self::joins_next( $cp ) && self::joins_prev( $bases[ $i + 1 ] );

			$forms = self::FORMS[ $cp ];
			if ( $prev_connects && $next_connects ) {
				$out[] = $forms[2]; // medial
			} elseif ( $prev_connects ) {
				$out[] = $forms[3]; // final
			} elseif ( $next_connects ) {
				$out[] = $forms[1]; // initial
			} else {
				$out[] = $forms[0]; // isolated
			}

			if ( '' !== $marks[ $i ] ) {
				foreach ( preg_split( '//u', $marks[ $i ], -1, PREG_SPLIT_NO_EMPTY ) as $m ) {
					$out[] = self::ord( $m );
				}
			}
		}

		return self::reorder_visual( $out );
	}

	/**
	 * Convert shaped logical-order codepoints into a visually ordered string.
	 *
	 * Base direction is RTL, so the whole run is reversed (Dompdf then lays the
	 * glyphs out left-to-right into the correct right-to-left appearance). Any
	 * embedded Latin/numeric run is re-reversed so it still reads left-to-right.
	 */
	private static function reorder_visual( array $out ): string {
		$out = array_reverse( $out );

		$len = count( $out );
		$i   = 0;
		while ( $i < $len ) {
			if ( self::is_ltr( $out[ $i ] ) ) {
				$j = $i;
				while ( $j < $len && self::is_ltr( $out[ $j ] ) ) {
					$j++;
				}
				$slice = array_reverse( array_slice( $out, $i, $j - $i ) );
				array_splice( $out, $i, $j - $i, $slice );
				$i = $j;
			} else {
				$i++;
			}
		}

		$str = '';
		foreach ( $out as $cp ) {
			$str .= self::chr( $cp );
		}
		return $str;
	}

	/** ASCII letters and digits form left-to-right runs that must be un-reversed. */
	private static function is_ltr( int $cp ): bool {
		return ( $cp >= 0x0030 && $cp <= 0x0039 ) // 0-9
			|| ( $cp >= 0x0041 && $cp <= 0x005A )   // A-Z
			|| ( $cp >= 0x0061 && $cp <= 0x007A );  // a-z
	}

	private static function ord( string $ch ): int {
		return function_exists( 'mb_ord' ) ? (int) mb_ord( $ch, 'UTF-8' ) : self::ord_fallback( $ch );
	}

	private static function chr( int $cp ): string {
		return function_exists( 'mb_chr' ) ? (string) mb_chr( $cp, 'UTF-8' ) : self::chr_fallback( $cp );
	}

	private static function ord_fallback( string $ch ): int {
		$code = 0;
		$bytes = unpack( 'C*', $ch );
		$bytes = array_values( $bytes );
		$first = $bytes[0];
		if ( $first < 0x80 ) {
			return $first;
		} elseif ( $first < 0xE0 ) {
			$code = ( $first & 0x1F ) << 6 | ( $bytes[1] & 0x3F );
		} elseif ( $first < 0xF0 ) {
			$code = ( $first & 0x0F ) << 12 | ( $bytes[1] & 0x3F ) << 6 | ( $bytes[2] & 0x3F );
		} else {
			$code = ( $first & 0x07 ) << 18 | ( $bytes[1] & 0x3F ) << 12 | ( $bytes[2] & 0x3F ) << 6 | ( $bytes[3] & 0x3F );
		}
		return $code;
	}

	private static function chr_fallback( int $cp ): string {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		} elseif ( $cp < 0x800 ) {
			return chr( 0xC0 | $cp >> 6 ) . chr( 0x80 | $cp & 0x3F );
		} elseif ( $cp < 0x10000 ) {
			return chr( 0xE0 | $cp >> 12 ) . chr( 0x80 | $cp >> 6 & 0x3F ) . chr( 0x80 | $cp & 0x3F );
		}
		return chr( 0xF0 | $cp >> 18 ) . chr( 0x80 | $cp >> 12 & 0x3F ) . chr( 0x80 | $cp >> 6 & 0x3F ) . chr( 0x80 | $cp & 0x3F );
	}
}

endif; // class_exists
