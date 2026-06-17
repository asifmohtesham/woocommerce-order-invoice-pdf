<?php
namespace WOI\PDF\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Guards that the bilingual settings fields are declared in the invoice
 * document's settings-definition array.
 *
 * woi_pdf_test_get_invoice_setting_ids() is a thin test accessor defined in
 * woi-pdf-functions.php that directly calls woi_pdf_add_invoice_bilingual_settings()
 * so the bilingual fields are included without needing apply_filters.
 * Brain Monkey stubs the WP i18n function __() so the assertions work in a
 * WP-free test environment.
 */
class BilingualSettingsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_second_language_fields_declared(): void {
		$fields = woi_pdf_test_get_invoice_setting_ids();
		$this->assertContains( 'enable_second_language', $fields );
		$this->assertContains( 'second_language', $fields );
		$this->assertContains( 'second_language_labels', $fields );
	}
}
