<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * woi_pdf_dedupe_address_company_line() collapses a company line that is
 * repeated on consecutive lines (the common B2B case where the order's billing
 * name fields also contain the company name), while leaving other repeated
 * lines (e.g. city == state) intact.
 */
class AddressCompanyDedupeTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'wp_strip_all_tags' )->returnArg( 1 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_collapses_consecutive_duplicate_company_line(): void {
        $address = 'NESTO HYPERMARKET LLC<br/>NESTO HYPERMARKET LLC<br/>Burj Nahar Mall<br/>Dubai';
        $this->assertSame(
            'NESTO HYPERMARKET LLC<br/>Burj Nahar Mall<br/>Dubai',
            woi_pdf_dedupe_address_company_line( $address, 'NESTO HYPERMARKET LLC' )
        );
    }

    public function test_leaves_single_company_occurrence_untouched(): void {
        $address = 'John Doe<br/>NESTO HYPERMARKET LLC<br/>Burj Nahar Mall';
        $this->assertSame( $address, woi_pdf_dedupe_address_company_line( $address, 'NESTO HYPERMARKET LLC' ) );
    }

    public function test_preserves_non_company_consecutive_duplicates(): void {
        // city == state ("Dubai") must NOT be collapsed.
        $address = 'NESTO HYPERMARKET LLC<br/>Burj Nahar Mall<br/>Dubai<br/>Dubai';
        $this->assertSame( $address, woi_pdf_dedupe_address_company_line( $address, 'NESTO HYPERMARKET LLC' ) );
    }

    public function test_empty_company_returns_address_unchanged(): void {
        $address = 'John Doe<br/>Some Street<br/>Dubai';
        $this->assertSame( $address, woi_pdf_dedupe_address_company_line( $address, '' ) );
    }

    public function test_match_is_case_insensitive_and_trims_company(): void {
        $address = 'Acme LLC<br/>acme llc<br/>Street 1';
        $this->assertSame(
            'Acme LLC<br/>Street 1',
            woi_pdf_dedupe_address_company_line( $address, '  ACME LLC  ' )
        );
    }
}
