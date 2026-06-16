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

    // --- recipient fallback to the customer (user) profile ---

    public function test_profile_trn_returns_user_meta_when_treated_as_vat(): void {
        Functions\when( 'get_option' )->justReturn( array( 'checkout_field_as_vat_number' => 1 ) );
        Functions\when( 'get_user_meta' )->justReturn( '100888888800003' );
        $this->assertSame( '100888888800003', woi_pdf_get_customer_profile_trn( 7 ) );
    }

    public function test_profile_trn_empty_when_not_treated_as_vat(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_user_meta' )->justReturn( '100888888800003' );
        $this->assertSame( '', woi_pdf_get_customer_profile_trn( 7 ) );
    }

    public function test_profile_trn_empty_for_guest(): void {
        Functions\when( 'get_option' )->justReturn( array( 'checkout_field_as_vat_number' => 1 ) );
        $this->assertSame( '', woi_pdf_get_customer_profile_trn( 0 ) );
    }
}
