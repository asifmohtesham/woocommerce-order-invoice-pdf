<?php
/**
 * Amount-in-words helpers (pure PHP — no WordPress, no ext-intl).
 *
 * Kept in its own dependency-free file (no ABSPATH guard) so it can be unit
 * tested in isolation. woi-pdf-functions.php require_once's this file inside the
 * WordPress bootstrap and registers the `woi_pdf_amount_in_words` filter; tests
 * require it directly. The intl extension is not guaranteed on the host (cf.
 * EditorMain::php_intl_check), so the speller is hand-rolled.
 */

if ( ! function_exists( 'woi_pdf_integer_to_words' ) ) {
	/**
	 * Spell a non-negative integer in English words. Returns lowercase,
	 * space-separated words with hyphenated tens-and-ones, e.g. 1234 ->
	 * "one thousand two hundred thirty-four". Capitalisation is the caller's job.
	 *
	 * @param int $number Integer to spell. Negatives are prefixed with "minus".
	 * @return string
	 */
	function woi_pdf_integer_to_words( $number ) {
		$number = (int) $number;
		if ( $number < 0 ) {
			return 'minus ' . woi_pdf_integer_to_words( - $number );
		}
		$ones = array( 'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen' );
		$tens = array( '', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety' );

		if ( $number < 20 ) {
			return $ones[ $number ];
		}
		if ( $number < 100 ) {
			$rem = $number % 10;
			return $tens[ intdiv( $number, 10 ) ] . ( $rem ? '-' . $ones[ $rem ] : '' );
		}
		if ( $number < 1000 ) {
			$rem = $number % 100;
			return $ones[ intdiv( $number, 100 ) ] . ' hundred' . ( $rem ? ' ' . woi_pdf_integer_to_words( $rem ) : '' );
		}
		// Largest scale first so the recursion peels off one group at a time.
		$scales = array(
			1000000000000 => 'trillion',
			1000000000    => 'billion',
			1000000       => 'million',
			1000          => 'thousand',
		);
		foreach ( $scales as $value => $name ) {
			if ( $number >= $value ) {
				$rem = $number % $value;
				return woi_pdf_integer_to_words( intdiv( $number, $value ) ) . ' ' . $name . ( $rem ? ' ' . woi_pdf_integer_to_words( $rem ) : '' );
			}
		}
		return $ones[0]; // unreachable; keeps static analysers happy.
	}
}

if ( ! function_exists( 'woi_pdf_amount_to_words' ) ) {
	/**
	 * Format a monetary amount as an "amount in words" string, currency-aware and
	 * including the subunit (fils/cents) — the standard layout for tax invoices,
	 * e.g. 1710.00 AED -> "UAE Dirhams One Thousand Seven Hundred Ten and Zero
	 * Fils only". The amount is rounded to the subunit first, so a fractional
	 * carry (9.999 -> 10.00) rolls correctly into the whole part.
	 *
	 * The currency-unit map (major name, minor name, decimal places) is filterable
	 * via `woi_pdf_amount_words_currency_units`; unknown currencies fall back to
	 * the ISO code as the major name and "Cents" with 2 decimals.
	 *
	 * @param float|string $amount   Monetary amount.
	 * @param string       $currency ISO 4217 code (e.g. AED). Optional.
	 * @return string
	 */
	function woi_pdf_amount_to_words( $amount, $currency = '' ) {
		$amount   = (float) $amount;
		$currency = strtoupper( (string) $currency );

		$units = array(
			// code => array( major plural, minor plural, decimal places )
			'AED' => array( 'UAE Dirhams', 'Fils', 2 ),
			'USD' => array( 'US Dollars', 'Cents', 2 ),
			'EUR' => array( 'Euros', 'Cents', 2 ),
			'GBP' => array( 'Pounds Sterling', 'Pence', 2 ),
			'SAR' => array( 'Saudi Riyals', 'Halalas', 2 ),
			'QAR' => array( 'Qatari Riyals', 'Dirhams', 2 ),
			'KWD' => array( 'Kuwaiti Dinars', 'Fils', 3 ),
			'BHD' => array( 'Bahraini Dinars', 'Fils', 3 ),
			'OMR' => array( 'Omani Rials', 'Baisa', 3 ),
			'INR' => array( 'Indian Rupees', 'Paise', 2 ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$units = apply_filters( 'woi_pdf_amount_words_currency_units', $units, $currency );
		}

		if ( isset( $units[ $currency ] ) ) {
			list( $major, $minor, $decimals ) = $units[ $currency ];
		} else {
			$major    = ( '' !== $currency ) ? $currency : '';
			$minor    = 'Cents';
			$decimals = 2;
		}

		// Round to the subunit in integer space so a carry rolls into the whole.
		$factor   = (int) pow( 10, (int) $decimals );
		$subtotal = (int) round( $amount * $factor );
		$whole    = intdiv( $subtotal, $factor );
		$frac     = $subtotal % $factor;

		// ucwords with a hyphen delimiter so "twenty-one" -> "Twenty-One".
		$whole_words = ucwords( woi_pdf_integer_to_words( $whole ), " \t\r\n\f\v-" );

		$result = trim( ( '' !== $major ? $major . ' ' : '' ) . $whole_words );
		if ( $decimals > 0 ) {
			$frac_words = ucwords( woi_pdf_integer_to_words( $frac ), " \t\r\n\f\v-" );
			$result    .= ' and ' . $frac_words . ' ' . $minor;
		}
		$result .= ' only';

		return $result;
	}
}

if ( ! function_exists( 'woi_pdf_default_amount_in_words' ) ) {
	/**
	 * Default provider for the `woi_pdf_amount_in_words` filter: spells the order
	 * grand total when no value has been supplied. Registered at the default
	 * priority so a site/template filter that returns a non-empty string still
	 * wins (this only fills the blank).
	 *
	 * @param string $words    Current value (empty by default).
	 * @param object $document Order document; exposes ->order (a WC_Order).
	 * @return string
	 */
	function woi_pdf_default_amount_in_words( $words, $document ) {
		if ( '' !== (string) $words ) {
			return $words; // an explicit value already won — don't override it.
		}
		$order = ( is_object( $document ) && isset( $document->order ) ) ? $document->order : null;
		if ( ! $order || ! is_callable( array( $order, 'get_total' ) ) ) {
			return $words;
		}
		$currency = is_callable( array( $order, 'get_currency' ) ) ? $order->get_currency() : '';
		return woi_pdf_amount_to_words( $order->get_total(), $currency );
	}
}
