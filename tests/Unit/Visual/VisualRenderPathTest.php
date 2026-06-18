<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
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
}
