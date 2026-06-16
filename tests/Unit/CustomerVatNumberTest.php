<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Documents\Invoice;

/**
 * Covers the document-level wrapper that surfaces the customer VAT/TRN number
 * on the PDF. The underlying value resolution lives in
 * woi_pdf_get_order_customer_vat_number() (already covered by integration with
 * WooCommerce); these tests lock the order guard the wrapper adds so documents
 * without a valid order never fatal.
 */
class CustomerVatNumberTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_document(): Invoice {
        return ( new \ReflectionClass( Invoice::class ) )->newInstanceWithoutConstructor();
    }

    public function test_returns_empty_string_when_order_missing(): void {
        $document = $this->make_document();
        $document->order = null;

        $this->assertSame( '', $document->get_customer_vat_number() );
    }

    public function test_returns_empty_string_when_order_is_not_a_wc_order(): void {
        $document = $this->make_document();
        $document->order = new \stdClass();

        $this->assertSame( '', $document->get_customer_vat_number() );
    }
}
