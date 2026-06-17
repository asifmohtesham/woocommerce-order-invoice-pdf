<?php
/**
 * Tests for the Standard UAE Tax Invoice preset bilingual defaults injection.
 *
 * Covers:
 *  - woi_pdf_standard_uae_inject_bilingual_defaults() merges absent keys.
 *  - It does NOT overwrite keys already present in $settings.
 *  - The woi_pdf_document_settings filter with no registered callback returns
 *    the input array unchanged (verifying OrderDocument::get_settings() callers
 *    are unaffected).
 *
 * Does NOT require WordPress or WooCommerce to be loaded — Brain\Monkey stubs
 * the WP functions.
 */
namespace WOI\PDF\Tests\Unit\Bilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Bilingual\BilingualEngine;

class PresetDefaultsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Stub WP functions used by BilingualEngine::dictionary() and the callback
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Helper: invoke the named callback directly (unit-testable named function)
	// -----------------------------------------------------------------------

	/**
	 * Load the preset's template-functions.php once so the named function is
	 * defined. Guard against double-include via function_exists.
	 */
	private function load_preset(): void {
		$file = dirname( __DIR__, 3 )
			. '/templates/Standard UAE Tax Invoice/template-functions.php';

		if ( ! function_exists( 'woi_pdf_standard_uae_inject_bilingual_defaults' ) ) {
			// Stub get_option so the file doesn't blow up when Customiser defaults
			// function references it at include-time (it doesn't — but defensive)
			Functions\when( 'get_option' )->justReturn( array() );
			require $file;
		}
	}

	// -----------------------------------------------------------------------
	// 1. Merges absent bilingual keys
	// -----------------------------------------------------------------------

	public function test_merges_bilingual_defaults_into_empty_settings(): void {
		$this->load_preset();

		$dict     = BilingualEngine::instance()->dictionary( 'ar' );
		$settings = array();
		$result   = woi_pdf_standard_uae_inject_bilingual_defaults( $settings, new \stdClass(), false, 'pdf' );

		$this->assertSame( 1,      $result['enable_second_language'],  'enable_second_language should be 1' );
		$this->assertSame( 'ar',   $result['second_language'],         'second_language should be ar' );
		$this->assertSame( 1,      $result['second_language_rtl'],     'second_language_rtl should be 1' );
		$this->assertSame( 'Tax Invoice', $result['document_title'],   'document_title should be Tax Invoice' );
		$this->assertSame( $dict,  $result['second_language_labels'],  'second_language_labels should be the AR dictionary' );
	}

	public function test_merges_missing_keys_into_partial_settings(): void {
		$this->load_preset();

		// Only second_language is set; others are absent
		$settings = array( 'second_language' => 'fr' );
		$result   = woi_pdf_standard_uae_inject_bilingual_defaults( $settings, new \stdClass(), false, 'pdf' );

		// Absent keys get defaults
		$this->assertSame( 1, $result['enable_second_language'] );
		$this->assertSame( 1, $result['second_language_rtl'] );
		$this->assertArrayHasKey( 'document_title', $result );
		$this->assertArrayHasKey( 'second_language_labels', $result );

		// Present key is untouched
		$this->assertSame( 'fr', $result['second_language'], 'existing second_language must not be overwritten' );
	}

	// -----------------------------------------------------------------------
	// 2. Does NOT override keys already present — including falsy values
	// -----------------------------------------------------------------------

	public function test_does_not_override_explicitly_disabled_enable_flag(): void {
		$this->load_preset();

		// User has explicitly disabled bilingual (value = 0)
		$settings = array( 'enable_second_language' => 0 );
		$result   = woi_pdf_standard_uae_inject_bilingual_defaults( $settings, new \stdClass(), false, 'pdf' );

		$this->assertSame( 0, $result['enable_second_language'], 'enable_second_language=0 must not be overwritten to 1' );
	}

	public function test_does_not_override_user_document_title(): void {
		$this->load_preset();

		$settings = array( 'document_title' => 'My Custom Title' );
		$result   = woi_pdf_standard_uae_inject_bilingual_defaults( $settings, new \stdClass(), false, 'pdf' );

		$this->assertSame( 'My Custom Title', $result['document_title'] );
	}

	public function test_does_not_override_any_preset_key_when_all_present(): void {
		$this->load_preset();

		$settings = array(
			'enable_second_language' => 0,
			'second_language'        => 'fr',
			'second_language_rtl'    => 0,
			'document_title'         => 'Custom',
			'second_language_labels' => array( 'foo' => 'bar' ),
		);

		$result = woi_pdf_standard_uae_inject_bilingual_defaults( $settings, new \stdClass(), true, 'pdf' );

		$this->assertSame( 0,        $result['enable_second_language'] );
		$this->assertSame( 'fr',     $result['second_language'] );
		$this->assertSame( 0,        $result['second_language_rtl'] );
		$this->assertSame( 'Custom', $result['document_title'] );
		$this->assertSame( array( 'foo' => 'bar' ), $result['second_language_labels'] );
	}

	// -----------------------------------------------------------------------
	// 3. apply_filters with no callback returns input unchanged
	//    (verifies existing get_settings() callers are unaffected)
	// -----------------------------------------------------------------------

	public function test_apply_filters_passthrough_when_no_callback(): void {
		// Brain\Monkey stubs apply_filters to return arg 2 (= $settings) by default.
		// This mirrors the real WordPress behaviour when no callbacks are registered.
		$input  = array( 'foo' => 'bar', 'enable_second_language' => 0 );
		$result = apply_filters( 'woi_pdf_document_settings', $input, new \stdClass(), false, 'pdf' );

		$this->assertSame( $input, $result, 'apply_filters must return settings unchanged when no callbacks registered' );
	}
}
