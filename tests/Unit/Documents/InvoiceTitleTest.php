<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Documents\Invoice;

class InvoiceTitleTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( '__' )->returnArg( 1 );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'apply_filters_deprecated' )->alias(
            static function ( $tag, $args ) {
                return $args[0];
            }
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_invoice( string $document_title ): Invoice {
        $invoice = $this->getMockBuilder( Invoice::class )
            ->disableOriginalConstructor()
            ->onlyMethods( [ 'get_settings_text' ] )
            ->getMock();
        $invoice->slug = 'invoice';
        $invoice->method( 'get_settings_text' )->willReturn( $document_title );

        return $invoice;
    }

    public function test_get_title_uses_custom_document_title_when_set(): void {
        $invoice = $this->make_invoice( 'Tax Invoice' );
        $this->assertSame( 'Tax Invoice', $invoice->get_title() );
    }

    public function test_get_title_falls_back_to_default_when_blank(): void {
        $invoice = $this->make_invoice( '' );
        $this->assertSame( 'Invoice', $invoice->get_title() );
    }

    public function test_get_title_trims_custom_document_title(): void {
        $invoice = $this->make_invoice( '  Tax Invoice  ' );
        $this->assertSame( 'Tax Invoice', $invoice->get_title() );
    }
}
