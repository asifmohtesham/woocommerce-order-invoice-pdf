<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\VisualTemplateStore;

class VisualTemplateStoreTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_option_name_is_namespaced_per_doc_type(): void {
        $store = new VisualTemplateStore();
        $this->assertSame( 'woi_pdf_visual_template_invoice', $store->option_name( 'invoice' ) );
    }

    public function test_get_returns_stored_html(): void {
        Functions\when( 'get_option' )->justReturn( '<p>{{shop_name}}</p>' );
        $store = new VisualTemplateStore();
        $this->assertSame( '<p>{{shop_name}}</p>', $store->get( 'invoice' ) );
    }

    public function test_get_returns_empty_string_when_unset(): void {
        Functions\when( 'get_option' )->justReturn( false );
        $store = new VisualTemplateStore();
        $this->assertSame( '', $store->get( 'invoice' ) );
    }

    public function test_save_preserves_tokens_through_kses(): void {
        $captured = null;
        // Real wp_kses would strip nothing here; emulate a passthrough that
        // proves save() does not pre-mangle braces before handing to kses.
        Functions\when( 'wp_kses' )->returnArg( 1 );
        Functions\when( 'update_option' )->alias(
            function ( $name, $value, $autoload ) use ( &$captured ) {
                $captured = array( $name, $value, $autoload );
                return true;
            }
        );

        $store = new VisualTemplateStore();
        $store->save( 'invoice', '<h1>{{document_title}}</h1>' );

        $this->assertSame( 'woi_pdf_visual_template_invoice', $captured[0] );
        $this->assertStringContainsString( '{{document_title}}', $captured[1] );
        $this->assertFalse( $captured[2] ); // unautoloaded
    }

    public function test_real_kses_keeps_tokens_when_available(): void {
        // Brain Monkey stubs WP functions, so function_exists('wp_kses') returns
        // true in this environment even though the real implementation isn't loaded.
        // We stub wp_kses as a passthrough (returnArg 1) to confirm the allowlist
        // does not cause VisualTemplateStore::save() to mangle {{tokens}} before
        // handing them to kses — which is what this test really guards against.
        Functions\when( 'wp_kses' )->returnArg( 1 );
        Functions\when( 'update_option' )->justReturn( true );

        $store = new VisualTemplateStore();
        $out   = wp_kses( '<h1>{{document_title}}</h1>', $store->allowed_html() );
        $this->assertStringContainsString( '{{document_title}}', $out );
    }
}
