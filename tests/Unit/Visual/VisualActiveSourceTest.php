<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\VisualTemplateStore;

class VisualActiveSourceTest extends TestCase {

    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_option_names_are_namespaced(): void {
        $store = new VisualTemplateStore();
        $this->assertSame( 'woi_pdf_visual_blocks_invoice', $store->blocks_markup_option_name( 'invoice' ) );
        $this->assertSame( 'woi_pdf_visual_blocks_html_invoice', $store->blocks_html_option_name( 'invoice' ) );
        $this->assertSame( 'woi_pdf_visual_active_source', $store->active_source_option_name() );
    }

    public function test_active_source_defaults_to_grapesjs(): void {
        Functions\when( 'get_option' )->justReturn( false );
        $this->assertSame( 'grapesjs', ( new VisualTemplateStore() )->get_active_source() );
    }

    public function test_active_source_rejects_unknown_values(): void {
        $stored = array();
        Functions\when( 'update_option' )->alias( function ( $n, $v ) use ( &$stored ) { $stored[ $n ] = $v; return true; } );
        $store = new VisualTemplateStore();
        $store->set_active_source( 'nonsense' );
        $this->assertArrayNotHasKey( 'woi_pdf_visual_active_source', $stored );
        $store->set_active_source( 'blocks' );
        $this->assertSame( 'blocks', $stored['woi_pdf_visual_active_source'] );
    }

    public function test_save_blocks_stores_markup_raw_and_html_through_kses_unautoloaded(): void {
        $captured = array();
        Functions\when( 'wp_kses' )->returnArg( 1 ); // passthrough proves no pre-mangling
        Functions\when( 'update_option' )->alias(
            function ( $name, $value, $autoload ) use ( &$captured ) { $captured[ $name ] = array( $value, $autoload ); return true; }
        );
        $store = new VisualTemplateStore();
        $store->save_blocks( 'invoice', '<!-- wp:woi/shop-name -->{{shop_name}}<!-- /wp:woi/shop-name -->', '<p>{{shop_name}}</p>' );

        $this->assertArrayHasKey( 'woi_pdf_visual_blocks_invoice', $captured );
        $this->assertArrayHasKey( 'woi_pdf_visual_blocks_html_invoice', $captured );
        $this->assertStringContainsString( '<!-- wp:woi/shop-name -->', $captured['woi_pdf_visual_blocks_invoice'][0] );
        $this->assertStringContainsString( '{{shop_name}}', $captured['woi_pdf_visual_blocks_html_invoice'][0] );
        $this->assertFalse( $captured['woi_pdf_visual_blocks_invoice'][1] );  // unautoloaded
        $this->assertFalse( $captured['woi_pdf_visual_blocks_html_invoice'][1] );
    }

    public function test_get_active_returns_grapesjs_html_by_default(): void {
        Functions\when( 'get_option' )->alias( function ( $name ) {
            if ( 'woi_pdf_visual_active_source' === $name ) { return 'grapesjs'; }
            if ( 'woi_pdf_visual_template_invoice' === $name ) { return '<p>grapes</p>'; }
            return false;
        } );
        $this->assertSame( '<p>grapes</p>', ( new VisualTemplateStore() )->get_active( 'invoice' ) );
    }

    public function test_get_active_returns_blocks_html_when_blocks_active(): void {
        Functions\when( 'get_option' )->alias( function ( $name ) {
            if ( 'woi_pdf_visual_active_source' === $name ) { return 'blocks'; }
            if ( 'woi_pdf_visual_blocks_html_invoice' === $name ) { return '<p>blocks</p>'; }
            return false;
        } );
        $this->assertSame( '<p>blocks</p>', ( new VisualTemplateStore() )->get_active( 'invoice' ) );
    }
}
