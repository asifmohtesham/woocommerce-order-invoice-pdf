<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Pure helpers behind the auto-displayed supplier/recipient TRN lines.
 * (The Main callbacks that echo these are thin wrappers around the actions.)
 */
class TrnDisplayTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // --- supplier TRN (shop VAT/TRN from general settings) ---

    public function test_supplier_trn_returns_general_vat_number(): void {
        Functions\when( 'get_option' )->justReturn( array( 'vat_number' => '100579920800003' ) );
        $this->assertSame( '100579920800003', woi_pdf_get_supplier_trn() );
    }

    public function test_supplier_trn_empty_when_unset(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        $this->assertSame( '', woi_pdf_get_supplier_trn() );
    }

    public function test_supplier_trn_is_trimmed(): void {
        Functions\when( 'get_option' )->justReturn( array( 'vat_number' => '  100579920800003  ' ) );
        $this->assertSame( '100579920800003', woi_pdf_get_supplier_trn() );
    }

    // --- formatter ---

    public function test_format_returns_empty_for_blank_value(): void {
        $this->assertSame( '', woi_pdf_format_trn_line( '', 'TRN:' ) );
    }

    public function test_format_builds_labelled_line(): void {
        Functions\when( 'esc_html' )->returnArg( 1 );
        $html = woi_pdf_format_trn_line( '100579920800003', 'TRN:' );
        $this->assertStringContainsString( 'TRN:', $html );
        $this->assertStringContainsString( '100579920800003', $html );
        $this->assertStringContainsString( 'trn-number', $html );
    }

    // --- customer-level TRN: dedicated profile field, then checkout-field fallback ---

    /** Stub get_user_meta with a per-key map. */
    private function stub_user_meta( array $map ): void {
        Functions\when( 'get_user_meta' )->alias(
            static function ( $id, $key ) use ( $map ) {
                return $map[ $key ] ?? '';
            }
        );
    }

    public function test_profile_trn_prefers_dedicated_field_ungated(): void {
        Functions\when( 'get_option' )->justReturn( array() ); // checkout-as-VAT off
        $this->stub_user_meta( array(
            '_woi_pdf_customer_trn' => '100888888800003',
            'woi_pdf_checkout_field' => 'IGNORED',
        ) );
        $this->assertSame( '100888888800003', woi_pdf_get_customer_profile_trn( 7 ) );
    }

    public function test_profile_trn_falls_back_to_checkout_field_when_treated_as_vat(): void {
        Functions\when( 'get_option' )->justReturn( array( 'checkout_field_as_vat_number' => 1 ) );
        $this->stub_user_meta( array(
            '_woi_pdf_customer_trn' => '',
            'woi_pdf_checkout_field' => '100777777700003',
        ) );
        $this->assertSame( '100777777700003', woi_pdf_get_customer_profile_trn( 7 ) );
    }

    public function test_profile_trn_ignores_checkout_field_when_not_treated_as_vat(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        $this->stub_user_meta( array(
            '_woi_pdf_customer_trn' => '',
            'woi_pdf_checkout_field' => '100777777700003',
        ) );
        $this->assertSame( '', woi_pdf_get_customer_profile_trn( 7 ) );
    }

    public function test_profile_trn_empty_for_guest(): void {
        Functions\when( 'get_option' )->justReturn( array( 'checkout_field_as_vat_number' => 1 ) );
        $this->assertSame( '', woi_pdf_get_customer_profile_trn( 0 ) );
    }

    // --- customer edit screen field registration ---

    public function test_adds_trn_field_under_customer_billing(): void {
        Functions\when( '__' )->returnArg( 1 );
        $fields = woi_pdf_add_customer_trn_field( array( 'billing' => array( 'fields' => array() ) ) );
        $this->assertArrayHasKey( '_woi_pdf_customer_trn', $fields['billing']['fields'] );
        $this->assertArrayHasKey( 'label', $fields['billing']['fields']['_woi_pdf_customer_trn'] );
    }

    public function test_adds_trn_field_when_billing_section_absent(): void {
        Functions\when( '__' )->returnArg( 1 );
        $fields = woi_pdf_add_customer_trn_field( array() );
        $this->assertArrayHasKey( '_woi_pdf_customer_trn', $fields['billing']['fields'] );
    }
}
