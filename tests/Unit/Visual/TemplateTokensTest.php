<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\TemplateTokens;

class TemplateTokensTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // esc_html / esc_attr passthrough so assertions are readable.
        Functions\when( 'esc_html' )->returnArg( 1 );
        Functions\when( 'esc_attr' )->returnArg( 1 );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        // BilingualEngine reads options + dictionary file.
        Functions\when( 'get_option' )->justReturn( array(
            'shop_name_ar'    => 'متجر',
            'shop_address_ar' => 'دبي',
        ) );
        // Block-token helpers are real functions loaded by woi-pdf-functions.php;
        // stub them so scalar/merge tests are not pulled into WooCommerce internals.
        Functions\when( 'woi_pdf_templates_get_table_headers' )->justReturn( array() );
        Functions\when( 'woi_pdf_templates_get_table_body' )->justReturn( array() );
        Functions\when( 'woi_pdf_templates_get_totals' )->justReturn( array() );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stub_document() {
        return new class {
            public function get_type() { return 'invoice'; }
            public function get_shop_name() { return 'Acme Co'; }
            public function get_shop_address() { return '1 Main St'; }
            public function get_shop_vat_number() { return '100' ; }
            public function get_shop_phone_number() { return '+971' ; }
            public function get_shop_email_address() { return 'a@b.co'; }
            public function get_title() { return 'Tax Invoice'; }
            public function get_order_number() { return '4242'; }
            public function get_payment_method() { return 'Card'; }
            public function get_billing_address() { return 'John<br>Dubai'; }
            public function has_header_logo() { return true; }
            public function header_logo() { echo '<img src="x.png">'; }
            public function number( $t ) { echo 'INV-7'; }
            public function date( $t ) { echo '2026-06-18'; }
            public function get_setting( $k ) { return ''; }
        };
    }

    public function test_scalar_tokens_resolve_and_escape(): void {
        $tokens = new TemplateTokens();
        $map    = $tokens->map( $this->stub_document() );

        $this->assertSame( 'Acme Co', $map['{{shop_name}}'] );
        $this->assertSame( '1 Main St', $map['{{shop_address}}'] );
        $this->assertSame( 'Tax Invoice', $map['{{document_title}}'] );
        $this->assertSame( 'INV-7', $map['{{invoice_number}}'] );
        $this->assertSame( '2026-06-18', $map['{{invoice_date}}'] );
        $this->assertSame( '4242', $map['{{order_number}}'] );
        $this->assertSame( '<img src="x.png">', $map['{{logo}}'] );
        $this->assertSame( 'متجر', $map['{{shop_name_ar}}'] );
    }

    public function test_merge_replaces_known_and_strips_unknown_tokens(): void {
        $tokens = new TemplateTokens();
        $html   = '<h1>{{document_title}}</h1><p>{{shop_name}}</p><i>{{bogus}}</i>';
        $out    = $tokens->merge( $html, $this->stub_document() );

        $this->assertStringContainsString( '<h1>Tax Invoice</h1>', $out );
        $this->assertStringContainsString( '<p>Acme Co</p>', $out );
        $this->assertStringNotContainsString( '{{', $out );
        $this->assertStringContainsString( '<i></i>', $out );
    }

    public function test_block_tokens_render_tables(): void {
        Functions\when( 'woi_pdf_templates_get_table_headers' )->justReturn( array(
            array( 'class' => 'sku', 'title' => 'SKU' ),
        ) );
        Functions\when( 'woi_pdf_templates_get_table_body' )->justReturn( array(
            array( array( 'class' => 'sku', 'data' => 'A-1' ) ),
        ) );
        Functions\when( 'woi_pdf_templates_get_totals' )->justReturn( array(
            array( 'class' => 'total', 'label' => 'Total', 'value' => 'AED 10' ),
        ) );

        $tokens = new TemplateTokens();
        $map    = $tokens->map( $this->stub_document() );

        $this->assertStringContainsString( '<table class="order-details">', $map['{{line_items}}'] );
        $this->assertStringContainsString( 'A-1', $map['{{line_items}}'] );
        $this->assertStringContainsString( '<table class="totals-table">', $map['{{totals}}'] );
        $this->assertStringContainsString( 'AED 10', $map['{{totals}}'] );
    }
}
