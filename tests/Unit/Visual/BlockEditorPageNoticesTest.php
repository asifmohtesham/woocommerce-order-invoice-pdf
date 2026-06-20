<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\BlockEditorPage;

class BlockEditorPageNoticesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function page(): BlockEditorPage { return new BlockEditorPage(); }

    public function test_suppress_noop_off_screen(): void {
        Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'edit-shop_order' ) );
        // remove_all_actions must NOT be called off-screen.
        Functions\expect( 'remove_all_actions' )->never();
        $this->page()->suppress_admin_notices();
        $this->assertFalse( $this->page()->is_block_editor_screen() );
    }

    public function test_suppress_runs_on_screen(): void {
        Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'woocommerce_page_woi-pdf-blocks' ) );
        Functions\expect( 'remove_all_actions' )->atLeast()->once();
        $this->page()->suppress_admin_notices();
        $this->assertTrue( $this->page()->is_block_editor_screen() );
    }
}
