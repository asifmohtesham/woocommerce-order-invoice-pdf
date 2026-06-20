<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class VisualRenderPathTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_visual_active_requires_toggle_and_stored_template(): void {
        // Helper extracted as a pure function for isolated testing.
        $active = \WOI\PDF\Visual\visual_template_active( 'invoice', true, '<p>x</p>' );
        $this->assertTrue( $active );

        $this->assertFalse( \WOI\PDF\Visual\visual_template_active( 'invoice', false, '<p>x</p>' ) );
        $this->assertFalse( \WOI\PDF\Visual\visual_template_active( 'invoice', true, '' ) );
        $this->assertFalse( \WOI\PDF\Visual\visual_template_active( 'credit-note', true, '<p>x</p>' ) );
    }

    public function test_get_active_default_source_matches_legacy_get(): void {
        // Default (no active-source option set) must return the GrapesJS HTML,
        // identical to the legacy get() the render branch used before the swap.
        Functions\when( 'get_option' )->alias( function ( $name ) {
            if ( 'woi_pdf_visual_active_source' === $name ) { return false; } // unset → default
            if ( 'woi_pdf_visual_template_invoice' === $name ) { return '<p>legacy</p>'; }
            return false;
        } );
        $store = new \WOI\PDF\Visual\VisualTemplateStore();
        $this->assertSame( $store->get( 'invoice' ), $store->get_active( 'invoice' ) );
        $this->assertSame( '<p>legacy</p>', $store->get_active( 'invoice' ) );
    }
}
