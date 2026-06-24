<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * woi_pdf_visual_doc_options() resolves + whitelists the visual document's
 * presentation options. These guard the repeat-letterhead toggle.
 */
class VisualDocOptionsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_repeat_letterhead_defaults_off(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        $opts = woi_pdf_visual_doc_options( 'invoice' );
        $this->assertSame( 'off', $opts['repeat_letterhead'] );
    }

    public function test_repeat_letterhead_accepts_on(): void {
        Functions\when( 'get_option' )->justReturn( array( 'repeat_letterhead' => 'on' ) );
        $this->assertSame( 'on', woi_pdf_visual_doc_options( 'invoice' )['repeat_letterhead'] );
    }

    public function test_repeat_letterhead_rejects_junk(): void {
        Functions\when( 'get_option' )->justReturn( array( 'repeat_letterhead' => 'maybe' ) );
        $this->assertSame( 'off', woi_pdf_visual_doc_options( 'invoice' )['repeat_letterhead'] );
    }

    public function test_invalid_saved_value_for_existing_option_falls_back_to_default(): void {
        Functions\when( 'get_option' )->justReturn( array( 'borders' => 'garbage' ) );
        $this->assertSame( 'off', woi_pdf_visual_doc_options( 'invoice' )['borders'] );
    }

    public function test_row_color_defaults_empty(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        $this->assertSame( '', woi_pdf_visual_doc_options( 'invoice' )['row_color'] );
    }

    public function test_row_color_accepts_valid_hex(): void {
        Functions\when( 'get_option' )->justReturn( array( 'row_color' => '#1C1A17' ) );
        $this->assertSame( '#1C1A17', woi_pdf_visual_doc_options( 'invoice' )['row_color'] );
    }

    public function test_row_color_accepts_shorthand_hex(): void {
        Functions\when( 'get_option' )->justReturn( array( 'row_color' => '#abc' ) );
        $this->assertSame( '#abc', woi_pdf_visual_doc_options( 'invoice' )['row_color'] );
    }

    public function test_row_color_rejects_non_hex(): void {
        Functions\when( 'get_option' )->justReturn( array( 'row_color' => 'red; }body{x' ) );
        $this->assertSame( '', woi_pdf_visual_doc_options( 'invoice' )['row_color'] );
    }
}
